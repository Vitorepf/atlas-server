<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\AtlasMaestroProjectLaneScheduler;
use Tests\TestCase;

final class AtlasMaestroProjectLaneSchedulerTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $extra
     * @return array<string, mixed>
     */
    private function safeCaps(array $extra = []): array
    {
        return array_merge(['verified' => true, 'namespace_safe' => true], $extra);
    }

    public function test_plan_envelope_carries_default_topology_and_schema(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['atlas-dev' => 2], ['atlas-dev' => $this->safeCaps()]);

        $this->assertSame(AtlasMaestroProjectLaneScheduler::SCHEMA, $plan['schema_version']);
        $this->assertSame('shared_local_main_with_scope_lock', $plan['topology']);
        $this->assertFalse($plan['default_worktree_or_sandbox']);
        foreach (['allocations', 'denied_lanes', 'unallocated_budget', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $plan);
        }
    }

    public function test_zero_demand_lane_is_denied_with_no_demand(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(5, ['empty' => 0, 'busy' => 2], [
            'empty' => $this->safeCaps(),
            'busy' => $this->safeCaps(),
        ]);

        $this->assertSame('empty', $plan['denied_lanes'][0]['lane_id']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_NO_DEMAND, $plan['denied_lanes'][0]['reason']);
        $this->assertCount(1, $plan['allocations']);
        $this->assertSame('busy', $plan['allocations'][0]['lane_id']);
    }

    public function test_critical_lane_is_capped_at_one_worker(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['critical' => 5], ['critical' => $this->safeCaps(['critical' => true])]);

        $this->assertSame(1, $plan['allocations'][0]['workers']);
        $this->assertTrue($plan['allocations'][0]['critical']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_CAPPED, $plan['reasons']['critical'][0]['reason']);
    }

    public function test_tight_budget_reduces_largest_lane_deterministically(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(4, ['alpha' => 4, 'beta' => 3], [
            'alpha' => $this->safeCaps(),
            'beta' => $this->safeCaps(),
        ]);

        $byLane = [];
        foreach ($plan['allocations'] as $row) {
            $byLane[$row['lane_id']] = $row['workers'];
        }
        $this->assertSame(4, array_sum($byLane), 'allocations sum to the budget');
        $this->assertGreaterThan(0, $byLane['alpha']);
        $this->assertGreaterThan(0, $byLane['beta']);
        $this->assertSame(0, $plan['unallocated_budget']);
        $this->assertArrayHasKey('alpha', $plan['reasons']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_BUDGET_REDUCED, $plan['reasons']['alpha'][0]['reason']);
    }

    public function test_byte_identical_json_on_repeat_invocation(): void
    {
        $scheduler = new AtlasMaestroProjectLaneScheduler;
        $demand = ['b' => 3, 'a' => 2, 'c' => 1];
        $caps = [
            'a' => $this->safeCaps(['cap' => 1]),
            'b' => $this->safeCaps(['critical' => true]),
            'c' => $this->safeCaps(),
        ];

        $a = $scheduler->plan(5, $demand, $caps);
        $b = $scheduler->plan(5, $demand, $caps);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_starvation_floor_keeps_one_worker_per_lane_when_budget_equals_lane_count(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(3, ['a' => 5, 'b' => 4, 'c' => 3], [
            'a' => $this->safeCaps(),
            'b' => $this->safeCaps(),
            'c' => $this->safeCaps(),
        ]);

        $this->assertCount(3, $plan['allocations'], 'all three lanes must be allocated');
        $this->assertSame(0, $plan['unallocated_budget']);
        $this->assertSame([], $plan['denied_lanes']);
        foreach ($plan['allocations'] as $alloc) {
            $this->assertSame(1, $alloc['workers'], "lane {$alloc['lane_id']} must get at least 1 worker");
        }
    }

    public function test_budget_starvation_denies_excess_lanes_deterministically(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(1, ['a' => 3, 'b' => 2, 'c' => 1], [
            'a' => $this->safeCaps(),
            'b' => $this->safeCaps(),
            'c' => $this->safeCaps(),
        ]);

        $this->assertCount(1, $plan['allocations'], 'only one lane survives the budget');
        $this->assertSame('a', $plan['allocations'][0]['lane_id'], 'largest-demand lane keeps the budget');
        $this->assertSame(1, $plan['allocations'][0]['workers']);
        $this->assertSame(0, $plan['unallocated_budget']);

        $deniedIds = array_column($plan['denied_lanes'], 'lane_id');
        $this->assertContains('b', $deniedIds, 'b must be denied as budget_starved');
        $this->assertContains('c', $deniedIds, 'c must be denied as budget_starved');
        $starvedReasons = array_column(
            array_filter($plan['denied_lanes'], static fn (array $d): bool => $d['reason'] === AtlasMaestroProjectLaneScheduler::REASON_BUDGET_STARVED),
            'reason',
        );
        $this->assertNotEmpty($starvedReasons, 'budget_starved reason must appear in denied_lanes');
    }

    public function test_unallocated_budget_when_demand_below_budget(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['a' => 2, 'b' => 1], [
            'a' => $this->safeCaps(),
            'b' => $this->safeCaps(),
        ]);

        $this->assertSame(7, $plan['unallocated_budget']);
    }

    // ── AC2: refuses lanes lacking verification or namespace safety ────────────

    public function test_unverified_lane_is_denied_regardless_of_demand(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['risky' => 5], ['risky' => ['verified' => false]]);

        $this->assertSame([], $plan['allocations']);
        $this->assertSame('risky', $plan['denied_lanes'][0]['lane_id']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_UNVERIFIED_LANE, $plan['denied_lanes'][0]['reason']);
    }

    public function test_namespace_unsafe_lane_is_denied_regardless_of_demand(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['unsafe' => 5], ['unsafe' => ['verified' => true, 'namespace_safe' => false]]);

        $this->assertSame([], $plan['allocations']);
        $this->assertSame('unsafe', $plan['denied_lanes'][0]['lane_id']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_NAMESPACE_UNSAFE, $plan['denied_lanes'][0]['reason']);
    }

    public function test_verified_and_namespace_safe_lane_is_allocated(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['ok' => 3], ['ok' => ['verified' => true, 'namespace_safe' => true]]);

        $this->assertCount(1, $plan['allocations']);
        $this->assertSame('ok', $plan['allocations'][0]['lane_id']);
    }

    public function test_verification_and_namespace_safety_default_false_when_absent(): void
    {
        // After the fail-closed fix, a lane with no verified/namespace_safe keys
        // defaults both to false and is denied — matching AC2 which says a lane
        // lacking verification is refused outright.
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['legacy' => 2], ['legacy' => []]);

        $this->assertSame([], $plan['allocations']);
        $this->assertNotEmpty($plan['denied_lanes']);
        $this->assertSame('legacy', $plan['denied_lanes'][0]['lane_id']);
    }

    // ── AC1: urgency/value protect high-priority lanes from reduction ─────────

    public function test_low_urgency_lane_is_reduced_before_high_urgency_lane(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(3, ['urgent' => 2, 'routine' => 2], [
            'urgent' => $this->safeCaps(['urgency' => 10]),
            'routine' => $this->safeCaps(['urgency' => 0]),
        ]);

        $byLane = array_column($plan['allocations'], 'workers', 'lane_id');
        $this->assertSame(2, $byLane['urgent']);
        $this->assertSame(1, $byLane['routine']);
    }

    public function test_isolation_risk_lane_is_reduced_before_urgency_is_considered(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(3, ['risky' => 2, 'safe' => 2], [
            'risky' => $this->safeCaps(['urgency' => 10, 'isolation_risk' => true]),
            'safe' => $this->safeCaps(['urgency' => 0]),
        ]);

        $byLane = array_column($plan['allocations'], 'workers', 'lane_id');
        $this->assertSame(1, $byLane['risky']);
        $this->assertSame(2, $byLane['safe']);
    }

    public function test_starvation_denial_protects_high_urgency_lane_over_low_urgency_lane(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(1, ['critical-work' => 2, 'routine-work' => 2], [
            'critical-work' => $this->safeCaps(['urgency' => 5]),
            'routine-work' => $this->safeCaps(['urgency' => 0]),
        ]);

        $this->assertCount(1, $plan['allocations']);
        $this->assertSame('critical-work', $plan['allocations'][0]['lane_id']);
        $deniedIds = array_column($plan['denied_lanes'], 'lane_id');
        $this->assertContains('routine-work', $deniedIds);
    }

    public function test_starvation_denial_protects_old_waiting_lane_when_urgency_and_value_tie(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(1, ['old-lane' => 2, 'new-lane' => 2], [
            'old-lane' => $this->safeCaps(['age_seconds' => 100000]),
            'new-lane' => $this->safeCaps(['age_seconds' => 10]),
        ]);

        $this->assertCount(1, $plan['allocations']);
        $this->assertSame('old-lane', $plan['allocations'][0]['lane_id']);
    }

    public function test_urgency_value_isolation_risk_absent_preserves_prior_behavior(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(4, ['alpha' => 4, 'beta' => 3], [
            'alpha' => $this->safeCaps(),
            'beta' => $this->safeCaps(),
        ]);
        $byLane = array_column($plan['allocations'], 'workers', 'lane_id');

        $this->assertSame(4, array_sum($byLane));
        $this->assertGreaterThan(0, $byLane['alpha']);
        $this->assertGreaterThan(0, $byLane['beta']);
    }

    // ── AC3: fairness_rationale is present and explains decisions ──────────────

    public function test_fairness_rationale_present_and_explains_reduction(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(4, ['alpha' => 4, 'beta' => 3], [
            'alpha' => $this->safeCaps(),
            'beta' => $this->safeCaps(),
        ]);

        $this->assertArrayHasKey('fairness_rationale', $plan);
        $this->assertNotEmpty($plan['fairness_rationale']);
        $blob = implode(' ', $plan['fairness_rationale']);
        $this->assertStringContainsString('alpha', $blob);
    }

    public function test_fairness_rationale_explains_verification_denial(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['risky' => 5], ['risky' => ['verified' => false]]);

        $blob = implode(' ', $plan['fairness_rationale']);
        $this->assertStringContainsString('risky', $blob);
    }

    public function test_fairness_rationale_empty_when_all_demand_fits_budget(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['a' => 2], ['a' => $this->safeCaps()]);

        $this->assertSame([], $plan['fairness_rationale']);
    }
}
