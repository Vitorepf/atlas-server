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

    // ── AC3: poison pressure throttles even when queue is deep ───────────────

    public function test_high_poison_pressure_triggers_throttle(): void
    {
        $result = $this->policy->recommend($this->clean([
            'poison_pressure' => 0.50, // > 0.20 threshold
            'servable_count'  => 8,
        ]));

        $this->assertContains('poison_pressure_elevated', $result['throttle_reasons']);
        $this->assertLessThan(8, $result['recommended_parallelism']);
    }

    // ── AC4: target_change deterministic relative to active_leases, spawn_allowed gate ──

    public function test_target_change_is_difference_between_recommended_and_active_leases(): void
    {
        $result = $this->policy->recommend($this->clean(['active_leases' => 2, 'servable_count' => 4]));

        $this->assertSame($result['recommended_parallelism'] - 2, $result['target_change']);
    }

    public function test_spawn_allowed_true_when_target_change_positive(): void
    {
        $result = $this->policy->recommend($this->clean(['active_leases' => 1, 'servable_count' => 6]));

        $this->assertGreaterThan(0, $result['target_change']);
        $this->assertTrue($result['spawn_allowed']);
    }

    public function test_spawn_allowed_false_when_target_change_not_positive(): void
    {
        $result = $this->policy->recommend($this->clean([
            'give_back_rate' => 0.90,
            'active_leases'  => 5,
            'servable_count' => 5,
        ]));

        $this->assertLessThanOrEqual(0, $result['target_change']);
        $this->assertFalse($result['spawn_allowed']);
    }

    public function test_recommended_parallelism_never_recommends_zero(): void
    {
        $result = $this->policy->recommend($this->clean([
            'give_back_rate'        => 1.0,
            'lock_contention'       => 1.0,
            'poison_pressure'       => 1.0,
            'worker_quality_scores' => [0.0],
            'servable_count'        => 1,
        ]));

        $this->assertGreaterThanOrEqual(1, $result['recommended_parallelism']);
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

    // ── coordinateConcurrentWork() — AC: disjoint files, lease pressure, worker fit ──

    private function candidate(string $workerId, array $allowedFiles, float $fitScore = 1.0): array
    {
        return ['worker_id' => $workerId, 'allowed_files' => $allowedFiles, 'worker_fit_score' => $fitScore];
    }

    public function test_coordination_output_has_required_keys(): void
    {
        $result = $this->policy->coordinateConcurrentWork(['candidates' => []]);

        foreach (['coordination_decision', 'conflict_files', 'recommended_worker_count', 'backoff_or_route'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_disjoint_concurrent_work_admits_all_candidates(): void
    {
        $result = $this->policy->coordinateConcurrentWork(['candidates' => [
            $this->candidate('w1', ['app/A.php']),
            $this->candidate('w2', ['app/B.php']),
            $this->candidate('w3', ['app/C.php']),
        ]]);

        $this->assertSame('admit_all', $result['coordination_decision']);
        $this->assertSame(3, $result['recommended_worker_count']);
        $this->assertSame([], $result['conflict_files']);
        $this->assertSame('proceed_concurrently', $result['backoff_or_route']);
    }

    public function test_file_overlap_excludes_the_conflicting_worker(): void
    {
        $result = $this->policy->coordinateConcurrentWork(['candidates' => [
            $this->candidate('w1', ['app/Shared.php']),
            $this->candidate('w2', ['app/Shared.php', 'app/Other.php']),
        ]]);

        $this->assertSame('admit_partial', $result['coordination_decision']);
        $this->assertContains('app/Shared.php', $result['conflict_files']);
        $this->assertSame(1, $result['recommended_worker_count']);
        $this->assertContains('w1', $result['admitted_worker_ids']);
        $this->assertNotContains('w2', $result['admitted_worker_ids']);
        $this->assertSame('reroute_conflicting_worker_to_disjoint_task', $result['backoff_or_route']);
    }

    public function test_lease_pressure_blocks_all_candidates(): void
    {
        $result = $this->policy->coordinateConcurrentWork([
            'candidates' => [
                $this->candidate('w1', ['app/A.php']),
                $this->candidate('w2', ['app/B.php']),
            ],
            'active_leases' => 8,
            'lease_ceiling' => 8,
        ]);

        $this->assertSame('block', $result['coordination_decision']);
        $this->assertSame(0, $result['recommended_worker_count']);
        $this->assertSame('retry_after_lease_pressure_clears', $result['backoff_or_route']);
        $this->assertSame([], $result['admitted_worker_ids']);
    }

    public function test_poor_worker_fit_excludes_that_candidate(): void
    {
        $result = $this->policy->coordinateConcurrentWork(['candidates' => [
            $this->candidate('good_fit', ['app/A.php'], 0.90),
            $this->candidate('bad_fit', ['app/B.php'], 0.20),
        ]]);

        $this->assertSame('admit_partial', $result['coordination_decision']);
        $this->assertContains('good_fit', $result['admitted_worker_ids']);
        $this->assertNotContains('bad_fit', $result['admitted_worker_ids']);
        $this->assertSame(1, $result['recommended_worker_count']);
        $this->assertSame('reassign_poor_fit_worker_to_better_matched_task', $result['backoff_or_route']);
    }

    public function test_safe_reduced_parallelism_when_some_candidates_excluded(): void
    {
        $result = $this->policy->coordinateConcurrentWork([
            'candidates' => [
                $this->candidate('w1', ['app/A.php'], 0.9),
                $this->candidate('w2', ['app/A.php'], 0.9), // conflicts with w1
                $this->candidate('w3', ['app/C.php'], 0.9),
            ],
            'active_leases' => 1,
            'lease_ceiling' => 8,
        ]);

        $this->assertSame('admit_partial', $result['coordination_decision']);
        $this->assertSame(2, $result['recommended_worker_count']);
        $this->assertContains('w1', $result['admitted_worker_ids']);
        $this->assertContains('w3', $result['admitted_worker_ids']);
        $this->assertNotContains('w2', $result['admitted_worker_ids']);
    }

    public function test_all_candidates_poor_fit_or_conflicting_blocks(): void
    {
        $result = $this->policy->coordinateConcurrentWork(['candidates' => [
            $this->candidate('w1', ['app/A.php'], 0.10),
            $this->candidate('w2', ['app/B.php'], 0.10),
        ]]);

        $this->assertSame('block', $result['coordination_decision']);
        $this->assertSame(0, $result['recommended_worker_count']);
        $this->assertSame('reassign_poor_fit_worker_to_better_matched_task', $result['backoff_or_route']);
    }
}
