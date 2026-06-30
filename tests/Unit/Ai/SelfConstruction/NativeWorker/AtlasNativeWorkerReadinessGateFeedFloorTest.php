<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerCapabilityRegistry;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerExecutionEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerReadinessGate;
use PHPUnit\Framework\TestCase;

final class AtlasNativeWorkerReadinessGateFeedFloorTest extends TestCase
{
    private function gate(): AtlasNativeWorkerReadinessGate
    {
        return new AtlasNativeWorkerReadinessGate(new AtlasNativeWorkerCapabilityRegistry);
    }

    private function allReady(): array
    {
        return [
            'runtime_owner' => AtlasNativeWorkerExecutionEnvelopeBuilder::RUNTIME_OWNER,
            'server_side_verification_available' => true,
            'rollback_available' => true,
            'queue_pressure' => 5,
            'available_worker_count' => 2,
            'heartbeat_age_seconds' => 30,
            'command_plan_runner_available' => true,
            'claimable_per_active_worker' => 2.0,
            'components' => [
                'patch_planner' => ['present' => true, 'verified' => true],
                'scoped_patch_applier' => ['present' => true, 'verified' => true],
                'gate_runner' => ['present' => true, 'verified' => true],
                'evidence_writer' => ['present' => true, 'verified' => true],
                'rollback_runner' => ['present' => true, 'verified' => true],
                'learning_receipt_writer' => ['present' => true, 'verified' => true],
            ],
        ];
    }

    public function test_claimable_per_active_worker_below_floor_blocks_readiness_with_queue_feed_floor_blocker(): void
    {
        $o = $this->allReady();
        $o['claimable_per_active_worker'] = 0.4;

        $v = $this->gate()->evaluate($o);

        $this->assertFalse($v['ready']);
        $this->assertNotEmpty(array_filter($v['blockers'], static fn (string $b): bool => str_starts_with($b, 'queue_feed_floor:')));
    }

    public function test_meeting_worker_feed_floor_and_all_other_checks_returns_ready_true(): void
    {
        $v = $this->gate()->evaluate($this->allReady());

        $this->assertTrue($v['ready']);
        $this->assertSame([], $v['blockers']);
    }

    public function test_servable_per_worker_below_floor_blocks_when_claimable_per_active_worker_absent(): void
    {
        $o = $this->allReady();
        unset($o['claimable_per_active_worker']);
        $o['servable_per_worker'] = 0.2;

        $v = $this->gate()->evaluate($o);

        $this->assertFalse($v['ready']);
        $this->assertNotEmpty(array_filter($v['blockers'], static fn (string $b): bool => str_starts_with($b, 'queue_feed_floor:')));
    }

    public function test_no_feed_facts_at_all_does_not_add_feed_floor_blocker(): void
    {
        $o = $this->allReady();
        unset($o['claimable_per_active_worker']);

        $v = $this->gate()->evaluate($o);

        $this->assertTrue($v['ready']);
    }
}
