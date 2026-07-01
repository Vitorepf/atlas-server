<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerGovernancePrecheck;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Focused contract test: proves the global kill_switch blocks dispatch batches, tasks missing
 * governance approval or a safe scope are blocked, agents missing runtime readiness or capacity
 * are blocked, clear task/agent counts drive status ok, status is blocked when global_blockers
 * are present even when every individual task/agent looks clear, and governance_hash is
 * deterministic across equivalent input.
 */
final class AgentDispatchPlannerGovernancePrecheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function task(string $id, string $risk = 'low', bool $evidenceRequired = false): array
    {
        return [
            'task_packet_id' => $id,
            'risk_level' => $risk,
            'evidence_required' => $evidenceRequired,
            'evidence_refs' => [],
        ];
    }

    private function agent(string $id, string $status = 'available'): array
    {
        return [
            'agent_id' => $id,
            'status' => $status,
            'capabilities' => ['code_edit'],
        ];
    }

    public function test_global_kill_switch_tripped_blocks_the_whole_batch(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp')],
            [$this->agent('agent-a')],
            ['kill_switch_state' => 'tripped', 'use_live_quarantine' => false],
        );

        self::assertSame('blocked', $result['status']);
        self::assertContains('kill_switch_tripped', $result['global_blockers']);
    }

    public function test_task_missing_operator_approval_for_high_risk_is_blocked(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp', 'high')],
            [$this->agent('agent-a')],
            ['use_live_quarantine' => false],
        );

        $blocker = $result['task_blockers'][0];
        self::assertFalse($blocker['is_governance_clear']);
        self::assertContains('operator_approval_required', $blocker['reasons']);
    }

    public function test_task_missing_evidence_refs_is_blocked(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp', 'low', true)],
            [$this->agent('agent-a')],
            ['use_live_quarantine' => false],
        );

        $blocker = $result['task_blockers'][0];
        self::assertFalse($blocker['is_governance_clear']);
        self::assertContains('evidence_refs_missing', $blocker['reasons']);
    }

    public function test_agent_missing_runtime_readiness_is_blocked(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp')],
            [$this->agent('agent-d', 'disabled')],
            ['use_live_quarantine' => false],
        );

        $blocker = $result['agent_blockers'][0];
        self::assertFalse($blocker['is_governance_clear']);
        self::assertContains('agent_disabled', $blocker['reasons']);
    }

    public function test_quarantined_agent_capacity_is_blocked(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp')],
            [$this->agent('agent-q')],
            ['quarantined_agents' => ['agent-q'], 'use_live_quarantine' => false],
        );

        $blocker = $result['agent_blockers'][0];
        self::assertFalse($blocker['is_governance_clear']);
        self::assertContains('agent_quarantined', $blocker['reasons']);
    }

    public function test_clear_task_and_agent_counts_drive_status_ok(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp')],
            [$this->agent('agent-a')],
            ['use_live_quarantine' => false],
        );

        self::assertSame('ok', $result['status']);
        self::assertSame(1, $result['clear_task_count']);
        self::assertSame(1, $result['clear_agent_count']);
    }

    public function test_status_is_blocked_when_global_blockers_present_even_though_tasks_and_agents_look_clear(): void
    {
        $result = (new AgentDispatchPlannerGovernancePrecheck)->precheck(
            [$this->task('tp')],
            [$this->agent('agent-a')],
            ['kill_switch_state' => 'tripped', 'use_live_quarantine' => false],
        );

        // Individual task/agent rows are both fully clear...
        self::assertTrue($result['task_blockers'][0]['is_governance_clear']);
        self::assertTrue($result['agent_blockers'][0]['is_governance_clear']);
        self::assertSame(1, $result['clear_task_count']);
        self::assertSame(1, $result['clear_agent_count']);

        // ...but the global kill-switch blocker still fails the whole batch closed.
        self::assertSame('blocked', $result['status']);
        self::assertNotSame([], $result['global_blockers']);
    }

    public function test_governance_hash_is_deterministic_for_equivalent_input(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $a = $svc->precheck([$this->task('tp')], [$this->agent('agent-a')], ['use_live_quarantine' => false]);
        $b = $svc->precheck([$this->task('tp')], [$this->agent('agent-a')], ['use_live_quarantine' => false]);

        self::assertSame($a['governance_hash'], $b['governance_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['governance_hash']);
    }

    public function test_runtime_flags_forbid_dispatch_provider_calls_token_spend_claiming_and_persistence(): void
    {
        $flags = (new AgentDispatchPlannerGovernancePrecheck)->runtimeFlags();

        self::assertFalse($flags['dispatch_allowed']);
        self::assertFalse($flags['provider_call_allowed']);
        self::assertFalse($flags['token_spend_allowed']);
        self::assertFalse($flags['claim_real_allowed']);
        self::assertFalse($flags['ledger_write_allowed']);
        self::assertFalse($flags['runtime_execution_allowed']);
        self::assertFalse($flags['self_programming_allowed']);
    }
}
