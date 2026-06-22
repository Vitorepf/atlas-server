<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * Pure, deterministic bid-strategy plan by data volume. You CANNOT start a cold
 * test on tCPA/tROAS (no conversion data to learn from), so the plan phases from
 * data-gathering → value/target bidding as conversions accumulate. The `advanced` block
 * adds the levers that separate a pro account: Value-Based Bidding, Seasonality Adjustments
 * and Data Exclusions (critical for affiliates when the postback breaks).
 */
class BidStrategyDecider
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<string,mixed>  $economics  output of CampaignEconomicsCalculator
     * @return array<string,mixed>
     */
    public function plan(array $economics): array
    {
        $maxCpa = (float) ($economics['max_cpa'] ?? 0);
        $targetRoas = (float) ($economics['target_roas'] ?? 0);
        $convForTroas = (int) ($economics['conv_for_troas'] ?? CampaignEconomicsCalculator::CONV_FOR_TROAS);
        $convForTcpa = (int) ($economics['conv_for_tcpa'] ?? CampaignEconomicsCalculator::CONV_FOR_TCPA);

        return [
            'first_test_strategy' => 'maximize_conversions',
            'advanced' => [
                'value_based_bidding' => $this->playbook->valueBasedBidding(),
                'seasonality_adjustments' => $this->playbook->seasonalityAdjustments(),
                'data_exclusions' => $this->playbook->dataExclusions(),
            ],
            'phases' => [
                [
                    'phase' => 1,
                    'when' => 'cold start — 0 conversões',
                    'strategy' => 'Maximize Conversions (sem target) ou Enhanced CPC',
                    'reason' => 'Sem dados de conversão não há como tCPA/tROAS aprender — primeiro junta dados.',
                    'target' => null,
                ],
                [
                    'phase' => 2,
                    'when' => "≥{$convForTroas} conversões / 30 dias",
                    'strategy' => 'Target ROAS',
                    'reason' => 'tROAS exige menos volume que tCPA — primeiro alvo viável.',
                    'target' => ['type' => 'roas', 'value' => $targetRoas],
                ],
                [
                    'phase' => 3,
                    'when' => "≥{$convForTcpa} conversões / 30 dias (estável)",
                    'strategy' => 'Target CPA',
                    'reason' => 'Com volume suficiente, fixa o custo por venda no teto seguro.',
                    'target' => ['type' => 'cpa', 'value' => round($maxCpa, 2)],
                ],
            ],
            'guardrails' => [
                'Não mudar o target (tCPA/tROAS) em mais de ~20% por vez — reseta o aprendizado.',
                'Não mudar o budget em mais de ~20% por passo (7–14 dias) — reseta o aprendizado.',
                'Não pausar/despausar a campanha durante o aprendizado.',
                'Fase de aprendizado sai por volta de '.CampaignEconomicsCalculator::LEARNING_EXIT_CONV.' conversões.',
            ],
        ];
    }
}
