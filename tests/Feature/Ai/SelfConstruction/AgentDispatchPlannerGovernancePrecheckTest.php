<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerGovernancePrecheck;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentDispatchPlannerGovernancePrecheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_governance_precheck.v1', AgentDispatchPlannerGovernancePrecheck::SCHEMA_VERSION);
        $this->assertContains('armed', AgentDispatchPlannerGovernancePrecheck::KILL_SWITCH_STATES);
        $this->assertContains('tripped', AgentDispatchPlannerGovernancePrecheck::KILL_SWITCH_STATES);
    }

    public function test_ok_when_low_risk_and_armed(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['global_blockers']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_kill_switch_tripped_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['kill_switch_state' => 'tripped', 'use_live_quarantine' => false],
        );
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('kill_switch_tripped', $result['global_blockers']);
    }

    public function test_kill_switch_disarmed_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['kill_switch_state' => 'disarmed', 'use_live_quarantine' => false],
        );
        $this->assertContains('kill_switch_disarmed', $result['global_blockers']);
    }

    public function test_budget_gate_not_green_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['budget_gate_state' => 'red', 'use_live_quarantine' => false],
        );
        $this->assertContains('budget_gate_not_green:red', $result['global_blockers']);
        $this->assertFalse($result['budget_gate_ok']);
    }

    public function test_self_programming_requested_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['self_programming_requested' => true, 'use_live_quarantine' => false],
        );
        $this->assertContains('self_programming_requested', $result['global_blockers']);
    }

    public function test_high_risk_requires_operator_approval(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'high', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $this->assertSame('blocked', $result['status']);
        $taskBlocker = $result['task_blockers'][0];
        $this->assertFalse($taskBlocker['is_governance_clear']);
        $this->assertContains('operator_approval_required', $taskBlocker['reasons']);
    }

    public function test_high_risk_with_operator_approval_clears(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'high', false)],
            [$this->agent('agent-a', 'available')],
            ['operator_approved_task_ids' => ['tp'], 'use_live_quarantine' => false],
        );
        $taskBlocker = $result['task_blockers'][0];
        $this->assertTrue($taskBlocker['is_governance_clear']);
    }

    public function test_evidence_refs_missing_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', true)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $taskBlocker = $result['task_blockers'][0];
        $this->assertContains('evidence_refs_missing', $taskBlocker['reasons']);
    }

    public function test_quarantined_agent_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-q', 'available')],
            ['quarantined_agents' => ['agent-q'], 'use_live_quarantine' => false],
        );
        $agentBlocker = $result['agent_blockers'][0];
        $this->assertContains('agent_quarantined', $agentBlocker['reasons']);
    }

    public function test_disabled_agent_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-d', 'disabled')],
            ['use_live_quarantine' => false],
        );
        $agentBlocker = $result['agent_blockers'][0];
        $this->assertContains('agent_disabled', $agentBlocker['reasons']);
    }

    public function test_governance_hash_stable(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $a = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $b = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $this->assertSame($a['governance_hash'], $b['governance_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['governance_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_invalid_kill_switch_state_normalized(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['kill_switch_state' => 'rogue', 'use_live_quarantine' => false],
        );
        $this->assertSame('unknown', $result['kill_switch_state']);
    }

    public function test_critical_risk_blocks_without_approval(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'critical', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $taskBlocker = $result['task_blockers'][0];
        $this->assertContains('operator_approval_required', $taskBlocker['reasons']);
    }

    public function test_runtime_safety_in_envelope(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
    }

    public function test_clear_counts_present(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false), $this->task('tp2', 'high', false)],
            [$this->agent('agent-a', 'available'), $this->agent('agent-b', 'disabled')],
            ['use_live_quarantine' => false],
        );
        $this->assertSame(1, $result['clear_task_count']);
        $this->assertSame(1, $result['clear_agent_count']);
    }

    public function test_budget_gate_yellow_blocks(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['budget_gate_state' => 'yellow', 'use_live_quarantine' => false],
        );
        $this->assertFalse($result['budget_gate_ok']);
        $this->assertContains('budget_gate_not_green:yellow', $result['global_blockers']);
    }

    public function test_armed_kill_switch_default_ok(): void
    {
        $svc = new AgentDispatchPlannerGovernancePrecheck;
        $result = $svc->precheck(
            [$this->task('tp', 'low', false)],
            [$this->agent('agent-a', 'available')],
            ['use_live_quarantine' => false],
        );
        $this->assertSame('armed', $result['kill_switch_state']);
    }

    /**
     * @return array<string, mixed>
     */
    private function task(string $id, string $risk, bool $evidenceRequired): array
    {
        return [
            'task_packet_id' => $id,
            'risk_level' => $risk,
            'evidence_required' => $evidenceRequired,
            'evidence_refs' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function agent(string $id, string $status): array
    {
        return [
            'agent_id' => $id,
            'status' => $status,
            'capabilities' => ['code_edit'],
        ];
    }
}
