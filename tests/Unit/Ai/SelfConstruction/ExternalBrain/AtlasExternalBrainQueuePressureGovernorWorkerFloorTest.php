<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueuePressureGovernor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueuePressureGovernorWorkerFloorTest extends TestCase
{
    private function governor(): AtlasExternalBrainQueuePressureGovernor
    {
        return new AtlasExternalBrainQueuePressureGovernor;
    }

    public function test_thin_worker_buffer_with_active_leases_and_no_malformed_requests_bounded_batch(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 0,
            'claimable_per_active_worker' => 2,
            'active_leases' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_REQUEST_BOUNDED_BATCH, $result['action']);
        $this->assertGreaterThan(0, $result['max_tasks']);
    }

    public function test_replenish_soon_signal_with_active_leases_requests_bounded_batch(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 0,
            'active_leases' => 4,
            'replenish_action' => 'replenish_soon',
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_REQUEST_BOUNDED_BATCH, $result['action']);
    }

    public function test_malformed_sweep_findings_hold_instead_of_requesting_generation(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 4,
            'claimable_per_active_worker' => 1,
            'active_leases' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_HOLD, $result['action']);
        $this->assertSame(0, $result['max_tasks']);
    }

    public function test_comfortable_buffer_holds_without_extra_generation(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 0,
            'claimable_per_active_worker' => 8,
            'active_leases' => 3,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_HOLD, $result['action']);
        $this->assertSame(0, $result['max_tasks']);
    }

    public function test_no_active_leases_holds_even_with_thin_buffer(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([
            'malformed_count' => 0,
            'claimable_per_active_worker' => 1,
            'active_leases' => 0,
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_HOLD, $result['action']);
    }

    public function test_missing_facts_hold_by_default(): void
    {
        $result = $this->governor()->evaluateWorkerFloor([]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::ACTION_HOLD, $result['action']);
    }
}
