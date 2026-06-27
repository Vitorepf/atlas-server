<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COVERAGE MATRIX — meta-improvement primitive beyond the catalog. Hand-curated map of which
 * portfolio path each existing brain organ serves. Lets the brain itself answer "which path has
 * the fewest organs?" so its next slice can route to the under-built dimension instead of the
 * most familiar one (the bias loop that turns rotation into ritual).
 *
 * Pure const. Pétreo: réu would reassign organs to falsely-balance the matrix.
 */
final class AtlasBrainCoverageMatrix
{
    public const SCHEMA = 'atlas.brain.coverage_matrix.v1';

    /** @var array<string, list<class-string>> */
    private const PATH_TO_ORGANS = [
        'comprehension-deepening' => [
            AtlasBrainPerceptionBundle::class,
            AtlasBrainScopeFlagAuditor::class,
            AtlasBrainPathSignalAggregator::class,
            AtlasBrainReflectionProvenanceChain::class,
        ],
        'adversarial-critique' => [
            AtlasBrainPlanAdviserRedTeam::class,
            AtlasBrainCriticalConsensusGate::class,
        ],
        'compounding' => [
            AtlasBrainPathYieldMomentum::class,
            AtlasBrainCompoundingVelocity::class,
        ],
        'simulation-twin' => [
            AtlasBrainNextCycleProjector::class,
            AtlasBrainHypotheticalTailAppender::class,
            AtlasBrainProjectionCalibrationScore::class,
        ],
        'metrics-optimization' => [
            AtlasBrainPathDiversityScore::class,
            AtlasBrainPathPriorityRank::class,
        ],
        'pattern-design' => [
            AtlasBrainPathOscillationDetector::class,
            AtlasBrainSchemaContractRegistry::class,
        ],
        'frontier-harvest' => [
            AtlasBrainFrontierMethodCatalog::class,
            AtlasBrainHintFrequencyDriftAlarm::class,
            AtlasBrainCrossScopePatternXref::class,
            AtlasBrainGateFalsePositiveEstimator::class,
        ],
    ];

    /**
     * @return array<string, int>
     */
    public function coverageByPath(): array
    {
        $out = [];
        foreach (self::PATH_TO_ORGANS as $path => $organs) {
            $out[$path] = count($organs);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function underbuiltPaths(int $minOrgans): array
    {
        $out = [];
        foreach (self::PATH_TO_ORGANS as $path => $organs) {
            if (count($organs) < $minOrgans) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<class-string>>
     */
    public function matrix(): array
    {
        return self::PATH_TO_ORGANS;
    }
}
