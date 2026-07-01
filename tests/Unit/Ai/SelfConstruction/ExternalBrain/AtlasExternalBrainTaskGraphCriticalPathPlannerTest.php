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

    // ── worker_feed_score / chain_unlock_count / critical_path_worker_safe / next_best_parallel_task_ids ──

    public function test_critical_path_worker_safe_true_when_worker_feed_risk_low(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.1],
            ],
        ]);

        $this->assertSame('low', $r['worker_feed_risk']);
        $this->assertTrue($r['critical_path_worker_safe']);
    }

    public function test_critical_path_worker_safe_false_when_worker_feed_risk_high(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 1.5],
            ],
        ]);

        $this->assertSame('high', $r['worker_feed_risk']);
        $this->assertFalse($r['critical_path_worker_safe']);
    }

    public function test_worker_feed_score_present_and_bounded_between_zero_and_one(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.5],
            ],
        ]);

        $this->assertGreaterThanOrEqual(0.0, $r['worker_feed_score']);
        $this->assertLessThanOrEqual(1.0, $r['worker_feed_score']);
    }

    public function test_worker_feed_score_lower_with_higher_drain(): void
    {
        $low = $this->planner()->plan([
            'tasks' => [['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.1]],
        ]);
        $high = $this->planner()->plan([
            'tasks' => [['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued', 'active_worker_drain' => 0.9]],
        ]);

        $this->assertGreaterThan($high['worker_feed_score'], $low['worker_feed_score']);
    }

    public function test_chain_unlock_count_counts_downstream_dependents_of_critical_path(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued'],
                ['task_id' => 'dep-a', 'leverage_score' => 0.1, 'effort' => 20, 'status' => 'queued', 'depends_on' => ['root']],
                ['task_id' => 'dep-b', 'leverage_score' => 0.1, 'effort' => 20, 'status' => 'queued', 'depends_on' => ['root']],
            ],
        ]);

        $this->assertSame(['root'], $r['critical_path_task_ids']);
        $this->assertSame(2, $r['chain_unlock_count']);
    }

    public function test_chain_unlock_count_zero_when_no_downstream_dependents(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(0, $r['chain_unlock_count']);
    }

    public function test_next_best_parallel_task_ids_surfaces_best_task_per_branch(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'critical-root', 'leverage_score' => 1.9, 'status' => 'queued'],
                ['task_id' => 'branch-low', 'leverage_score' => 0.2, 'status' => 'queued'],
                ['task_id' => 'branch-high', 'leverage_score' => 0.8, 'status' => 'queued', 'depends_on' => ['branch-low']],
            ],
        ]);

        $this->assertSame(['critical-root'], $r['critical_path_task_ids']);
        $this->assertContains('branch-high', $r['next_best_parallel_task_ids']);
        $this->assertNotContains('branch-low', $r['next_best_parallel_task_ids']);
    }

    public function test_next_best_parallel_task_ids_empty_when_no_parallelizable_branches(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'only', 'leverage_score' => 0.9, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame([], $r['next_best_parallel_task_ids']);
    }

    // ── new AC: downstream_unblock_count boosts critical path selection ──────

    public function test_high_downstream_unblock_count_can_beat_standalone_high_leverage_task(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'unblock-node', 'leverage_score' => 0.3, 'status' => 'queued', 'downstream_unblock_count' => 10],
                ['task_id' => 'standalone-high', 'leverage_score' => 0.9, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['unblock-node'], $r['critical_path_task_ids']);
    }

    public function test_blocked_stale_duplicate_and_low_evidence_tasks_excluded_despite_high_downstream_unblock_count(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'blocked-unblock', 'leverage_score' => 0.9, 'status' => 'blocked', 'downstream_unblock_count' => 10],
                ['task_id' => 'stale-unblock', 'leverage_score' => 0.9, 'status' => 'stale', 'downstream_unblock_count' => 10],
                ['task_id' => 'duplicate-unblock', 'leverage_score' => 0.9, 'status' => 'duplicate', 'downstream_unblock_count' => 10],
                ['task_id' => 'low-evidence-unblock', 'leverage_score' => 0.9, 'status' => 'queued', 'evidence_strength' => 0.1, 'downstream_unblock_count' => 10],
                ['task_id' => 'good-node', 'leverage_score' => 0.1, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['good-node'], $r['critical_path_task_ids']);
    }

    public function test_high_produces_claimable_count_can_beat_standalone_high_leverage_task(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'claimable-producer', 'leverage_score' => 0.3, 'status' => 'queued', 'produces_claimable_count' => 10],
                ['task_id' => 'standalone-high', 'leverage_score' => 0.9, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['claimable-producer'], $r['critical_path_task_ids']);
    }

    public function test_claimable_producing_node_deprioritizes_broad_low_leverage_branch(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'claimable-producer', 'leverage_score' => 0.3, 'status' => 'queued', 'produces_claimable_count' => 5],
                ['task_id' => 'broad-low-leverage', 'leverage_score' => 0.2, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame('claimable-producer', $r['next_best_task']);
        $this->assertContains('broad-low-leverage', $r['next_best_parallel_task_ids']);
    }

    public function test_chain_unlock_count_remains_deterministic_for_parallel_branches(): void
    {
        $input = [
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'status' => 'queued'],
                ['task_id' => 'dep-a', 'leverage_score' => 0.1, 'effort' => 20, 'status' => 'queued', 'depends_on' => ['root']],
                ['task_id' => 'dep-b', 'leverage_score' => 0.1, 'effort' => 20, 'status' => 'queued', 'depends_on' => ['root']],
                ['task_id' => 'branch', 'leverage_score' => 0.2, 'status' => 'queued'],
            ],
        ];

        $a = $this->planner()->plan($input);
        $b = $this->planner()->plan($input);

        $this->assertSame($a['chain_unlock_count'], $b['chain_unlock_count']);
        $this->assertSame(2, $a['chain_unlock_count']);
    }
}
