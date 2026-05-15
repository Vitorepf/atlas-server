<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentParallelismPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
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
}
