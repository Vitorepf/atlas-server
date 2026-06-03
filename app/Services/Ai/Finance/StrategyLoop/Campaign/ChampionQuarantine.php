<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * A round winner never becomes a proposal directly. It is promoted into quarantine
 * and must survive campaign-level penalties and independent checks first.
 */
final class ChampionQuarantine
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $roundVerdict = $input['round_verdict'] ?? [];
        if (! (bool) ($roundVerdict['certified'] ?? false)) {
            return [
                'promoted' => false,
                'certified' => false,
                'status' => 'rejected_honest_null',
                'reasons' => $roundVerdict['reasons'] ?? ['round_gate_failed'],
            ];
        }

        $reasons = [];
        $campaignVerdict = $input['campaign_verdict'] ?? [];
        if (! (bool) ($campaignVerdict['certified'] ?? false)) {
            $reasons[] = 'campaign_penalty_failed';
        }

        $holdoutStatus = (string) ($input['holdout_status'] ?? StrategyCampaignStore::HOLDOUT_EXHAUSTED);
        if (! in_array($holdoutStatus, [StrategyCampaignStore::HOLDOUT_FRESH, StrategyCampaignStore::HOLDOUT_RESERVED], true)) {
            $reasons[] = 'fresh_holdout_required('.$holdoutStatus.')';
        }

        $freshHoldout = $input['fresh_holdout'] ?? [];
        if (
            ! is_array($freshHoldout)
            || ! is_numeric($freshHoldout['ann_sharpe'] ?? null)
            || (float) $freshHoldout['ann_sharpe'] < 0.5
            || (int) ($freshHoldout['n_trades'] ?? 0) < 10
        ) {
            $reasons[] = 'fresh_holdout_failed';
        }

        $costStress = $input['cost_stress'] ?? [];
        if (! (bool) ($costStress['passed'] ?? false)) {
            $reasons[] = 'cost_stress_failed';
        }

        $neighborhood = $input['neighborhood'] ?? [];
        if (! (bool) ($neighborhood['passed'] ?? false)) {
            $reasons[] = 'neighborhood_robustness_failed';
        }

        $secondEngine = $input['second_engine'] ?? [];
        if (! (bool) ($secondEngine['passed'] ?? false)) {
            $reasons[] = 'second_engine_required';
        }

        $crossCampaign = $input['cross_campaign'] ?? [];
        if (! (bool) ($crossCampaign['passed'] ?? false)) {
            $reasons[] = 'cross_campaign_rediscovery_required';
        }

        return [
            'promoted' => true,
            'certified' => $reasons === [],
            'status' => $reasons === [] ? 'certified_for_review' : 'promoted_pending_quarantine',
            'reasons' => $reasons === [] ? ['certified_for_review'] : $reasons,
            'campaign_verdict' => $campaignVerdict,
            'holdout_status' => $holdoutStatus,
            'fresh_holdout' => $freshHoldout,
            'cost_stress' => $costStress,
            'neighborhood' => $neighborhood,
            'second_engine' => $secondEngine,
            'cross_campaign' => $crossCampaign,
        ];
    }
}
