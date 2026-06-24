<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * BayesianKillScaleDecider — decide KILL / SCALE / KEEP / HOLD with FEW data (pillar 3).
 *
 * The folk gate is binary: HOLD until spend ≥ 3×Max CPA. It burns money — 200 clicks with no sale against
 * a 1% minimum-viable CVR is already statistically a loser, and 2 sales in 30 clicks is already a winner.
 * This models the click→sale CVR as a Beta posterior (prior = the breakeven economics' assumed CVR as
 * pseudo-counts) and returns P(true CPA > Max CPA) — i.e. P(loss). KILL when loss is near-certain even at
 * zero sales; SCALE when profit is near-certain; KEEP when profitable but not yet confident; HOLD only when
 * the data genuinely cannot decide. Reuses CampaignEconomicsCalculator (max_cpa, prior CVR) and
 * FewShotConfidenceMeter (sufficiency). Deterministic, provider-free, anti-Goodhart (measures P(real profit),
 * never a proxy).
 */
class BayesianKillScaleDecider
{
    /** Prior strength in pseudo-clicks — how much the assumed CVR anchors before real data accrues. */
    private const PRIOR_CLICKS = 30;

    private const KILL_P_LOSS = 0.85;

    private const SCALE_P_PROFIT = 0.70;

    public function __construct(
        private readonly CampaignEconomicsCalculator $economics = new CampaignEconomicsCalculator,
        private readonly FewShotConfidenceMeter $confidence = new FewShotConfidenceMeter,
    ) {}

    /**
     * @param  array{clicks?:int,conversions?:int,spend?:float}  $stats
     * @param  array<string,mixed>  $economicsInputs  CampaignEconomicsCalculator::compute() inputs (payout, etc.)
     * @return array{decision:string,p_loss:float,p_profit:float,min_viable_cvr:float,posterior_cvr:float,observed_cpa:?float,max_cpa:float,confidence:array<string,mixed>,reason:string}
     */
    public function decide(array $stats, array $economicsInputs): array
    {
        $econ = $this->economics->compute($economicsInputs);
        $maxCpa = (float) $econ['max_cpa'];
        $priorCvr = (float) ($econ['assumptions']['cvr'] ?? 0.01);

        $clicks = max(0, (int) ($stats['clicks'] ?? 0));
        $conversions = max(0, (int) ($stats['conversions'] ?? 0));
        $spend = max(0.0, (float) ($stats['spend'] ?? 0));

        $cpc = $clicks > 0 ? $spend / $clicks : (float) ($econ['breakeven_cpc'] ?? 0);
        // The CVR below which CPA exceeds Max CPA at the observed CPC → the campaign loses money.
        $minViableCvr = ($cpc > 0 && $maxCpa > 0) ? min(1.0, $cpc / $maxCpa) : $priorCvr;

        // Beta posterior on CVR: prior CVR as PRIOR_CLICKS pseudo-trials.
        $a0 = $priorCvr * self::PRIOR_CLICKS;
        $b0 = (1 - $priorCvr) * self::PRIOR_CLICKS;
        $a = $a0 + $conversions;
        $b = $b0 + max(0, $clicks - $conversions);
        $mean = $a / ($a + $b);
        $sd = sqrt(($a * $b) / (($a + $b) ** 2 * ($a + $b + 1)));

        // P(true CVR < min viable) = P(loss). Normal approximation to the Beta posterior (honest: approx).
        $pLoss = $sd > 0 ? $this->normalCdf(($minViableCvr - $mean) / $sd) : ($mean < $minViableCvr ? 1.0 : 0.0);
        $pProfit = 1 - $pLoss;

        $observedCpa = $conversions > 0 ? round($spend / $conversions, 2) : null;
        $conf = $this->confidence->assess($conversions, $clicks, $minViableCvr);

        // Guard: never KILL on the prior alone — require real evidence (a sale, or at least ~one
        // expected-sale's worth of clicks with zero sales). Below that, 0 sales is noise, not a verdict.
        $enoughToKill = $conversions >= 1 || $clicks >= (int) ceil(1 / max($minViableCvr, 0.005));

        $decision = match (true) {
            $pLoss >= self::KILL_P_LOSS && $enoughToKill => 'KILL',
            $conversions >= 1 && $observedCpa !== null && $observedCpa <= $maxCpa && $pProfit >= self::SCALE_P_PROFIT => 'SCALE',
            $conversions >= 1 && $observedCpa !== null && $observedCpa <= $maxCpa => 'KEEP',
            default => 'HOLD',
        };

        $reason = match ($decision) {
            'KILL' => sprintf('P(prejuízo)=%d%% ≥ %d%%: a CVR real está quase certamente abaixo da mínima viável (%.2f%%) ao CPC atual — matar agora, sem queimar até 3×Max CPA.', (int) round($pLoss * 100), (int) (self::KILL_P_LOSS * 100), $minViableCvr * 100),
            'SCALE' => sprintf('CPA real $%.2f ≤ Max CPA $%.2f e P(lucro)=%d%%: escalar (+10-20%%/passo, sem resetar o learning).', (float) $observedCpa, $maxCpa, (int) round($pProfit * 100)),
            'KEEP' => sprintf('Lucrativo (CPA $%.2f ≤ Max CPA $%.2f) mas confiança ainda baixa pra escalar — manter e acumular dado.', (float) $observedCpa, $maxCpa),
            default => sprintf('Indeciso: P(prejuízo)=%d%%, faltam ~%d cliques pra separar da CVR mínima viável — HOLD (0 venda ainda não é veredito, mas perto).', (int) round($pLoss * 100), (int) $conf['n_needed']),
        };

        return [
            'decision' => $decision,
            'p_loss' => round($pLoss, 3),
            'p_profit' => round($pProfit, 3),
            'min_viable_cvr' => round($minViableCvr, 4),
            'posterior_cvr' => round($mean, 4),
            'observed_cpa' => $observedCpa,
            'max_cpa' => $maxCpa,
            'confidence' => $conf,
            'reason' => $reason,
        ];
    }

    /** Standard normal CDF via the Abramowitz-Stegun erf approximation. */
    private function normalCdf(float $x): float
    {
        return 0.5 * (1 + $this->erf($x / sqrt(2)));
    }

    private function erf(float $x): float
    {
        $t = 1 / (1 + 0.3275911 * abs($x));
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return $x >= 0 ? $y : -$y;
    }
}
