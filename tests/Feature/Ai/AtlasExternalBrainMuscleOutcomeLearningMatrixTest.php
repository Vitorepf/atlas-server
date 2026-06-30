<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomeLearningMatrix;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMuscleOutcomeLearningMatrixTest extends TestCase
{
    private AtlasExternalBrainMuscleOutcomeLearningMatrix $matrix;

    protected function setUp(): void
    {
        $this->matrix = new AtlasExternalBrainMuscleOutcomeLearningMatrix;
    }

    private function analyze(array $rows, array $extra = []): array
    {
        return $this->matrix->analyze(array_merge(['outcome_rows' => $rows], $extra));
    }

    private function row(string $family, string $worker, string $tier, string $outcome): array
    {
        return ['task_family' => $family, 'worker_id' => $worker, 'model_tier' => $tier, 'outcome' => $outcome];
    }

    // ── AC2: mixed outcomes → deterministic family/worker/tier + routing ──────

    public function test_family_matrix_groups_by_family(): void
    {
        $r = $this->analyze([
            $this->row('infra', 'w1', 'fast', 'success'),
            $this->row('infra', 'w1', 'fast', 'give_back'),
            $this->row('brain', 'w2', 'slow', 'success'),
        ]);

        $this->assertArrayHasKey('infra', $r['family_matrix']);
        $this->assertArrayHasKey('brain', $r['family_matrix']);
    }

    public function test_worker_matrix_groups_by_worker(): void
    {
        $r = $this->analyze([
            $this->row('infra', 'worker-A', 'fast', 'success'),
            $this->row('infra', 'worker-A', 'fast', 'give_back'),
            $this->row('brain', 'worker-B', 'slow', 'success'),
        ]);

        $this->assertArrayHasKey('worker-A', $r['worker_matrix']);
        $this->assertArrayHasKey('worker-B', $r['worker_matrix']);
    }

    public function test_tier_matrix_groups_by_tier(): void
    {
        $r = $this->analyze([
            $this->row('infra', 'w1', 'fast', 'success'),
            $this->row('brain', 'w2', 'slow', 'success'),
        ]);

        $this->assertArrayHasKey('fast', $r['tier_matrix']);
        $this->assertArrayHasKey('slow', $r['tier_matrix']);
    }

    public function test_routing_recommendations_present_for_each_family(): void
    {
        $r = $this->analyze([
            $this->row('infra', 'w1', 'fast', 'success'),
            $this->row('infra', 'w1', 'fast', 'success'),
        ]);

        $this->assertArrayHasKey('infra', $r['routing_recommendations']);
    }

    public function test_mixed_outcomes_produce_correct_matrix_summary(): void
    {
        $rows = [
            $this->row('infra', 'w1', 'fast', 'success'),
            $this->row('infra', 'w1', 'fast', 'give_back'),
            $this->row('infra', 'w1', 'fast', 'poison'),
            $this->row('infra', 'w1', 'fast', 'quarantine'),
            $this->row('infra', 'w1', 'fast', 'duplicate'),
            $this->row('infra', 'w1', 'fast', 'weak_green'),
        ];
        $r = $this->analyze($rows);

        $this->assertSame(6, $r['matrix_summary']['total_rows']);
        $this->assertSame(1, $r['matrix_summary']['families']);
    }

    // ── AC3: poison/quarantine/duplicate rate over threshold → respec ─────────

    public function test_poison_rate_over_threshold_puts_family_in_respec(): void
    {
        // 2/4 = 0.50 poison rate > default 0.20
        $r = $this->analyze([
            $this->row('toxic', 'w1', 'fast', 'poison'),
            $this->row('toxic', 'w1', 'fast', 'poison'),
            $this->row('toxic', 'w1', 'fast', 'success'),
            $this->row('toxic', 'w1', 'fast', 'success'),
        ]);

        $this->assertContains('toxic', $r['respec_families']);
        $this->assertNotContains('toxic', $r['supply_families']);
    }

    public function test_quarantine_rate_over_threshold_puts_family_in_respec(): void
    {
        // 2/4 = 0.50 > default 0.30
        $r = $this->analyze([
            $this->row('risky', 'w1', 'fast', 'quarantine'),
            $this->row('risky', 'w1', 'fast', 'quarantine'),
            $this->row('risky', 'w1', 'fast', 'success'),
            $this->row('risky', 'w1', 'fast', 'success'),
        ]);

        $this->assertContains('risky', $r['respec_families']);
    }

    public function test_duplicate_rate_over_threshold_puts_family_in_respec(): void
    {
        // 2/4 = 0.50 > default 0.30
        $r = $this->analyze([
            $this->row('dup-family', 'w1', 'fast', 'duplicate'),
            $this->row('dup-family', 'w1', 'fast', 'duplicate'),
            $this->row('dup-family', 'w1', 'fast', 'success'),
            $this->row('dup-family', 'w1', 'fast', 'success'),
        ]);

        $this->assertContains('dup-family', $r['respec_families']);
    }

    public function test_clean_family_not_in_respec(): void
    {
        $r = $this->analyze([
            $this->row('good', 'w1', 'fast', 'success'),
            $this->row('good', 'w1', 'fast', 'success'),
            $this->row('good', 'w1', 'fast', 'success'),
        ]);

        $this->assertNotContains('good', $r['respec_families']);
    }

    public function test_high_success_family_lands_in_supply_families(): void
    {
        // 4/4 = 1.00 >= 0.80 threshold
        $r = $this->analyze([
            $this->row('hot', 'w1', 'fast', 'success'),
            $this->row('hot', 'w1', 'fast', 'success'),
            $this->row('hot', 'w1', 'fast', 'success'),
            $this->row('hot', 'w1', 'fast', 'success'),
        ]);

        $this->assertContains('hot', $r['supply_families']);
        $this->assertNotContains('hot', $r['respec_families']);
    }

    // ── AC4: worker/tier require enough samples, prefer proven value ──────────

    public function test_worker_with_enough_samples_is_marked_reliable(): void
    {
        $r = $this->analyze([
            $this->row('infra', 'solid-worker', 'fast', 'success'),
            $this->row('infra', 'solid-worker', 'fast', 'success'),
            $this->row('infra', 'solid-worker', 'fast', 'success'),
        ]);

        $this->assertTrue($r['worker_matrix']['solid-worker']['reliable']);
    }

    public function test_worker_with_low_success_rate_is_not_reliable(): void
    {
        $r = $this->analyze([
            $this->row('infra', 'flaky', 'fast', 'success'),
            $this->row('infra', 'flaky', 'fast', 'give_back'),
            $this->row('infra', 'flaky', 'fast', 'failure'),
            $this->row('infra', 'flaky', 'fast', 'failure'),
        ]);

        $this->assertFalse($r['worker_matrix']['flaky']['reliable']);
    }

    public function test_repeat_give_back_worker_surfaces_as_reliability_signal(): void
    {
        // min_give_back_flag default = 2
        $r = $this->analyze([
            $this->row('infra', 'churner', 'fast', 'give_back'),
            $this->row('infra', 'churner', 'fast', 'give_back'),
            $this->row('infra', 'churner', 'fast', 'success'),
        ]);

        $workerIds = array_column($r['worker_reliability_signals'], 'worker_id');
        $this->assertContains('churner', $workerIds);
    }

    public function test_routing_prefers_worker_with_high_cross_family_success(): void
    {
        // worker-good: 3 successes in infra family; worker-bad: 2 failures
        $r = $this->analyze([
            $this->row('infra', 'worker-good', 'fast', 'success'),
            $this->row('infra', 'worker-good', 'fast', 'success'),
            $this->row('infra', 'worker-bad',  'fast', 'failure'),
            $this->row('infra', 'worker-bad',  'fast', 'failure'),
        ]);

        $preferred = array_column($r['routing_recommendations']['infra']['preferred_workers'], 'worker_id');
        $avoided   = array_column($r['routing_recommendations']['infra']['avoid_workers'],    'worker_id');

        $this->assertContains('worker-good', $preferred);
        $this->assertContains('worker-bad',  $avoided);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $rows = [
            $this->row('infra', 'w1', 'fast', 'success'),
            $this->row('infra', 'w1', 'fast', 'poison'),
            $this->row('brain', 'w2', 'slow', 'give_back'),
        ];

        $this->assertSame(json_encode($this->analyze($rows)), json_encode($this->analyze($rows)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->analyze([]);

        $this->assertSame(AtlasExternalBrainMuscleOutcomeLearningMatrix::SCHEMA, $r['schema_version']);
    }
}
