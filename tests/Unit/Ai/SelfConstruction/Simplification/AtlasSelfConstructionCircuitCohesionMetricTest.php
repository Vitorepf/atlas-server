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
}
