<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceScheduler;
use Tests\TestCase;

final class AtlasProjectLaneRuntimeInstanceSchedulerTest extends TestCase
{
    private function laneInstance(string $laneId, string $projectId = 'p', array $overrides = []): array
    {
        return array_replace([
            'lane_id' => $laneId,
            'project_id' => $projectId,
            'queue_namespace' => $laneId.'.ns',
            'allowed_roots' => ['projects/'.$projectId.'/src/'.$laneId],
            'isolation_evidence_refs' => ['receipts/'.$laneId.'.jsonl'],
        ], $overrides);
    }

    public function test_max_parallel_lanes_caps_tick_now_and_blocks_remaining(): void
    {
        $instances = [
            $this->laneInstance('a', 'p1'),
            $this->laneInstance('b', 'p2'),
            $this->laneInstance('c', 'p3'),
        ];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);

        $this->assertCount(2, $verdict['tick_now']);
        $this->assertCount(1, $verdict['blocked_lanes']);
        $this->assertSame(['max_parallel_lanes_reached'], $verdict['blocked_lanes'][0]['reasons']);
    }

    public function test_deterministic_ordering_by_urgency_then_heartbeat_age_then_lane_id(): void
    {
        $instances = [
            $this->laneInstance('c'),
            $this->laneInstance('a'),
            $this->laneInstance('b'),
        ];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, [
            'max_parallel_lanes' => 3,
            'lane_health' => [
                'a' => ['urgency' => 5, 'heartbeat_age_seconds' => 10],
                'b' => ['urgency' => 5, 'heartbeat_age_seconds' => 50],
                'c' => ['urgency' => 9, 'heartbeat_age_seconds' => 0],
            ],
        ]);

        $order = array_column($verdict['tick_now'], 'lane_id');
        // c (urgency 9) first; then b (urgency 5, older heartbeat 50); then a (urgency 5, age 10).
        $this->assertSame(['c', 'b', 'a'], $order);
    }

    public function test_safety_stop_holds_lane(): void
    {
        $instances = [$this->laneInstance('a')];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, [
            'max_parallel_lanes' => 5,
            'lane_health' => ['a' => ['safety_stop' => true]],
        ]);

        $this->assertSame([], $verdict['tick_now']);
        $this->assertSame('a', $verdict['held_lanes'][0]['lane_id']);
        $this->assertContains('safety_stop', $verdict['held_lanes'][0]['reasons']);
    }

    public function test_stale_heartbeat_holds_lane(): void
    {
        $instances = [$this->laneInstance('a')];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, [
            'max_parallel_lanes' => 5,
            'heartbeat_staleness_seconds' => 60,
            'lane_health' => ['a' => ['heartbeat_age_seconds' => 600]],
        ]);

        $this->assertSame([], $verdict['tick_now']);
        $this->assertContains('stale_heartbeat', $verdict['held_lanes'][0]['reasons']);
    }

    public function test_human_operator_or_external_provider_dependency_holds_lane(): void
    {
        foreach (['operator', 'human', 'external_provider', 'claude_code', 'codex', 'cursor'] as $dep) {
            $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([$this->laneInstance('a')], [
                'max_parallel_lanes' => 5,
                'lane_health' => ['a' => ['steady_state_dependencies' => [$dep]]],
            ]);
            $this->assertNotEmpty($verdict['held_lanes'], "{$dep} must hold the lane");
        }
    }

    public function test_missing_isolation_evidence_holds_lane(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p', ['isolation_evidence_refs' => []]),
        ], ['max_parallel_lanes' => 5]);

        $this->assertContains('missing_isolation_evidence', $verdict['held_lanes'][0]['reasons']);
    }

    public function test_queue_namespace_conflict_between_lanes_holds_second(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p1', ['queue_namespace' => 'shared.ns']),
            $this->laneInstance('b', 'p2', ['queue_namespace' => 'shared.ns']),
        ], ['max_parallel_lanes' => 5]);

        $this->assertSame('a', $verdict['tick_now'][0]['lane_id']);
        $this->assertSame('b', $verdict['held_lanes'][0]['lane_id']);
        $this->assertContains('queue_namespace_conflict', $verdict['held_lanes'][0]['reasons']);
    }

    public function test_cross_project_write_root_leak_holds_second_lane(): void
    {
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p1', ['allowed_roots' => ['shared/root']]),
            $this->laneInstance('b', 'p2', ['allowed_roots' => ['shared/root']]),
        ], ['max_parallel_lanes' => 5]);

        $this->assertSame('a', $verdict['tick_now'][0]['lane_id']);
        $this->assertContains('cross_project_write_root_leak', $verdict['held_lanes'][0]['reasons']);
    }

    public function test_budget_exhausted_blocks_remaining_lanes(): void
    {
        $instances = [
            $this->laneInstance('a'),
            $this->laneInstance('b'),
        ];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, [
            'max_parallel_lanes' => 5,
            'budget' => ['max_ticks' => 1],
        ]);

        $this->assertCount(1, $verdict['tick_now']);
        $this->assertContains('budget_exhausted', $verdict['blocked_lanes'][0]['reasons']);
        $this->assertSame(0, $verdict['budget_facts']['budget_remaining']);
    }

    public function test_scheduler_hash_is_deterministic_for_identical_input(): void
    {
        $instances = [$this->laneInstance('a'), $this->laneInstance('b')];
        $a = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);
        $b = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);

        $this->assertSame($a['scheduler_hash'], $b['scheduler_hash']);
    }
}
