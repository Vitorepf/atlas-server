<?php

namespace App\Services\Ai\Rivals\Core;

/**
 * Read-only gate for world-trial frontiers.
 *
 * This reports whether a campaign is ready for the separate Rivals claim
 * adjudicator; it never issues, mutates or renews a claim.
 */
final class WorldTrialReadiness
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function evaluate(array $manifest): array
    {
        $campaigns = array_values(array_filter(
            (array) ($manifest['campaigns'] ?? []),
            'is_array',
        ));
        $requiredExposure = max(1, (int) ($manifest['required_exposure'] ?? 1));
        $requiredOutcomeDays = max(1, (int) ($manifest['required_outcome_days'] ?? 30));
        $requiredDimensions = array_values(array_unique(array_map(
            'strval',
            (array) ($manifest['required_critical_dimensions'] ?? []),
        )));
        $blockers = [];

        if (count($campaigns) < 3) {
            $blockers[] = 'campaign_count_below_three:'.count($campaigns).'<3';
        }

        foreach ($campaigns as $campaign) {
            $id = (string) ($campaign['id'] ?? 'unknown');
            if ((int) ($campaign['distinct_units'] ?? 0) < $requiredExposure) {
                $blockers[] = "exposure_below_required:{$id}";
            }
            if ((float) ($campaign['power'] ?? 0.0) < 0.90) {
                $blockers[] = "power_below_90_percent:{$id}";
            }
            if ((int) ($campaign['outcome_days'] ?? 0) < $requiredOutcomeDays) {
                $blockers[] = "outcome_window_incomplete:{$id}";
            }
            if (($campaign['synthetic'] ?? false) === true) {
                $blockers[] = "synthetic_campaign:{$id}";
            }
            if (($campaign['contamination_free'] ?? false) !== true) {
                $blockers[] = "contamination_detected:{$id}";
            }
            if (($campaign['itt_complete'] ?? false) !== true) {
                $blockers[] = "itt_incomplete:{$id}";
            }
            foreach ($requiredDimensions as $dimension) {
                if (($campaign['critical_dimensions'][$dimension] ?? false) !== true) {
                    $blockers[] = "critical_dimension_missing_or_regressed:{$id}:{$dimension}";
                }
            }
        }

        $blockers = array_values(array_unique($blockers));
        $implementedTrial = $campaigns !== []
            && (string) ($manifest['mode'] ?? '') !== ''
            && $requiredExposure > 0;
        $activeCampaign = $campaigns !== [];
        $elapsedOutcome = $campaigns !== [] && ! (bool) preg_grep(
            '/^outcome_window_incomplete:/',
            $blockers,
        );
        $eligibleClaim = $implementedTrial && $activeCampaign && $elapsedOutcome && $blockers === [];

        return [
            'status' => $eligibleClaim ? 'ready_for_separate_claim_adjudication' : 'blocked',
            'mode' => $manifest['mode'] ?? null,
            'campaign_count' => count($campaigns),
            'implemented_trial' => $implementedTrial,
            'active_campaign' => $activeCampaign,
            'elapsed_outcome' => $elapsedOutcome,
            'eligible_claim' => $eligibleClaim,
            'claim_issued' => false,
            'blockers' => $blockers,
        ];
    }
}
