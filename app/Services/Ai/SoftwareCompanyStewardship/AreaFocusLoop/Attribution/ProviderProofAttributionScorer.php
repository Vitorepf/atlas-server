<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Attribution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderAndModelAttributionPerLaneContract;

/**
 * Scores whether provider proof attribution covers the changed surface produced
 * by a stewardship cycle, evaluated purely over the canonical workcell lanes.
 *
 * Pure: every returned field is computed from the method inputs over the five
 * imported {@see ProviderAndModelAttributionPerLaneContract::LANE_ROLES}.
 */
final class ProviderProofAttributionScorer
{
    private const SCHEMA_VERSION = 'atlas.stewardship.provider_proof_attribution.v1';

    /**
     * @param  array<string, array{provider?: ?string, model?: ?string}>  $lanes
     * @return array{schema_version: string, verdict: string, attributed: bool, attribution_score: float, attributed_lane_count: int, total_lane_count: int, unattributed_lanes: list<string>, reason: string}
     */
    public function score(string $owner, int $providerCalls, bool $hasChangedFiles, array $lanes): array
    {
        $owner = strtolower(trim($owner));

        $unattributed = [];
        $attributedCount = 0;
        foreach (ProviderAndModelAttributionPerLaneContract::LANE_ROLES as $role) {
            if ($this->laneIsAttributed($lanes[$role] ?? null)) {
                $attributedCount++;

                continue;
            }

            $unattributed[] = $role;
        }

        $totalLanes = count(ProviderAndModelAttributionPerLaneContract::LANE_ROLES);
        sort($unattributed);
        $score = round($attributedCount / $totalLanes, 2);

        [$verdict, $attributed, $score, $reason] = $this->classify(
            $owner,
            $providerCalls,
            $hasChangedFiles,
            $attributedCount,
            $score,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'attributed' => $attributed,
            'attribution_score' => $score,
            'attributed_lane_count' => $attributedCount,
            'total_lane_count' => $totalLanes,
            'unattributed_lanes' => $unattributed,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{0: string, 1: bool, 2: float, 3: string}
     */
    private function classify(string $owner, int $providerCalls, bool $hasChangedFiles, int $attributedCount, float $score): array
    {
        if ($owner === 'forge' && $hasChangedFiles && $providerCalls <= 0) {
            return ['unattributed_forge_diff', false, 0.0, 'forge changed files without any provider call as proof'];
        }

        if (! $hasChangedFiles) {
            return ['no_diff_no_proof_required', true, $score, 'no changed files so provider proof is not required'];
        }

        if ($attributedCount === 0) {
            return ['no_lane_attribution', false, $score, 'changed files exist but no lane carries provider attribution'];
        }

        if ($owner === 'forge' && $score < 0.6) {
            return ['weak_attribution_coverage', false, $score, 'forge attribution coverage is below the proof threshold'];
        }

        if ($score < 1.0 && $score >= 0.6) {
            return ['partial_attribution', true, $score, 'attribution covers most lanes but not all'];
        }

        if ($score === 1.0) {
            return ['fully_attributed', true, $score, 'every canonical lane carries provider attribution'];
        }

        return ['under_attribution_review', false, $score, 'attribution coverage is incomplete and needs review'];
    }

    /**
     * @param  array{provider?: ?string, model?: ?string}|null  $lane
     */
    private function laneIsAttributed(?array $lane): bool
    {
        $provider = $lane['provider'] ?? null;

        return is_string($provider) && trim($provider) !== '';
    }
}
