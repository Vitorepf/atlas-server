<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueuePressureGovernor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueuePressureGovernorTest extends TestCase
{
    private function gov(): AtlasExternalBrainQueuePressureGovernor
    {
        return new AtlasExternalBrainQueuePressureGovernor;
    }

    // ── AC2: urgent repair classes bypass all pressure checks ─────────────────

    public function test_malformed_enqueues_with_urgent_override(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 100, 'active_leases' => 100],
            'candidate'   => ['task_class' => 'malformed', 'leverage_score' => 0.0],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $r['decision']);
        $this->assertTrue($r['urgent_override']);
    }

    public function test_collision_enqueues_with_urgent_override(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 100, 'active_leases' => 100],
            'candidate'   => ['task_class' => 'collision'],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $r['decision']);
        $this->assertTrue($r['urgent_override']);
    }

    public function test_lease_leak_enqueues_with_urgent_override(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 100, 'active_leases' => 100],
            'candidate'   => ['task_class' => 'lease_leak'],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $r['decision']);
        $this->assertTrue($r['urgent_override']);
    }

    public function test_normal_task_does_not_have_urgent_override(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 0, 'active_leases' => 0],
            'candidate'   => ['task_class' => 'normal', 'leverage_score' => 0.8],
        ]);

        $this->assertFalse($r['urgent_override']);
    }

    // ── AC3: critical pressure → stop (unless urgent repair) ─────────────────

    public function test_critical_pressure_returns_stop(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 30, 'active_leases' => 15],
            'candidate'   => ['task_class' => 'normal', 'leverage_score' => 1.0],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_STOP, $r['decision']);
        $this->assertFalse($r['urgent_override']);
    }

    public function test_urgent_repair_bypasses_critical_stop(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 30, 'active_leases' => 15],
            'candidate'   => ['task_class' => 'malformed'],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $r['decision']);
        $this->assertTrue($r['urgent_override']);
    }

    public function test_only_one_dimension_at_ceiling_does_not_stop(): void
    {
        // Only claimable_depth at ceiling; active_leases below → not critical
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 30, 'active_leases' => 5],
            'candidate'   => ['task_class' => 'normal', 'leverage_score' => 0.2],
        ]);

        $this->assertNotSame(AtlasExternalBrainQueuePressureGovernor::DECISION_STOP, $r['decision']);
    }

    // ── AC4: high worker pressure → consolidate medium+, defer low ───────────

    public function test_high_worker_pressure_with_medium_leverage_consolidates(): void
    {
        $r = $this->gov()->decide([
            'context'   => ['worker_pressure' => 'high'],
            'candidate' => ['leverage_score' => 0.60],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_CONSOLIDATE, $r['decision']);
    }

    public function test_high_worker_pressure_with_low_leverage_defers(): void
    {
        $r = $this->gov()->decide([
            'context'   => ['worker_pressure' => 'high'],
            'candidate' => ['leverage_score' => 0.30],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_DEFER, $r['decision']);
    }

    // ── Healthy state ─────────────────────────────────────────────────────────

    public function test_healthy_queue_enqueues(): void
    {
        $r = $this->gov()->decide([
            'queue_state' => ['claimable_depth' => 5, 'active_leases' => 3],
            'candidate'   => ['task_class' => 'normal', 'leverage_score' => 0.50],
        ]);

        $this->assertSame(AtlasExternalBrainQueuePressureGovernor::DECISION_ENQUEUE_NOW, $r['decision']);
        $this->assertFalse($r['urgent_override']);
    }

    public function test_decide_is_deterministic(): void
    {
        $input = [
            'queue_state' => ['claimable_depth' => 10, 'active_leases' => 5],
            'candidate'   => ['task_class' => 'normal', 'leverage_score' => 0.6],
            'context'     => ['worker_pressure' => 'normal'],
        ];

        $a = $this->gov()->decide($input);
        $b = $this->gov()->decide($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
