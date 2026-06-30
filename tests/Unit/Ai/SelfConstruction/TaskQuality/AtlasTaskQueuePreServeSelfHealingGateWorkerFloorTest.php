<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueuePreServeSelfHealingGate;
use PHPUnit\Framework\TestCase;

final class AtlasTaskQueuePreServeSelfHealingGateWorkerFloorTest extends TestCase
{
    private function gate(): AtlasTaskQueuePreServeSelfHealingGate
    {
        return new AtlasTaskQueuePreServeSelfHealingGate;
    }

    public function test_claimable_per_active_worker_at_or_below_floor_recommends_top_up_before_starvation(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'claimable_per_active_worker' => 2,
        ]);

        $this->assertSame('top_up_before_serve_starvation', $result['recommendation']);
    }

    public function test_malformed_packets_keep_precedence_over_worker_floor_top_up(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'malformed_count' => 2,
            'claimable_per_active_worker' => 1,
        ]);

        $this->assertSame('repair_malformed_before_serve', $result['recommendation']);
    }

    public function test_blocked_packets_keep_precedence_over_worker_floor_top_up(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'blocked_count' => 1,
            'claimable_per_active_worker' => 0.5,
        ]);

        $this->assertSame('unblock_before_serve', $result['recommendation']);
    }

    public function test_comfortable_claimable_per_active_worker_recommends_serve_clean(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'claimable_per_active_worker' => 5,
        ]);

        $this->assertSame('serve_clean', $result['recommendation']);
    }

    public function test_missing_worker_floor_facts_recommends_serve_clean(): void
    {
        $result = $this->gate()->evaluateQueueHealth([]);

        $this->assertSame('serve_clean', $result['recommendation']);
    }
}
