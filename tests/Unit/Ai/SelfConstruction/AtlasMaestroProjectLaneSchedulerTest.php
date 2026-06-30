<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\AtlasMaestroProjectLaneScheduler;
use Tests\TestCase;

final class AtlasMaestroProjectLaneSchedulerTest extends TestCase
{
    public function test_plan_envelope_carries_default_topology_and_schema(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['atlas-dev' => 2], ['atlas-dev' => []]);

        $this->assertSame(AtlasMaestroProjectLaneScheduler::SCHEMA, $plan['schema_version']);
        $this->assertSame('shared_local_main_with_scope_lock', $plan['topology']);
        $this->assertFalse($plan['default_worktree_or_sandbox']);
        foreach (['allocations', 'denied_lanes', 'unallocated_budget', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $plan);
        }
    }

    public function test_zero_demand_lane_is_denied_with_no_demand(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(5, ['empty' => 0, 'busy' => 2], ['empty' => [], 'busy' => []]);

        $this->assertSame('empty', $plan['denied_lanes'][0]['lane_id']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_NO_DEMAND, $plan['denied_lanes'][0]['reason']);
        $this->assertCount(1, $plan['allocations']);
        $this->assertSame('busy', $plan['allocations'][0]['lane_id']);
    }

    public function test_critical_lane_is_capped_at_one_worker(): void
    {
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['critical' => 5], ['critical' => ['critical' => true]]);

        $this->assertSame(1, $plan['allocations'][0]['workers']);
        $this->assertTrue($plan['allocations'][0]['critical']);
        $this->assertSame(AtlasMaestroProjectLaneScheduler::REASON_CAPPED, $plan['reasons']['critical'][0]['reason']);
    }

    public function test_tight_budget_reduces_largest_lane_deterministically(): void
    {
        // total demand 4+3 = 7; budget 4 ⇒ trim 3 from the largest. Largest is alpha (4 → 1).
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(4, ['alpha' => 4, 'beta' => 3], ['alpha' => [], 'beta' => []]);

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
        $caps = ['a' => ['cap' => 1], 'b' => ['critical' => true], 'c' => []];

        $a = $scheduler->plan(5, $demand, $caps);
        $b = $scheduler->plan(5, $demand, $caps);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_starvation_floor_keeps_one_worker_per_lane_when_budget_equals_lane_count(): void
    {
        // budget=3 = 3 positive-demand lanes; every lane must get exactly 1 after reduction.
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(3, ['a' => 5, 'b' => 4, 'c' => 3], ['a' => [], 'b' => [], 'c' => []]);

        $this->assertCount(3, $plan['allocations'], 'all three lanes must be allocated');
        $this->assertSame(0, $plan['unallocated_budget']);
        $this->assertSame([], $plan['denied_lanes']);
        foreach ($plan['allocations'] as $alloc) {
            $this->assertSame(1, $alloc['workers'], "lane {$alloc['lane_id']} must get at least 1 worker");
        }
    }

    public function test_budget_starvation_denies_excess_lanes_deterministically(): void
    {
        // budget=1, 3 positive-demand lanes → 2 must be denied with budget_starved; smallest demand denied first.
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(1, ['a' => 3, 'b' => 2, 'c' => 1], ['a' => [], 'b' => [], 'c' => []]);

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
        $plan = (new AtlasMaestroProjectLaneScheduler)->plan(10, ['a' => 2, 'b' => 1], ['a' => [], 'b' => []]);

        $this->assertSame(7, $plan['unallocated_budget']);
    }
}
