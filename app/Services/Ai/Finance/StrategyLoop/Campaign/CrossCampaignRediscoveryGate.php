<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

final class CrossCampaignRediscoveryGate
{
    /**
     * @param  array<string,mixed>  $signature
     * @return array<string,mixed>
     */
    public function evaluate(CandidateRediscoveryLedger $ledger, array $signature, string $currentCampaignId, int $requiredIndependentCampaigns = 1): array
    {
        $confirmations = $ledger->confirmations($signature, $currentCampaignId);
        $count = (int) ($confirmations['count'] ?? 0);
        $passed = $count >= $requiredIndependentCampaigns;

        return [
            'passed' => $passed,
            'required_independent_campaigns' => $requiredIndependentCampaigns,
            'confirmed_independent_campaigns' => $count,
            'signature' => $signature,
            'confirmations' => $confirmations['independent_campaigns'] ?? [],
            'reason' => $passed ? 'cross_campaign_rediscovery_passed' : 'cross_campaign_rediscovery_required',
        ];
    }
}
