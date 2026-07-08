<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneMultiAgentParallelismPlanner;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentParallelismPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_control_plane_multi_agent_parallelism_plan.v1', AgentControlPlaneMultiAgentParallelismPlanner::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_control_plane_multi_agent_parallelism_plan', AgentControlPlaneMultiAgentParallelismPlanner::MODE);
    }

    public function test_no_conflict_parallel_allowed(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertSame('planned', $plan['status']);
        $this->assertTrue($plan['parallelism_allowed']);
        $this->assertSame(0, $plan['blocked_pair_count']);
    }

    public function test_write_conflict_blocked(): void
    {
        $packets = [$this->packet(['a.php', 'b.php']), $this->packet(['b.php', 'c.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertSame('planned_blocked', $plan['status']);
        $this->assertFalse($plan['parallelism_allowed']);
        $this->assertSame(1, $plan['blocked_pair_count']);
        $this->assertContains('b.php', $plan['blocked_pairs'][0]['write_overlap']);
    }

    public function test_read_only_overlap_allowed(): void
    {
        // we treat overlap on allowed_files as a write overlap by default; this tests
        // disjoint allowed paths still parallelizable.
        $packets = [$this->packet(['x.php']), $this->packet(['y.php']), $this->packet(['z.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertTrue($plan['parallelism_allowed']);
    }

    public function test_blocked_pairs_reported(): void
    {
        $packets = [$this->packet(['shared.php']), $this->packet(['shared.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertSame(1, $plan['blocked_pair_count']);
        $this->assertNotEmpty($plan['blocked_pairs']);
    }

    public function test_scheduling_plan_present(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php']), $this->packet(['c.php']), $this->packet(['d.php']), $this->packet(['e.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets, ['max_parallel_agents' => 2]);
        $this->assertSame(2, $plan['scheduling_plan']['max_parallel_agents']);
        $this->assertGreaterThanOrEqual(3, $plan['scheduling_plan']['wave_count']);
    }

    public function test_isolation_plan_present(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertSame('simulated_worktree_per_packet', $plan['isolation_plan']['isolation_strategy']);
        $this->assertFalse($plan['isolation_plan']['runtime_enabled']);
    }

    public function test_hash_stable(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $svc = new AgentControlPlaneMultiAgentParallelismPlanner;
        $a = $svc->plan($packets);
        $b = $svc->plan($packets);
        $this->assertSame($a['parallelism_hash'], $b['parallelism_hash']);
    }

    public function test_no_packets_blocks(): void
    {
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan([]);
        $this->assertSame('planned_blocked', $plan['status']);
        $this->assertContains('no_task_packets', $plan['blocking_reasons']);
    }

    public function test_runtime_flags_false(): void
    {
        $packets = [$this->packet(['a.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertTrue($plan['runtime_disabled']);
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['ledger_write_allowed']);
    }

    public function test_cli_status_returns_payload(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-multi-agent-parallelism-planner-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.self_construction_agent_control_plane_multi_agent_parallelism_planner_status.v1', $payload['schema_version']);
        $this->assertGreaterThanOrEqual(2, (int) data_get($payload, 'agent_control_plane_multi_agent_parallelism_planner_status.agent_count'));
    }

    public function test_cli_quartet_works(): void
    {
        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-multi-agent-parallelism-planner-{$stage}" => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame(
                "atlas.self_construction_agent_control_plane_multi_agent_parallelism_planner_{$stageKey}.v1",
                $payload['schema_version'],
            );
        }
    }

    public function test_merge_policy_present(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        $this->assertSame('serialized_merge_via_operator_review', $plan['merge_policy']['strategy']);
        $this->assertFalse($plan['merge_policy']['auto_merge_runtime_enabled']);
    }

    public function test_full_guarantee_set(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        foreach ([
            'multi_agent_parallelism_planner_does_not_start_codex',
            'multi_agent_parallelism_planner_does_not_call_codex_cli_or_app',
            'multi_agent_parallelism_planner_does_not_spawn_subprocess',
            'multi_agent_parallelism_planner_does_not_invoke_adapter',
            'multi_agent_parallelism_planner_does_not_call_provider',
            'multi_agent_parallelism_planner_does_not_dispatch_work',
            'multi_agent_parallelism_planner_does_not_spend_tokens',
            'multi_agent_parallelism_planner_does_not_enable_self_programming',
            'multi_agent_parallelism_planner_does_not_write_ledger',
            'multi_agent_parallelism_planner_does_not_persist_leases',
            'multi_agent_parallelism_planner_does_not_mutate_pointer',
        ] as $expected) {
            $this->assertContains($expected, $plan['non_execution_guarantees']);
        }
    }

    public function test_payload_fully_shaped(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php']), $this->packet(['c.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);
        foreach ([
            'schema_version', 'mode', 'parallelism_plan_id', 'generated_at', 'status',
            'agent_count', 'task_packet_count', 'parallelism_allowed', 'conflict_matrix',
            'lease_plan', 'scheduling_plan', 'isolation_plan', 'merge_policy',
            'max_parallel_agents', 'blocked_pairs', 'blocked_pair_count', 'blocking_reasons',
            'read_only', 'runtime_disabled', 'dispatch_allowed', 'provider_call_allowed',
            'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed',
            'persistence_allowed', 'non_execution_guarantees', 'human_summary', 'parallelism_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $plan, "Missing $key");
        }
        foreach ($plan['lease_plan'] as $lease) {
            $this->assertArrayHasKey('task_packet_id', $lease);
            $this->assertArrayHasKey('lease_id_simulated', $lease);
            $this->assertFalse($lease['lease_runtime_enabled']);
            $this->assertSame('simulated_worktree', $lease['isolation_strategy']);
        }
        $this->assertContains('multi_agent_parallelism_planner_does_not_persist_leases', $plan['non_execution_guarantees']);
        $this->assertContains('multi_agent_parallelism_planner_does_not_dispatch_work', $plan['non_execution_guarantees']);
        $this->assertContains('multi_agent_parallelism_planner_does_not_call_provider', $plan['non_execution_guarantees']);
        $this->assertContains('multi_agent_parallelism_planner_does_not_start_codex', $plan['non_execution_guarantees']);
        $this->assertContains('multi_agent_parallelism_planner_does_not_mutate_pointer', $plan['non_execution_guarantees']);
        $this->assertContains('multi_agent_parallelism_planner_does_not_write_ledger', $plan['non_execution_guarantees']);
    }

    // ── lanes / blocked_tasks / conflict_reason ─────────────────────────────────

    public function test_lanes_group_non_conflicting_tasks_into_a_single_wave(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertArrayHasKey('lanes', $plan);
        $this->assertCount(1, $plan['lanes']);
        $this->assertSame('lane-1', $plan['lanes'][0]['lane_id']);
        $this->assertCount(2, $plan['lanes'][0]['selected_tasks']);
    }

    public function test_conflicting_tasks_are_serialized_into_separate_lanes(): void
    {
        $packets = [$this->packet(['shared.php']), $this->packet(['shared.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertGreaterThanOrEqual(2, count($plan['lanes']));
    }

    public function test_conflict_reason_present_on_write_overlap_pair(): void
    {
        $packets = [$this->packet(['shared.php']), $this->packet(['shared.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertSame('write_set_overlap', $plan['blocked_pairs'][0]['conflict_reason']);
        $this->assertSame('write_set_overlap', $plan['conflict_matrix'][0]['conflict_reason']);
    }

    public function test_conflict_reason_is_null_for_non_conflicting_pair(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertNull($plan['conflict_matrix'][0]['conflict_reason']);
    }

    public function test_blocked_tasks_lists_not_planned_packets_with_reason(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $packets[1]['status'] = 'draft';

        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertNotEmpty($plan['blocked_tasks']);
        $this->assertSame('not_planned', $plan['blocked_tasks'][0]['conflict_reason']);
    }

    public function test_blocked_tasks_empty_when_all_packets_planned(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertSame([], $plan['blocked_tasks']);
    }

    // ── AC: runtime health throttle ─────────────────────────────────────────

    public function test_high_give_back_rate_throttles_parallelism_on_non_overlapping_packets(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets, [
            'health_signals' => [
                'queue_depth' => 10,
                'servable_count' => 5,
                'active_leases' => 2,
                'conflict_free_scope_ratio' => 0.9,
                'lock_contention' => 0.1,
                'give_back_rate' => 0.50, // above 0.30 threshold
                'worker_quality_scores' => [8.0, 7.5],
                'poison_pressure' => 0.0,
            ],
        ]);

        $this->assertNotEmpty($plan['throttle_reasons']);
        $this->assertTrue($plan['health_signals_consulted']);
        // Parallelism must be reduced below the max.
        $this->assertLessThan($plan['max_parallel_agents'], $plan['recommended_parallelism']);
    }

    public function test_healthy_signals_preserve_parallelism_on_non_overlapping_packets(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets, [
            'health_signals' => [
                'queue_depth' => 10,
                'servable_count' => 5,
                'active_leases' => 2,
                'conflict_free_scope_ratio' => 0.9,
                'lock_contention' => 0.0,
                'give_back_rate' => 0.0,
                'worker_quality_scores' => [9.0, 8.5],
                'poison_pressure' => 0.0,
            ],
        ]);

        $this->assertTrue($plan['parallelism_allowed']);
        $this->assertSame([], $plan['throttle_reasons']);
        $this->assertTrue($plan['health_signals_consulted']);
    }

    public function test_no_health_signals_preserves_existing_behavior(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertTrue($plan['parallelism_allowed']);
        $this->assertFalse($plan['health_signals_consulted']);
        $this->assertSame([], $plan['throttle_reasons']);
    }

    public function test_high_lock_contention_throttles_parallelism(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets, [
            'health_signals' => [
                'queue_depth' => 5,
                'servable_count' => 3,
                'active_leases' => 2,
                'conflict_free_scope_ratio' => 0.8,
                'lock_contention' => 0.40, // above 0.25 threshold
                'give_back_rate' => 0.0,
                'worker_quality_scores' => [8.0, 7.0],
                'poison_pressure' => 0.0,
            ],
        ]);

        $this->assertNotEmpty($plan['throttle_reasons']);
    }

    public function test_high_poison_pressure_throttles_parallelism(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets, [
            'health_signals' => [
                'queue_depth' => 5,
                'servable_count' => 3,
                'active_leases' => 2,
                'conflict_free_scope_ratio' => 0.8,
                'lock_contention' => 0.0,
                'give_back_rate' => 0.0,
                'worker_quality_scores' => [8.0, 7.0],
                'poison_pressure' => 0.30, // above 0.20 threshold
            ],
        ]);

        $this->assertNotEmpty($plan['throttle_reasons']);
    }

    public function test_plan_includes_recommended_parallelism_field(): void
    {
        $packets = [$this->packet(['a.php']), $this->packet(['b.php'])];
        $plan = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan($packets);

        $this->assertArrayHasKey('recommended_parallelism', $plan);
        $this->assertIsInt($plan['recommended_parallelism']);
        $this->assertGreaterThanOrEqual(1, $plan['recommended_parallelism']);
    }

    /**
     * @param  array<int, string>  $allowed
     * @return array<string, mixed>
     */
    private function packet(array $allowed): array
    {
        return (new AgentControlPlaneTaskPacketBuilder)->build([
            'objective' => 'multi-agent packet',
            'operator_id' => 'tester',
            'allowed_files' => $allowed,
            'scope_in' => $allowed,
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['x'],
        ]);
    }

    // ── AC2: tasks sharing implementation files are placed in separate lanes ──

    public function test_tasks_sharing_implementation_files_are_in_separate_lanes_with_conflict_reason(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $sharedFile = 'app/Services/Shared.php';
        $p1 = $builder->build(['task_packet_id' => 'p1', 'allowed_files' => [$sharedFile, 'tests/Unit/SharedTest.php'], 'objective' => 'o1', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p2 = $builder->build(['task_packet_id' => 'p2', 'allowed_files' => [$sharedFile, 'tests/Unit/OtherTest.php'], 'objective' => 'o2', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);

        $result = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan([$p1, $p2]);

        $this->assertNotEmpty($result['blocked_pairs']);
        $this->assertSame('write_set_overlap', $result['blocked_pairs'][0]['conflict_reason']);
    }

    // ── AC3: high-risk or high-proof-cost tasks reduce recommended_parallelism ──

    public function test_high_risk_tasks_reduce_recommended_parallelism(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $p1 = $builder->build(['task_packet_id' => 'p1', 'allowed_files' => ['app/A.php'], 'objective' => 'o1', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p2 = $builder->build(['task_packet_id' => 'p2', 'allowed_files' => ['app/B.php'], 'objective' => 'o2', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p1['risk_level'] = 'high';
        $p2['risk_level'] = 'high';

        $result = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan([$p1, $p2]);

        $this->assertLessThanOrEqual(1, $result['recommended_parallelism']);
    }

    public function test_high_proof_cost_tasks_reduce_recommended_parallelism(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $p1 = $builder->build(['task_packet_id' => 'p1', 'allowed_files' => ['app/A.php'], 'objective' => 'o1', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p2 = $builder->build(['task_packet_id' => 'p2', 'allowed_files' => ['app/B.php'], 'objective' => 'o2', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p1['proof_cost'] = 'high';
        $p2['proof_cost'] = 'high';

        $result = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan([$p1, $p2]);

        $this->assertLessThanOrEqual(1, $result['recommended_parallelism']);
    }

    // ── AC4: low-risk disjoint tasks can be grouped into parallel lanes ──

    public function test_low_risk_disjoint_tasks_grouped_into_parallel_lanes(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $p1 = $builder->build(['task_packet_id' => 'p1', 'allowed_files' => ['app/A.php'], 'objective' => 'o1', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p2 = $builder->build(['task_packet_id' => 'p2', 'allowed_files' => ['app/B.php'], 'objective' => 'o2', 'acceptance_criteria' => ['ok'], 'required_evidence' => ['x']]);
        $p1['risk_level'] = 'low';
        $p2['risk_level'] = 'low';
        $p1['worker_skill_hint'] = 'php';
        $p2['worker_skill_hint'] = 'php';

        $result = (new AgentControlPlaneMultiAgentParallelismPlanner)->plan([$p1, $p2]);

        $this->assertTrue($result['parallelism_allowed']);
        $this->assertGreaterThanOrEqual(2, $result['recommended_parallelism']);
    }
}
