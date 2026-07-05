<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomeLearningMatrix;
use Tests\TestCase;

/**
 * Proves the Wilson score lower bound gates family-level supply_families
 * promotion and worker routing so small-sample flukes don't trigger routing.
 *
 * BEFORE Wilson:
 *   - 4/5 family (raw 0.80) → supply_families
 *   - 2/2 worker (raw 1.0) → preferred, ranked above 70/100 worker (raw 0.70)
 *
 * AFTER Wilson:
 *   - 4/5 family (Wilson LB ~0.36 < 0.80) → NOT supply_families
 *   - 400/500 family (Wilson LB ~0.76 < 0.80 actually needs larger) → same
 *   - 180/200 family (90%, Wilson LB ~0.855 > 0.80) → supply_families
 *   - 2/2 worker → NOT preferred (Wilson LB ~0.34 < 0.70)
 *   - 10/10 worker → preferred (Wilson LB ~0.72 > 0.70)
 */
final class AtlasExternalBrainMuscleOutcomeLearningMatrixWilsonTest extends TestCase
{
    private AtlasExternalBrainMuscleOutcomeLearningMatrix $matrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matrix = new AtlasExternalBrainMuscleOutcomeLearningMatrix;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'task_family' => 'refactor',
            'worker_id'   => 'w1',
            'model_tier'  => 'standard',
            'outcome'     => 'success',
        ], $overrides);
    }

    public function test_4_of_5_family_not_promoted_to_supply_under_wilson(): void
    {
        // 4/5 = 80% raw rate. Wilson LB ~0.36 < 0.80 → NOT supply_families.
        $r = $this->matrix->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
            'success_threshold' => 0.80,
        ]);

        $this->assertNotContains('refactor', $r['supply_families'],
            '4/5 family must NOT be promoted to supply_families under Wilson gate');
        $this->assertSame('high_success_insufficient_sample', $r['family_matrix']['refactor']['signal']);
    }

    public function test_180_of_200_family_promoted_to_supply(): void
    {
        // 180/200 = 90%. Wilson LB ~0.855 > 0.80 → supply_families.
        $rows = array_merge(
            array_fill(0, 180, $this->row(['task_family' => 'big_family', 'outcome' => 'success'])),
            array_fill(0, 20, $this->row(['task_family' => 'big_family', 'outcome' => 'failure'])),
        );
        $r = $this->matrix->analyze([
            'outcome_rows' => $rows,
            'success_threshold' => 0.80,
        ]);

        $this->assertContains('big_family', $r['supply_families'],
            '180/200 family must be promoted to supply_families');
        $this->assertSame('high_success', $r['family_matrix']['big_family']['signal']);
    }

    public function test_2_of_2_worker_not_preferred_under_wilson(): void
    {
        // 2/2 = 100%. Wilson LB ~0.34 < 0.70 → NOT preferred.
        $r = $this->matrix->analyze([
            'outcome_rows' => [
                $this->row(['worker_id' => 'thin_worker', 'outcome' => 'success']),
                $this->row(['worker_id' => 'thin_worker', 'outcome' => 'success']),
            ],
            'routing_prefer_floor' => 0.70,
            'routing_min_rows'     => 2,
        ]);

        $pref = $r['routing_recommendations']['refactor']['preferred_workers'] ?? [];
        $ids  = array_column($pref, 'worker_id');
        $this->assertNotContains('thin_worker', $ids,
            '2/2 worker must NOT be in preferred_workers under Wilson gate');
    }

    public function test_10_of_10_worker_preferred_under_wilson(): void
    {
        // 10/10 = 100%. Wilson LB ~0.72 > 0.70 → preferred.
        $r = $this->matrix->analyze([
            'outcome_rows' => array_fill(0, 10, $this->row(['worker_id' => 'proven', 'outcome' => 'success'])),
            'routing_prefer_floor' => 0.70,
            'routing_min_rows'     => 2,
        ]);

        $pref = $r['routing_recommendations']['refactor']['preferred_workers'] ?? [];
        $ids  = array_column($pref, 'worker_id');
        $this->assertContains('proven', $ids,
            '10/10 worker must be in preferred_workers under Wilson gate');
    }

    public function test_wilson_routing_is_deterministic(): void
    {
        $rows = array_merge(
            array_fill(0, 180, $this->row(['task_family' => 'fam', 'outcome' => 'success'])),
            array_fill(0, 20, $this->row(['task_family' => 'fam', 'outcome' => 'failure'])),
        );

        $a = $this->matrix->analyze(['outcome_rows' => $rows]);
        $b = $this->matrix->analyze(['outcome_rows' => $rows]);

        $this->assertSame($a['supply_families'], $b['supply_families']);
        $this->assertSame(
            $a['family_matrix']['fam']['signal'],
            $b['family_matrix']['fam']['signal'],
        );
    }
}
