<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * Pure, deterministic unit-economics for a paid-traffic affiliate launch.
 * Same input → same output. No LLM, no state, no I/O. This is the math that
 * governs every kill/scale decision and the bid targets fed to Google.
 */
class CampaignEconomicsCalculator
{
    use CampaignMathHelper;

    /** Smart Bidding data-volume thresholds (per 30 days), research-directional. */
    public const CONV_FOR_TROAS = 15;

    public const CONV_FOR_TCPA = 30;

    public const LEARNING_EXIT_CONV = 50;

    /**
     * @param  array<string,mixed>  $inputs  payout, refund_rate, target_margin, cvr, daily_budget
     * @return array<string,mixed>
     */
    public function compute(array $inputs): array
    {
        $payout = max(0.0, (float) ($inputs['payout'] ?? 0));
        $refund = $this->clamp01((float) ($inputs['refund_rate'] ?? 0.10));
        $margin = $this->clamp01((float) ($inputs['target_margin'] ?? 0.30));
        $cvr = $this->clamp01((float) ($inputs['cvr'] ?? 0.01)); // click → sale (ASSUMPTION until live data)

        $netPayout = $payout * (1 - $refund);          // revenue you keep after refunds
        $maxCpa = $netPayout * (1 - $margin);          // most you can pay per sale at target margin
        $breakevenCpa = $netPayout;                    // zero-margin ceiling

        $targetCpc = $cvr > 0 ? $maxCpa * $cvr : 0.0;          // bid ceiling at target margin
        $breakevenCpc = $cvr > 0 ? $breakevenCpa * $cvr : 0.0; // bid ceiling at zero margin

        $targetRoas = $maxCpa > 0 ? $payout / $maxCpa : 0.0;       // conv_value(gross) / cost at target
        $breakevenRoas = $breakevenCpa > 0 ? $payout / $breakevenCpa : 0.0;

        $killSpendNoSale = round(1.5 * $maxCpa, 2);    // kill a keyword/group at this spend with 0 sales
        $testDecisionSpend = round(3.0 * $maxCpa, 2);  // by here you can judge the first test
        $clicksPerSale = $cvr > 0 ? (int) ceil(1 / $cvr) : null;          // expected clicks for 1 sale
        $clicksToDecision = $cvr > 0 ? (int) ceil(3 / $cvr) : null;       // ~3 sales worth = a read

        $dailyBudget = isset($inputs['daily_budget']) && (float) $inputs['daily_budget'] > 0
            ? round((float) $inputs['daily_budget'], 2)
            : round(3.0 * $maxCpa, 2);                 // default: reach a decision in days, not weeks

        return [
            'currency' => (string) ($inputs['currency'] ?? 'USD'),
            'payout' => round($payout, 2),
            'net_payout' => round($netPayout, 2),
            'max_cpa' => round($maxCpa, 2),
            'breakeven_cpa' => round($breakevenCpa, 2),
            'target_cpc' => round($targetCpc, 2),
            'breakeven_cpc' => round($breakevenCpc, 2),
            'target_roas' => round($targetRoas, 2),
            'breakeven_roas' => round($breakevenRoas, 2),
            'kill_spend_no_sale' => $killSpendNoSale,
            'test_decision_spend' => $testDecisionSpend,
            'clicks_per_sale' => $clicksPerSale,
            'clicks_to_decision' => $clicksToDecision,
            'daily_budget' => $dailyBudget,
            'conv_for_troas' => self::CONV_FOR_TROAS,
            'conv_for_tcpa' => self::CONV_FOR_TCPA,
            'learning_exit_conv' => self::LEARNING_EXIT_CONV,
            'assumptions' => [
                'refund_rate' => $refund,
                'target_margin' => $margin,
                'cvr' => $cvr,
                'cvr_note' => 'CVR (clique→venda) é a ÚNICA premissa não-determinística — confirmar com dado real assim que houver.',
            ],
        ];
    }
}
