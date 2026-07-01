<?php

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentDispatchPlannerBatchPlanner;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryRepository;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Autonomos can prove it can plan without executing: batch planner produces planned and
 * blocked dispatches, refuses tasks with scope/governance blockers, selects the first eligible
 * agent with free slots after ordering, optionally attaches dry-run receipt ids, and always emits
 * dispatch_allowed=false (and every other runtime flag false).
 */
final class AgentDispatchPlannerBatchPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function seedTask(string $id, int $priority): void
    {
        (new AgentControlPlaneTaskPacketQueueRepository)->enqueue([
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
        ], ['priority' => $priority]);
    }

    private function seedAgent(string $id, array $capabilities, int $maxParallel = 2): void
    {
        (new AgentRuntimeRegistryRepository)->register([
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

    public function test_produces_planned_and_blocked_dispatches(): void
    {
        $this->seedTask('tp-planned', 5);
        $this->seedTask('tp-blocked', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection'], 1);

        $plan = (new AgentDispatchPlannerBatchPlanner)->plan(['max_dispatches' => 10]);

        self::assertSame(1, $plan['planned_dispatch_count']);
        self::assertGreaterThanOrEqual(1, $plan['blocked_dispatch_count']);
    }

    public function test_refuses_tasks_with_governance_blockers(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);

        $plan = (new AgentDispatchPlannerBatchPlanner)->plan(['governance' => ['kill_switch_state' => 'tripped']]);

        self::assertSame('blocked', $plan['status']);
        self::assertContains('kill_switch_tripped', $plan['global_blockers']);
    }

    public function test_selects_the_first_eligible_agent_with_free_slots_after_ordering(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);

        $plan = (new AgentDispatchPlannerBatchPlanner)->plan();

        self::assertSame('planned', $plan['status']);
        self::assertSame('agent-a', $plan['planned_dispatches'][0]['agent_id']);
    }

    public function test_optionally_attaches_dry_run_receipt_ids(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);

        $plan = (new AgentDispatchPlannerBatchPlanner)->plan(['persist_receipts' => true]);

        self::assertNotEmpty($plan['planned_dispatches'][0]['receipt_id']);
        self::assertNotEmpty($plan['planned_dispatches'][0]['receipt_hash']);
    }

    public function test_emits_dispatch_allowed_false_across_all_runtime_flags(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);

        $plan = (new AgentDispatchPlannerBatchPlanner)->plan();

        foreach (['dispatch_allowed', 'runtime_execution_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'claim_real_allowed'] as $flag) {
            self::assertFalse($plan[$flag], "{$flag} must be false");
        }
        foreach ((new AgentDispatchPlannerBatchPlanner)->runtimeFlags() as $key => $value) {
            self::assertFalse($value, "runtimeFlags()[{$key}] must be false");
        }
    }

    public function test_batch_hash_is_stable_for_the_same_input(): void
    {
        $this->seedTask('tp-1', 5);
        $this->seedAgent('agent-a', ['code_edit', 'evidence_collection']);
        $planner = new AgentDispatchPlannerBatchPlanner;

        $a = $planner->plan(['batch_id' => 'fixed-batch']);
        $b = $planner->plan(['batch_id' => 'fixed-batch']);

        self::assertSame($a['batch_hash'], $b['batch_hash']);
    }
}
