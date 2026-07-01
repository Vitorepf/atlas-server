<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroReplenishUrgencyClassifier;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroReplenishUrgencyClassifierWorkerFloorTest extends TestCase
{
    public function test_active_leases_with_claimable_per_active_worker_at_floor_never_waits(): void
    {
        $classifier = new AtlasMaestroReplenishUrgencyClassifier();
        $result = $classifier->classifyWorkerFloor([
            'active_leases' => 3,
            'claimable_per_active_worker' => 2.0,
        ]);

        $this->assertNotSame('wait', $result['replenish_action']);
        $this->assertContains($result['replenish_action'], ['replenish_soon', 'replenish_urgently']);
        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::REASON_WORKER_FLOOR, $result['reason']);
    }

    public function test_active_leases_with_claimable_below_floor_replenishes_urgently(): void
    {
        $classifier = new AtlasMaestroReplenishUrgencyClassifier();
        $result = $classifier->classifyWorkerFloor([
            'active_leases' => 5,
            'claimable_per_active_worker' => 0.0,
        ]);

        $this->assertSame('replenish_urgently', $result['replenish_action']);
        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::REASON_WORKER_FLOOR, $result['reason']);
    }

    public function test_zero_active_leases_with_nonempty_queue_returns_monitoring(): void
    {
        $classifier = new AtlasMaestroReplenishUrgencyClassifier();
        $result = $classifier->classifyWorkerFloor([
            'active_leases' => 0,
            'claimable_per_active_worker' => 0.5,
        ]);

        $this->assertSame('monitor_idle_supply', $result['replenish_action']);
        $this->assertNull($result['reason']);
    }

    public function test_active_leases_with_comfortable_claimable_buffer_monitors_without_stopping(): void
    {
        $classifier = new AtlasMaestroReplenishUrgencyClassifier();
        $result = $classifier->classifyWorkerFloor([
            'active_leases' => 4,
            'claimable_per_active_worker' => 10.0,
        ]);

        $this->assertSame('monitor_idle_supply', $result['replenish_action']);
        $this->assertNull($result['reason']);
    }
}
