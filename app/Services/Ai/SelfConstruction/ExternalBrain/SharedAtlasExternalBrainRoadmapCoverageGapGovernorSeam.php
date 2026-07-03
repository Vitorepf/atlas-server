<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * The single seam for "does this acceptance criteria list carry a runnable
 * test signal?" — extracted from the identical const + scan loop duplicated in
 * AtlasExternalBrainRoadmapCoverageGapGovernor and
 * AtlasExternalBrainPoisonRepairConversionTracker (measured by the delta
 * prover shingle metric; origin: refchain-d831966cf4). Every future marker
 * lands HERE once instead of drifting per copy.
 */
final class SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam
{
    private const RUNNABLE_ACCEPTANCE_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    /** @param list<string> $acceptanceCriteria */
    public static function hasRunnableAcceptance(array $acceptanceCriteria): bool
    {
        foreach ($acceptanceCriteria as $criterion) {
            $lower = strtolower((string) $criterion);
            foreach (self::RUNNABLE_ACCEPTANCE_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }
}
