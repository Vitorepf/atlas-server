<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphCriticalPathPlanner;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphCriticalPathPlannerTest extends TestCase
{
    public function test_picks_highest_leverage_chain_as_critical_path(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'root', 'leverage_score' => 0.9, 'effort' => 1, 'status' => 'queued'],
                ['task_id' => 'child', 'depends_on' => ['root'], 'leverage_score' => 0.8, 'effort' => 1, 'status' => 'queued'],
                ['task_id' => 'lone-low', 'leverage_score' => 0.1, 'effort' => 5, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['root', 'child'], $result['critical_path_task_ids']);
        $this->assertSame('root', $result['next_best_task']);
        $this->assertGreaterThan(0, $result['path_score']);
    }

    public function test_blocked_task_is_excluded_from_path_even_with_high_leverage(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'tempting-but-blocked', 'leverage_score' => 0.99, 'status' => 'blocked'],
                ['task_id' => 'modest-but-usable', 'leverage_score' => 0.4, 'status' => 'queued'],
            ],
        ]);

        $this->assertNotContains('tempting-but-blocked', $result['critical_path_task_ids']);
        $this->assertSame(['modest-but-usable'], $result['critical_path_task_ids']);
    }

    public function test_stale_and_duplicate_tasks_are_excluded(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'stale-task', 'leverage_score' => 0.99, 'status' => 'stale'],
                ['task_id' => 'duplicate-task', 'leverage_score' => 0.99, 'status' => 'duplicate'],
                ['task_id' => 'real-task', 'leverage_score' => 0.2, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['real-task'], $result['critical_path_task_ids']);
    }

    public function test_low_evidence_strength_excludes_task_despite_high_leverage(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'unproven', 'leverage_score' => 0.99, 'evidence_strength' => 0.1, 'status' => 'queued'],
                ['task_id' => 'proven', 'leverage_score' => 0.3, 'evidence_strength' => 0.9, 'status' => 'queued'],
            ],
        ]);

        $this->assertNotContains('unproven', $result['critical_path_task_ids']);
        $this->assertSame(['proven'], $result['critical_path_task_ids']);
    }

    public function test_high_risk_penalizes_effective_score(): void
    {
        $resultLowRisk = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [['task_id' => 'a', 'leverage_score' => 0.5, 'risk' => 'low', 'status' => 'queued']],
        ]);
        $resultHighRisk = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [['task_id' => 'a', 'leverage_score' => 0.5, 'risk' => 'high', 'status' => 'queued']],
        ]);

        $this->assertGreaterThan($resultHighRisk['path_score'], $resultLowRisk['path_score']);
    }

    public function test_done_tasks_are_excluded_from_the_path(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'finished', 'leverage_score' => 0.9, 'status' => 'done'],
                ['task_id' => 'next-up', 'depends_on' => ['finished'], 'leverage_score' => 0.4, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['next-up'], $result['critical_path_task_ids']);
    }

    public function test_bottleneck_tasks_identifies_high_fanout_critical_path_nodes(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'hub', 'leverage_score' => 0.9, 'status' => 'queued'],
                ['task_id' => 'spoke-1', 'depends_on' => ['hub'], 'leverage_score' => 0.6, 'status' => 'queued'],
                ['task_id' => 'spoke-2', 'depends_on' => ['hub'], 'leverage_score' => 0.5, 'status' => 'queued'],
            ],
        ]);

        $this->assertContains('hub', $result['bottleneck_tasks']);
    }

    public function test_parallelizable_branches_groups_independent_usable_tasks_outside_the_path(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan([
            'tasks' => [
                ['task_id' => 'main-root', 'leverage_score' => 0.9, 'status' => 'queued'],
                ['task_id' => 'main-child', 'depends_on' => ['main-root'], 'leverage_score' => 0.8, 'status' => 'queued'],
                ['task_id' => 'branch-a', 'leverage_score' => 0.2, 'status' => 'queued'],
                ['task_id' => 'branch-a-child', 'depends_on' => ['branch-a'], 'leverage_score' => 0.1, 'status' => 'queued'],
            ],
        ]);

        $this->assertSame(['main-root', 'main-child'], $result['critical_path_task_ids']);
        $this->assertCount(1, $result['parallelizable_branches']);
        $this->assertEqualsCanonicalizing(['branch-a', 'branch-a-child'], $result['parallelizable_branches'][0]);
    }

    public function test_empty_task_list_returns_empty_path(): void
    {
        $result = (new AtlasExternalBrainTaskGraphCriticalPathPlanner)->plan(['tasks' => []]);

        $this->assertSame([], $result['critical_path_task_ids']);
        $this->assertNull($result['next_best_task']);
        $this->assertSame(0.0, $result['path_score']);
    }
}
