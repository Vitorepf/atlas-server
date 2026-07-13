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
    /** @var list<string> */
    private const OUTCOME_WINDOWS = ['0h', '24h', '7d', '30d', '90d', '150d'];

    /** @var array<string,int> */
    private const OUTCOME_DAYS = ['0h' => 0, '24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '150d' => 150];

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
        $mode = (string) ($manifest['mode'] ?? '');
        $frontierDefaults = match ($mode) {
            'dev' => ['exposure' => 150, 'outcome_days' => 30],
            'forge' => ['exposure' => 30, 'outcome_days' => 30],
            'autonomos' => ['exposure' => 1, 'outcome_days' => 150],
            default => ['exposure' => 1, 'outcome_days' => 30],
        };
        // The plan's frontier is a floor, not caller-controlled metadata.
        $requiredExposure = max($frontierDefaults['exposure'], (int) ($manifest['required_exposure'] ?? 1));
        $requiredOutcomeDays = max($frontierDefaults['outcome_days'], (int) ($manifest['required_outcome_days'] ?? 30));
        $requiredDimensions = array_values(array_unique(array_map(
            'strval',
            (array) ($manifest['required_critical_dimensions'] ?? []),
        )));
        $blockers = [];

        if (count($campaigns) < 3) {
            $blockers[] = 'campaign_count_below_three:'.count($campaigns).'<3';
        }
        if ($requiredDimensions === []) {
            $blockers[] = 'critical_dimensions_policy_missing';
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
            $outcomeWindows = is_array($campaign['outcome_windows'] ?? null)
                ? $campaign['outcome_windows']
                : [];
            foreach ($this->requiredOutcomeWindows($requiredOutcomeDays) as $window) {
                $observation = is_array($outcomeWindows[$window] ?? null)
                    ? $outcomeWindows[$window]
                    : [];
                if (($observation['state'] ?? null) !== 'observed') {
                    $blockers[] = "outcome_observation_missing:{$id}:{$window}";
                    continue;
                }
                if (($observation['source'] ?? null) !== 'atlas_outcome_store') {
                    $blockers[] = "outcome_observation_source_missing:{$id}:{$window}";
                }
                if (($observation['synthetic'] ?? true) !== false) {
                    $blockers[] = "outcome_observation_synthetic:{$id}:{$window}";
                }
                if (preg_match('/^[a-f0-9]{64}$/', (string) ($observation['observation_hash'] ?? '')) !== 1) {
                    $blockers[] = "outcome_observation_hash_missing:{$id}:{$window}";
                }
            }
            if (($campaign['synthetic'] ?? false) === true) {
                $blockers[] = "synthetic_campaign:{$id}";
            }
            if (($campaign['synthetic'] ?? true) !== false) {
                $blockers[] = "synthetic_status_unproven:{$id}";
            }
            if (($campaign['execution_source'] ?? null) !== 'native_runtime') {
                $blockers[] = "native_execution_source_missing:{$id}";
            }
            if (($campaign['real_execution'] ?? false) !== true) {
                $blockers[] = "real_execution_unproven:{$id}";
            }
            foreach (['preregistration_hash', 'native_receipt_hash', 'evidence_pack_hash', 'outcome_receipt_hash'] as $hashField) {
                if (preg_match('/^[a-f0-9]{64}$/', (string) ($campaign[$hashField] ?? '')) !== 1) {
                    $blockers[] = "{$hashField}_missing:{$id}";
                }
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
            '/^(outcome_window_incomplete|outcome_observation_)/',
            $blockers,
        );
        $eligibleClaim = $implementedTrial && $activeCampaign && $elapsedOutcome && $blockers === [];

        return [
            'status' => $eligibleClaim ? 'ready_for_separate_claim_adjudication' : 'blocked',
            'mode' => $mode !== '' ? $mode : null,
            'frontier_defaults' => $frontierDefaults,
            'required_exposure' => $requiredExposure,
            'required_outcome_days' => $requiredOutcomeDays,
            'required_outcome_windows' => $this->requiredOutcomeWindows($requiredOutcomeDays),
            'campaign_count' => count($campaigns),
            'implemented_trial' => $implementedTrial,
            'active_campaign' => $activeCampaign,
            'elapsed_outcome' => $elapsedOutcome,
            'eligible_claim' => $eligibleClaim,
            'claim_issued' => false,
            'blockers' => $blockers,
        ];
    }

    /** @return list<string> */
    private function requiredOutcomeWindows(int $requiredOutcomeDays): array
    {
        return array_values(array_filter(
            self::OUTCOME_WINDOWS,
            static fn (string $window): bool => self::OUTCOME_DAYS[$window] <= $requiredOutcomeDays,
        ));
    }
}
