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

    public function test_fair_project_lane_scheduling_orders_by_urgency_across_projects(): void
    {
        // Two projects — higher urgency project schedules first regardless of input order.
        $instances = [
            $this->laneInstance('beta', 'proj-B'),
            $this->laneInstance('alpha', 'proj-A'),
        ];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, [
            'max_parallel_lanes' => 2,
            'lane_health' => [
                'beta' => ['urgency' => 3],
                'alpha' => ['urgency' => 9],
            ],
        ]);

        $this->assertCount(2, $verdict['tick_now']);
        $this->assertSame('alpha', $verdict['tick_now'][0]['lane_id'], 'higher-urgency lane must tick first');
        $this->assertSame('beta', $verdict['tick_now'][1]['lane_id']);
    }

    public function test_stale_context_is_rejected_and_lane_held(): void
    {
        $instances = [$this->laneInstance('a')];
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, [
            'max_parallel_lanes' => 5,
            'lane_health' => ['a' => ['context_freshness_stale' => true]],
        ]);

        $this->assertSame([], $verdict['tick_now']);
        $this->assertContains(AtlasProjectLaneRuntimeInstanceScheduler::HOLD_STALE_CONTEXT, $verdict['held_lanes'][0]['reasons']);
    }

    public function test_max_concurrent_instance_cap_allows_exactly_n_instances(): void
    {
        $instances = [
            $this->laneInstance('a', 'p1'),
            $this->laneInstance('b', 'p2'),
            $this->laneInstance('c', 'p3'),
            $this->laneInstance('d', 'p4'),
        ];
        $cap = 2;
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => $cap]);

        $this->assertCount($cap, $verdict['tick_now'], 'exactly the cap number of instances must tick');
        $this->assertCount(count($instances) - $cap, $verdict['blocked_lanes']);
        foreach ($verdict['blocked_lanes'] as $blocked) {
            $this->assertContains(AtlasProjectLaneRuntimeInstanceScheduler::HOLD_MAX_PARALLEL_REACHED, $blocked['reasons']);
        }
    }

    public function test_cross_project_leak_is_explicitly_blocked_with_named_hold(): void
    {
        // Two different projects claim the same write root → second one must be explicitly blocked.
        $verdict = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('lane-x', 'project-1', ['allowed_roots' => ['shared/atlas/src']]),
            $this->laneInstance('lane-y', 'project-2', ['allowed_roots' => ['shared/atlas/src']]),
        ], ['max_parallel_lanes' => 5]);

        $this->assertSame('lane-x', $verdict['tick_now'][0]['lane_id']);
        $this->assertSame('lane-y', $verdict['held_lanes'][0]['lane_id']);
        $this->assertContains(
            AtlasProjectLaneRuntimeInstanceScheduler::HOLD_WRITE_ROOT_LEAK,
            $verdict['held_lanes'][0]['reasons'],
            'second project claiming the same write root must be held with cross_project_write_root_leak',
        );
    }

    public function test_scheduler_hash_is_deterministic_for_identical_input(): void
    {
        $instances = [$this->laneInstance('a'), $this->laneInstance('b')];
        $a = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);
        $b = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);

        $this->assertSame($a['scheduler_hash'], $b['scheduler_hash']);
    }

    // ── fairness_facts ────────────────────────────────────────────────────────

    public function test_fairness_facts_key_exists_with_required_fields(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan(
            [$this->laneInstance('a', 'p1'), $this->laneInstance('b', 'p2')],
            ['max_parallel_lanes' => 2],
        );

        $this->assertArrayHasKey('fairness_facts', $r);
        foreach (['per_project_tick_allocation', 'held_duration_hint', 'starvation_risk_lanes',
                  'fairness_reason', 'next_lane_to_unblock'] as $key) {
            $this->assertArrayHasKey($key, $r['fairness_facts'], "fairness_facts must contain {$key}");
        }
    }

    public function test_per_project_tick_allocation_counts_ticks_per_project(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'proj-A'),
            $this->laneInstance('b', 'proj-A'),
            $this->laneInstance('c', 'proj-B'),
        ], ['max_parallel_lanes' => 3]);

        $alloc = $r['fairness_facts']['per_project_tick_allocation'];
        $this->assertSame(2, $alloc['proj-A']);
        $this->assertSame(1, $alloc['proj-B']);
    }

    public function test_starvation_risk_lanes_are_those_blocked_by_capacity(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p1'),
            $this->laneInstance('b', 'p2'),
            $this->laneInstance('c', 'p3'),
        ], ['max_parallel_lanes' => 2]);

        // lane 'c' is blocked by max_parallel_reached → starvation risk.
        $this->assertContains('c', $r['fairness_facts']['starvation_risk_lanes']);
        $this->assertCount(1, $r['fairness_facts']['starvation_risk_lanes']);
    }

    public function test_next_lane_to_unblock_is_first_starvation_risk_lane(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p1'),
            $this->laneInstance('b', 'p2'),
            $this->laneInstance('c', 'p3'),
        ], ['max_parallel_lanes' => 2]);

        $this->assertSame('c', $r['fairness_facts']['next_lane_to_unblock']);
    }

    public function test_next_lane_to_unblock_is_null_when_no_starvation_risk(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan(
            [$this->laneInstance('a', 'p1'), $this->laneInstance('b', 'p2')],
            ['max_parallel_lanes' => 5],
        );

        $this->assertNull($r['fairness_facts']['next_lane_to_unblock']);
        $this->assertSame([], $r['fairness_facts']['starvation_risk_lanes']);
    }

    public function test_held_duration_hint_is_persistent_for_safety_stop(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan(
            [$this->laneInstance('a')],
            ['max_parallel_lanes' => 5, 'lane_health' => ['a' => ['safety_stop' => true]]],
        );

        $hints = $r['fairness_facts']['held_duration_hint'];
        $this->assertArrayHasKey('a', $hints);
        $this->assertSame('persistent_until_resolved', $hints['a']);
    }

    public function test_held_duration_hint_is_transient_for_stale_heartbeat(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan(
            [$this->laneInstance('a')],
            ['max_parallel_lanes' => 5, 'heartbeat_staleness_seconds' => 60,
             'lane_health' => ['a' => ['heartbeat_age_seconds' => 600]]],
        );

        $hints = $r['fairness_facts']['held_duration_hint'];
        $this->assertArrayHasKey('a', $hints);
        $this->assertSame('transient_resolves_when_condition_clears', $hints['a']);
    }

    public function test_fairness_reason_is_non_empty_and_mentions_scheduled_count(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p1'),
            $this->laneInstance('b', 'p2'),
            $this->laneInstance('c', 'p3'),
        ], ['max_parallel_lanes' => 2]);

        $reason = $r['fairness_facts']['fairness_reason'];
        $this->assertIsString($reason);
        $this->assertNotEmpty($reason);
        $this->assertStringContainsString('2', $reason); // 2 scheduled
    }

    public function test_fairness_facts_included_in_deterministic_hash(): void
    {
        $instances = [$this->laneInstance('x', 'p1'), $this->laneInstance('y', 'p2')];
        $r1 = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);
        $r2 = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan($instances, ['max_parallel_lanes' => 2]);

        $this->assertSame($r1['scheduler_hash'], $r2['scheduler_hash'],
            'hash must remain deterministic with fairness_facts included');
    }

    // ── starvation fairness ───────────────────────────────────────────────────

    public function test_starved_lane_by_count_ranks_ahead_of_equal_urgency_fresh_lane(): void
    {
        // 'fresh' and 'starved' have the same urgency, but 'starved' has starvation_count >= threshold.
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('fresh', 'p1'),
            $this->laneInstance('starved', 'p2'),
        ], [
            'max_parallel_lanes' => 1,
            'lane_health' => [
                'fresh'   => ['urgency' => 5, 'starvation_count' => 0],
                'starved' => ['urgency' => 5, 'starvation_count' => AtlasProjectLaneRuntimeInstanceScheduler::STARVATION_COUNT_THRESHOLD],
            ],
        ]);

        $this->assertSame('starved', $r['tick_now'][0]['lane_id'],
            'starved lane must rank ahead of equally-urgent fresh lane');
        $this->assertSame('fresh', $r['blocked_lanes'][0]['lane_id']);
    }

    public function test_starved_lane_by_tick_age_ranks_ahead_of_equal_urgency_fresh_lane(): void
    {
        $threshold = AtlasProjectLaneRuntimeInstanceScheduler::STARVATION_TICK_AGE_THRESHOLD_SECONDS;
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('fresh', 'p1'),
            $this->laneInstance('aged', 'p2'),
        ], [
            'max_parallel_lanes' => 1,
            'lane_health' => [
                'fresh' => ['urgency' => 4, 'time_since_last_tick_seconds' => 0],
                'aged'  => ['urgency' => 4, 'time_since_last_tick_seconds' => $threshold],
            ],
        ]);

        $this->assertSame('aged', $r['tick_now'][0]['lane_id'],
            'long-idle lane must rank ahead of equally-urgent fresh lane');
    }

    public function test_high_urgency_still_beats_starved_low_urgency_lane(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('low-starved', 'p1'),
            $this->laneInstance('high-fresh', 'p2'),
        ], [
            'max_parallel_lanes' => 1,
            'lane_health' => [
                'low-starved' => ['urgency' => 2, 'starvation_count' => 10],
                'high-fresh'  => ['urgency' => 9],
            ],
        ]);

        $this->assertSame('high-fresh', $r['tick_now'][0]['lane_id'],
            'higher urgency must still win over a starved lower-urgency lane');
    }

    public function test_tick_now_fairness_reasons_present_for_each_scheduled_lane(): void
    {
        $r = (new AtlasProjectLaneRuntimeInstanceScheduler)->plan([
            $this->laneInstance('a', 'p1'),
            $this->laneInstance('b', 'p2'),
        ], [
            'max_parallel_lanes' => 2,
            'lane_health' => [
                'a' => ['urgency' => 3, 'starvation_count' => AtlasProjectLaneRuntimeInstanceScheduler::STARVATION_COUNT_THRESHOLD],
                'b' => ['urgency' => 5],
            ],
        ]);

        $reasons = $r['fairness_facts']['tick_now_fairness_reasons'];
        $this->assertArrayHasKey('a', $reasons);
        $this->assertArrayHasKey('b', $reasons);
        $this->assertNotEmpty($reasons['a']);
        $this->assertNotEmpty($reasons['b']);
        // Starved lane should report starvation_count in its reasons.
        $aReasonsStr = implode(',', $reasons['a']);
        $this->assertStringContainsString('starvation_count', $aReasonsStr);
    }
}
