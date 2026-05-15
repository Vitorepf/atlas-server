<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentDispatchPlannerBatchPlanner;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryQuarantineRepository;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerBatchPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_batch_plan.v1', AgentDispatchPlannerBatchPlanner::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_dispatch_planner_batch_plan', AgentDispatchPlannerBatchPlanner::MODE);
    }

    public function test_empty_state_blocked(): void
    {
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan();
        $this->assertSame('blocked', $plan['status']);
        $this->assertSame(0, $plan['planned_dispatch_count']);
        $this->assertFalse($plan['dispatch_allowed']);
    }

    public function test_plans_dispatch_when_task_and_agent_match(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan();
        $this->assertSame('planned', $plan['status']);
        $this->assertSame(1, $plan['planned_dispatch_count']);
        $this->assertSame('tp-1', $plan['planned_dispatches'][0]['task_packet_id']);
        $this->assertSame('agent-a', $plan['planned_dispatches'][0]['agent_id']);
    }

    public function test_kill_switch_tripped_blocks_all(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['governance' => ['kill_switch_state' => 'tripped']]);
        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('kill_switch_tripped', $plan['global_blockers']);
    }

    public function test_quarantined_agent_excluded(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-q', ['code_edit', 'evidence_collection']);
        (new AgentRuntimeRegistryQuarantineRepository)->quarantine('agent-q', [
            'code' => 'operator_disabled',
            'declared_by' => 'op',
        ]);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan();
        $this->assertSame('blocked', $plan['status']);
        $this->assertSame(0, $plan['planned_dispatch_count']);
        $this->assertSame(1, $plan['blocked_dispatch_count']);
    }

    public function test_persist_receipts_creates_records(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['persist_receipts' => true]);
        $this->assertSame('planned', $plan['status']);
        $this->assertNotEmpty($plan['planned_dispatches'][0]['receipt_id']);
        $this->assertNotEmpty($plan['planned_dispatches'][0]['receipt_hash']);
    }

    public function test_max_dispatches_caps_output(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedTask('tp-2', 5);
        $this->seedTask('tp-3', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection'], 3);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['max_dispatches' => 2]);
        $this->assertSame(2, $plan['planned_dispatch_count']);
    }

    public function test_batch_hash_stable(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $a = $planner->plan(['batch_id' => 'fixed-batch']);
        $b = $planner->plan(['batch_id' => 'fixed-batch']);
        $this->assertSame($a['batch_hash'], $b['batch_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['batch_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $planner = new AgentDispatchPlannerBatchPlanner;
        foreach ($planner->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_no_agent_blocks_task(): void
    {
        $this->seedTask('tp-1', 5);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan();
        $this->assertSame(1, $plan['blocked_dispatch_count']);
        $this->assertContains('no_eligible_agent', $plan['blocked_dispatches'][0]['reasons']);
    }

    public function test_capacity_respected_across_dispatches(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedTask('tp-2', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection'], 1);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['max_dispatches' => 10]);
        $this->assertSame(1, $plan['planned_dispatch_count']);
        $this->assertGreaterThanOrEqual(1, $plan['blocked_dispatch_count']);
    }

    public function test_dispatch_allowed_false_in_envelope(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan();
        $this->assertFalse($plan['dispatch_allowed']);
        $this->assertFalse($plan['runtime_execution_allowed']);
        $this->assertFalse($plan['provider_call_allowed']);
        $this->assertFalse($plan['token_spend_allowed']);
        $this->assertFalse($plan['self_programming_allowed']);
        $this->assertFalse($plan['ledger_write_allowed']);
        $this->assertFalse($plan['claim_real_allowed']);
    }

    public function test_self_programming_request_blocks(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['governance' => ['self_programming_requested' => true]]);
        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('self_programming_requested', $plan['global_blockers']);
    }

    public function test_budget_gate_red_blocks(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['governance' => ['budget_gate_state' => 'red']]);
        $this->assertSame('blocked', $plan['status']);
        $this->assertContains('budget_gate_not_green:red', $plan['global_blockers']);
    }

    public function test_planned_dispatch_lists_required_fields(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan();
        $dispatch = $plan['planned_dispatches'][0];
        $this->assertArrayHasKey('task_packet_id', $dispatch);
        $this->assertArrayHasKey('task_packet_hash', $dispatch);
        $this->assertArrayHasKey('agent_id', $dispatch);
        $this->assertArrayHasKey('matching_policy', $dispatch);
        $this->assertArrayHasKey('capability_match', $dispatch);
    }

    public function test_batch_id_returned(): void
    {
        $planner = new AgentDispatchPlannerBatchPlanner;
        $plan = $planner->plan(['batch_id' => 'b-test-1']);
        $this->assertSame('b-test-1', $plan['batch_id']);
    }

    private function seedTask(string $id, int $priority): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository;
        $repo->enqueue([
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'status' => 'claimable',
            'required_capabilities' => ['code_edit'],
            'risk_level' => 'low',
            'evidence_required' => true,
            'evidence_refs' => ['ev1.md'],
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'dry_run_only' => false,
        ], [
            'priority' => $priority,
        ]);
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    private function seedAgent(string $id, array $capabilities, int $maxParallel = 2): void
    {
        $repo = new AgentRuntimeRegistryRepository;
        $repo->register([
            'agent_id' => $id,
            'kind' => 'codex',
            'label' => $id,
            'status' => 'available',
            'capabilities' => $capabilities,
            'max_parallel_tasks' => $maxParallel,
            'current_task_count' => 0,
            'heartbeat_required' => true,
            'lease_supported' => true,
            'workspace_isolation_supported' => true,
            'evidence_required' => true,
        ]);
    }
}
