<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueuePressureGovernor;
use Tests\TestCase;

class AtlasExternalBrainQueuePressureGovernorWorkerStarvationTest extends TestCase
{
    public function test_worker_starvation_bypasses_pressure_and_enqueues_now(): void
    {
        $governor = new AtlasExternalBrainQueuePressureGovernor();

        $result = $governor->decide([
            'queue_state' => [
                'servable_depth' => 10,
                'active_leases' => 6,
                'worker_floor' => 2,
            ],
            'candidate' => [
                'replenishes_worker_capacity' => true,
            ],
        ]);

        $this->assertSame('enqueue_now', $result['decision']);
        $this->assertStringContainsString('worker starvation', $result['reason']);
        $this->assertLessThanOrEqual(3, $result['batch_budget']['max_tasks']);
    }

    public function test_saturated_non_replenishment_low_leverage_fixture_still_defers(): void
    {
        $governor = new AtlasExternalBrainQueuePressureGovernor();

        $result = $governor->decide([
            'queue_state' => [
                'claimable_depth' => 40,
                'active_leases' => 20,
                'servable_depth' => 5,
                'worker_floor' => 2,
            ],
            'candidate' => [
                'leverage_score' => 0.1,
                'replenishes_worker_capacity' => false,
            ],
        ]);

        $this->assertSame('stop', $result['decision']);
    }
}
