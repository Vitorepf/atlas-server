<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane scheduler is live at the operator surface and emits a deterministic allocation
 * plan: demand within budget is granted as-is, a lane cap clamps the grant, and over-budget demand is
 * fairly reduced to the budget.
 */
final class AtlasLoopLaneScheduleCommandTest extends TestCase
{
    public function test_demand_within_budget_is_granted(): void
    {
        $decoded = $this->plan(5, ['alpha' => 3, 'beta' => 2], []);

        $this->assertSame('atlas.maestro.project_lane_plan.v1', $decoded['schema_version']);
        $byLane = array_column($decoded['allocations'], 'workers', 'lane_id');
        $this->assertSame(3, $byLane['alpha']);
        $this->assertSame(2, $byLane['beta']);
        $this->assertSame(0, $decoded['unallocated_budget']);
    }

    public function test_lane_cap_clamps_the_grant(): void
    {
        $decoded = $this->plan(10, ['alpha' => 5], ['alpha' => ['cap' => 2]]);

        $byLane = array_column($decoded['allocations'], 'workers', 'lane_id');
        $this->assertSame(2, $byLane['alpha']);
        // 8 of the 10 budget stays unallocated since the cap held the lane to 2
        $this->assertSame(8, $decoded['unallocated_budget']);
    }

    public function test_over_budget_demand_is_fairly_reduced(): void
    {
        $decoded = $this->plan(3, ['alpha' => 3, 'beta' => 2], []);

        $total = array_sum(array_column($decoded['allocations'], 'workers'));
        $this->assertSame(3, $total); // trimmed to the budget
        $this->assertSame(0, $decoded['unallocated_budget']);
    }

    /**
     * @param  array<string,int>  $demand
     * @param  array<string,mixed>  $caps
     * @return array<string,mixed>
     */
    private function plan(int $budget, array $demand, array $caps): array
    {
        $exit = Artisan::call('atlas:loop:lane-schedule', [
            '--budget' => $budget,
            '--demand' => json_encode($demand),
            '--caps' => json_encode((object) $caps),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
