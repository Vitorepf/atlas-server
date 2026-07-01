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

    public function test_below_floor_recommendation_requires_top_up_or_repair_proof(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'claimable_per_active_worker' => 1,
        ]);

        $this->assertTrue($result['proof_required']);
    }

    public function test_serve_clean_does_not_require_repair_proof(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'claimable_per_active_worker' => 5,
        ]);

        $this->assertFalse($result['proof_required']);
    }

    public function test_fresh_confirmed_sufficient_depth_avoids_unnecessary_top_up(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'claimable_per_active_worker' => 1,
            'sufficient_depth' => ['value' => true, 'fresh' => true],
        ]);

        $this->assertSame('serve_clean', $result['recommendation']);
        $this->assertFalse($result['proof_required']);
    }

    public function test_stale_sufficient_depth_does_not_override_worker_floor_starvation(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'claimable_per_active_worker' => 1,
            'sufficient_depth' => ['value' => true, 'fresh' => false],
        ]);

        $this->assertSame('top_up_before_serve_starvation', $result['recommendation']);
    }

    public function test_packet_id_collision_requires_repair_before_serve(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'collision_count' => 1,
            'claimable_per_active_worker' => 1,
        ]);

        $this->assertSame('resolve_collisions_before_serve', $result['recommendation']);
        $this->assertTrue($result['proof_required']);
    }

    public function test_blocked_packets_keep_precedence_over_collisions(): void
    {
        $result = $this->gate()->evaluateQueueHealth([
            'blocked_count' => 1,
            'collision_count' => 1,
        ]);

        $this->assertSame('unblock_before_serve', $result['recommendation']);
    }
}
