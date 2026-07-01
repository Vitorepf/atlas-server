<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitCohesionMetric;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitCohesionMetricTest extends TestCase
{
    public function test_improved_consolidation_across_all_dimensions(): void
    {
        $result = (new AtlasSelfConstructionCircuitCohesionMetric)->measure([
            'before' => ['cohesion' => 0.4, 'coupling' => 0.8, 'duplicated_contracts' => 3, 'entrypoint_count' => 5, 'proof_density' => 0.2],
            'after' => ['cohesion' => 0.7, 'coupling' => 0.3, 'duplicated_contracts' => 1, 'entrypoint_count' => 2, 'proof_density' => 0.6],
        ]);

        self::assertTrue($result['consolidation_improves_circuit']);
        self::assertGreaterThan(0, $result['delta']);
        self::assertSame([], $result['regressions']);
        self::assertNotEmpty($result['drivers']);
    }

    public function test_neutral_consolidation_with_no_dimension_changes_is_not_improvement(): void
    {
        $metrics = ['cohesion' => 0.5, 'coupling' => 0.5, 'duplicated_contracts' => 2, 'entrypoint_count' => 3, 'proof_density' => 0.4];

        $result = (new AtlasSelfConstructionCircuitCohesionMetric)->measure([
            'before' => $metrics,
            'after' => $metrics,
        ]);

        self::assertFalse($result['consolidation_improves_circuit']);
        self::assertSame(0.0, $result['delta']);
        self::assertSame([], $result['drivers']);
        self::assertSame([], $result['regressions']);
    }

    public function test_fewer_lines_but_worse_coupling_entrypoints_or_proof_density_is_not_improvement(): void
    {
        $result = (new AtlasSelfConstructionCircuitCohesionMetric)->measure([
            'before' => ['cohesion' => 0.5, 'coupling' => 0.3, 'duplicated_contracts' => 1, 'entrypoint_count' => 2, 'proof_density' => 0.6],
            'after' => ['cohesion' => 0.5, 'coupling' => 0.7, 'duplicated_contracts' => 1, 'entrypoint_count' => 5, 'proof_density' => 0.1],
        ]);

        self::assertFalse($result['consolidation_improves_circuit']);
        self::assertContains('coupling', $result['regressions']);
        self::assertContains('entrypoint_count', $result['regressions']);
        self::assertContains('proof_density', $result['regressions']);
    }

    public function test_output_shape_has_required_fields(): void
    {
        $result = (new AtlasSelfConstructionCircuitCohesionMetric)->measure([
            'before' => ['cohesion' => 0.5, 'coupling' => 0.5, 'duplicated_contracts' => 1, 'entrypoint_count' => 1, 'proof_density' => 0.5],
            'after' => ['cohesion' => 0.6, 'coupling' => 0.4, 'duplicated_contracts' => 0, 'entrypoint_count' => 1, 'proof_density' => 0.5],
        ]);

        foreach (['before_score', 'after_score', 'delta', 'drivers', 'regressions', 'consolidation_improves_circuit'] as $key) {
            self::assertArrayHasKey($key, $result);
        }
    }

    public function test_bloated_low_cohesion_high_coupling_circuit_is_flagged_for_collapse(): void
    {
        $result = (new AtlasSelfConstructionCircuitCohesionMetric)->evaluate([
            'cohesion' => 0.2,
            'coupling' => 0.8,
            'line_count' => 3000,
        ]);

        self::assertSame(AtlasSelfConstructionCircuitCohesionMetric::RECOMMENDATION_SPLIT_OR_COLLAPSE, $result['recommendation']);
        self::assertLessThan(0.0, $result['score']);
    }

    public function test_small_cohesive_circuit_is_kept(): void
    {
        $result = (new AtlasSelfConstructionCircuitCohesionMetric)->evaluate([
            'cohesion' => 0.9,
            'coupling' => 0.1,
            'line_count' => 80,
        ]);

        self::assertSame(AtlasSelfConstructionCircuitCohesionMetric::RECOMMENDATION_KEEP, $result['recommendation']);
        self::assertGreaterThan(0.0, $result['score']);
    }

    public function test_duplicate_helper_count_and_removable_lines_increase_deletion_upside_without_hiding_risk(): void
    {
        $withoutDuplicates = (new AtlasSelfConstructionCircuitCohesionMetric)->evaluate([
            'cohesion' => 0.2,
            'coupling' => 0.8,
        ]);
        $withDuplicates = (new AtlasSelfConstructionCircuitCohesionMetric)->evaluate([
            'cohesion' => 0.2,
            'coupling' => 0.8,
            'duplicate_helper_count' => 5,
            'removable_lines' => 400,
        ]);

        self::assertGreaterThan($withoutDuplicates['deletion_upside'], $withDuplicates['deletion_upside']);
        // A large deletion upside never masks the underlying risk — recommendation and score stay
        // exactly what the cohesion/coupling shape dictates.
        self::assertSame($withoutDuplicates['recommendation'], $withDuplicates['recommendation']);
        self::assertSame(AtlasSelfConstructionCircuitCohesionMetric::RECOMMENDATION_SPLIT_OR_COLLAPSE, $withDuplicates['recommendation']);
        self::assertSame($withoutDuplicates['score'], $withDuplicates['score']);
    }
}
