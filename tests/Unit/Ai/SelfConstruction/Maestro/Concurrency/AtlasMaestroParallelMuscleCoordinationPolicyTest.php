<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Concurrency;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroParallelMuscleCoordinationPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroParallelMuscleCoordinationPolicyTest extends TestCase
{
    private AtlasMaestroParallelMuscleCoordinationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasMaestroParallelMuscleCoordinationPolicy;
    }

    private function clean(array $overrides = []): array
    {
        return array_merge([
            'queue_depth'               => 10,
            'servable_count'            => 4,
            'active_leases'             => 2,
            'conflict_free_scope_ratio' => 1.0,
            'lock_contention'           => 0.0,
            'give_back_rate'            => 0.0,
            'worker_quality_scores'     => [9.0, 9.5],
        ], $overrides);
    }

    // ── AC1: required output keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->policy->recommend($this->clean());

        foreach (['schema', 'recommended_parallelism', 'throttle_reasons', 'add_worker_reasons'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasMaestroParallelMuscleCoordinationPolicy::SCHEMA, $result['schema']);
    }

    public function test_recommended_parallelism_is_positive_integer(): void
    {
        $result = $this->policy->recommend($this->clean());
        $this->assertIsInt($result['recommended_parallelism']);
        $this->assertGreaterThanOrEqual(1, $result['recommended_parallelism']);
    }

    public function test_clean_signals_produce_no_throttle_reasons(): void
    {
        $result = $this->policy->recommend($this->clean());
        $this->assertSame([], $result['throttle_reasons']);
    }

    // ── AC2: throttle reduces parallelism even with many claimable tasks ─────

    public function test_high_giveback_rate_triggers_throttle(): void
    {
        $result = $this->policy->recommend($this->clean([
            'give_back_rate'  => 0.50,  // > 0.30 threshold
            'servable_count'  => 8,
        ]));

        $this->assertContains('high_giveback_churn', $result['throttle_reasons']);
        $this->assertLessThan(8, $result['recommended_parallelism'], 'Must throttle even with 8 servable tasks');
    }

    public function test_high_lock_contention_triggers_throttle(): void
    {
        $result = $this->policy->recommend($this->clean([
            'lock_contention' => 0.50,  // > 0.25 threshold
            'servable_count'  => 8,
        ]));

        $this->assertContains('commit_lock_contention', $result['throttle_reasons']);
        $this->assertLessThan(8, $result['recommended_parallelism']);
    }

    public function test_low_worker_quality_triggers_throttle(): void
    {
        $result = $this->policy->recommend($this->clean([
            'worker_quality_scores' => [3.0, 4.0],  // avg 3.5 < 6.0
            'servable_count'        => 8,
        ]));

        $this->assertContains('quality_risk', $result['throttle_reasons']);
        $this->assertLessThan(8, $result['recommended_parallelism']);
    }

    public function test_low_conflict_free_ratio_with_multiple_active_leases_triggers_throttle(): void
    {
        $result = $this->policy->recommend($this->clean([
            'conflict_free_scope_ratio' => 0.30,  // < 0.50
            'active_leases'             => 3,
            'servable_count'            => 8,
        ]));

        $this->assertContains('low_disjoint_scope_ratio', $result['throttle_reasons']);
        $this->assertLessThan(8, $result['recommended_parallelism']);
    }

    public function test_multiple_throttle_conditions_reduce_further(): void
    {
        $single = $this->policy->recommend($this->clean([
            'give_back_rate' => 0.50,
            'servable_count' => 8,
        ]))['recommended_parallelism'];

        $both = $this->policy->recommend($this->clean([
            'give_back_rate'  => 0.50,
            'lock_contention' => 0.50,
            'servable_count'  => 8,
        ]))['recommended_parallelism'];

        $this->assertLessThanOrEqual($single, $both, 'More throttle conditions must not increase parallelism');
    }

    public function test_recommended_parallelism_never_drops_below_one(): void
    {
        $result = $this->policy->recommend([
            'queue_depth'               => 1,
            'servable_count'            => 1,
            'active_leases'             => 5,
            'conflict_free_scope_ratio' => 0.10,
            'lock_contention'           => 0.90,
            'give_back_rate'            => 0.90,
            'worker_quality_scores'     => [1.0, 1.0],
        ]);

        $this->assertGreaterThanOrEqual(1, $result['recommended_parallelism']);
    }

    // ── Add-worker signals ───────────────────────────────────────────────────

    public function test_high_queue_depth_produces_add_worker_reason(): void
    {
        $result = $this->policy->recommend($this->clean([
            'queue_depth'    => 20,
            'servable_count' => 6,
            'active_leases'  => 2,
        ]));

        $this->assertContains('high_queue_depth', $result['add_worker_reasons']);
    }

    public function test_high_conflict_free_ratio_produces_add_worker_reason(): void
    {
        $result = $this->policy->recommend($this->clean(['conflict_free_scope_ratio' => 0.90]));
        $this->assertContains('disjoint_scopes_available', $result['add_worker_reasons']);
    }

    public function test_high_quality_scores_produce_add_worker_reason(): void
    {
        $result = $this->policy->recommend($this->clean(['worker_quality_scores' => [9.0, 9.5, 8.5]]));
        $this->assertContains('workers_demonstrate_quality', $result['add_worker_reasons']);
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function test_empty_signals_returns_valid_result(): void
    {
        $result = $this->policy->recommend([]);

        $this->assertGreaterThanOrEqual(1, $result['recommended_parallelism']);
        $this->assertIsArray($result['throttle_reasons']);
        $this->assertIsArray($result['add_worker_reasons']);
    }

    public function test_servable_count_caps_recommendation(): void
    {
        $result = $this->policy->recommend($this->clean([
            'servable_count' => 2,
            'queue_depth'    => 100,
        ]));

        $this->assertLessThanOrEqual(2, $result['recommended_parallelism']);
    }
}
