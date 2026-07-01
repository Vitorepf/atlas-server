<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphCriticalPathPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskGraphCriticalPathPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainTaskGraphCriticalPathPlanner
    {
        return new AtlasExternalBrainTaskGraphCriticalPathPlanner;
    }

    public function test_high_active_worker_drain_with_no_claimable_producing_path_returns_worker_feed_risk_high(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.8],
                ['task_id' => 'child', 'depends_on' => ['root'], 'leverage_score' => 0.8, 'status' => 'queued', 'active_worker_drain' => 0.7],
            ],
        ]);

        $this->assertSame('high', $r['worker_feed_risk']);
    }

    public function test_starvation_rescue_task_ids_surfaced_from_usable_replenishment_or_unblock_nodes(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.8],
                ['task_id' => 'child', 'depends_on' => ['root'], 'leverage_score' => 0.8, 'status' => 'queued', 'active_worker_drain' => 0.7],
                ['task_id' => 'rescue-1', 'leverage_score' => 0.5, 'status' => 'queued', 'is_replenishment_node' => true],
                ['task_id' => 'rescue-2', 'leverage_score' => 0.3, 'status' => 'queued', 'is_unblock_node' => true],
            ],
        ]);

        $this->assertSame('high', $r['worker_feed_risk']);
        $this->assertContains('rescue-1', $r['starvation_rescue_task_ids']);
        $this->assertContains('rescue-2', $r['starvation_rescue_task_ids']);
        // Higher effective_score rescue node ranks first.
        $this->assertSame('rescue-1', $r['starvation_rescue_task_ids'][0]);
    }

    public function test_no_starvation_rescue_task_ids_when_worker_feed_risk_is_low(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.1],
                ['task_id' => 'rescue-1', 'leverage_score' => 0.5, 'status' => 'queued', 'is_replenishment_node' => true],
            ],
        ]);

        $this->assertSame('low', $r['worker_feed_risk']);
        $this->assertSame([], $r['starvation_rescue_task_ids']);
    }

    public function test_blocked_stale_duplicate_done_and_low_evidence_tasks_are_never_rescue_nodes(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 1.5],
                ['task_id' => 'blocked-rescue', 'leverage_score' => 0.9, 'status' => 'blocked', 'is_replenishment_node' => true],
                ['task_id' => 'stale-rescue', 'leverage_score' => 0.9, 'status' => 'stale', 'is_replenishment_node' => true],
                ['task_id' => 'duplicate-rescue', 'leverage_score' => 0.9, 'status' => 'duplicate', 'is_unblock_node' => true],
                ['task_id' => 'done-rescue', 'leverage_score' => 0.9, 'status' => 'done', 'is_unblock_node' => true],
                ['task_id' => 'low-evidence-rescue', 'leverage_score' => 0.9, 'status' => 'queued', 'evidence_strength' => 0.1, 'is_replenishment_node' => true],
                ['task_id' => 'good-rescue', 'leverage_score' => 0.1, 'status' => 'queued', 'is_replenishment_node' => true],
            ],
        ]);

        $this->assertSame('high', $r['worker_feed_risk']);
        $this->assertSame(['good-rescue'], $r['starvation_rescue_task_ids']);
    }
}
