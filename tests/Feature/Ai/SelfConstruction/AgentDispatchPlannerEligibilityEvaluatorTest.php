<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentDispatchPlannerEligibilityEvaluator;
use Tests\TestCase;

final class AgentDispatchPlannerEligibilityEvaluatorTest extends TestCase
{
    public function test_constants_canonical(): void
    {
        $this->assertSame('atlas.self_construction.agent_dispatch_planner_eligibility.v1', AgentDispatchPlannerEligibilityEvaluator::SCHEMA_VERSION);
        $this->assertSame('read_only_agent_dispatch_planner_eligibility', AgentDispatchPlannerEligibilityEvaluator::MODE);
    }

    public function test_eligible_pair_when_capabilities_match(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'])],
            [$this->agent('agent-a', ['code_edit'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
        $this->assertSame(1, $result['eligible_pair_count']);
        $this->assertSame(0, $result['ineligible_pair_count']);
        $this->assertFalse($result['dispatch_allowed']);
    }

    public function test_capability_mismatch_rejects(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['cost_reporting'])],
            [$this->agent('agent-a', ['code_edit'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('capability_mismatch', $eval['reasons']);
    }

    public function test_capacity_full_rejects(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'])],
            [$this->agent('agent-a', ['code_edit'], ['max_parallel_tasks' => 1, 'current_task_count' => 1])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('capacity_full', $eval['reasons']);
    }

    public function test_quarantined_rejects(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'])],
            [$this->agent('agent-q', ['code_edit'])],
            ['quarantined_agents' => ['agent-q']],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('agent_quarantined', $eval['reasons']);
    }

    public function test_high_risk_requires_human_approval(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['risk_level' => 'high'])],
            [$this->agent('agent-a', ['code_edit'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('human_approval_required_for_risk:high', $eval['reasons']);
    }

    public function test_critical_risk_with_human_approval_accepts(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['risk_level' => 'critical'])],
            [$this->agent('agent-h', ['code_edit', 'human_approval'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
    }

    public function test_workspace_isolation_required(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['workspace_policy' => 'isolated'])],
            [$this->agent('agent-no-ws', ['code_edit'], ['workspace_isolation_supported' => false])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertContains('workspace_isolation_required', $eval['reasons']);
    }

    public function test_lease_support_required(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['requires_lease' => true])],
            [$this->agent('agent-no-lease', ['code_edit'], ['lease_supported' => false])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertContains('lease_support_required', $eval['reasons']);
    }

    public function test_dry_run_agent_requires_dry_run_only_task(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['dry_run_only' => false])],
            [$this->agent('agent-dr', ['code_edit'], ['kind' => 'dry_run_agent'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertContains('dry_run_agent_requires_dry_run_only_task', $eval['reasons']);
    }

    public function test_dry_run_only_capability_requires_dry_run_only_task(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['dry_run_only' => false])],
            [$this->agent('agent-do', ['code_edit', 'dry_run_only'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertContains('dry_run_only_capability_requires_dry_run_only_task', $eval['reasons']);
    }

    public function test_evidence_required_but_agent_not_ready(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['evidence_required' => true])],
            [$this->agent('agent-noev', ['code_edit'], ['evidence_required' => false])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertContains('evidence_required_but_agent_not_evidence_ready', $eval['reasons']);
    }

    public function test_eligibility_hash_stable(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $a = $svc->evaluate([$this->task('tp', ['code_edit'])], [$this->agent('agent-a', ['code_edit'])]);
        $b = $svc->evaluate([$this->task('tp', ['code_edit'])], [$this->agent('agent-a', ['code_edit'])]);
        $this->assertSame($a['eligibility_hash'], $b['eligibility_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['eligibility_hash']);
    }

    public function test_runtime_flags_helper(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        foreach ($svc->runtimeFlags() as $key => $value) {
            $this->assertFalse($value, "flag {$key} must be false");
        }
    }

    public function test_empty_required_capabilities_match(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', [])],
            [$this->agent('agent-a', ['code_edit'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertTrue($eval['is_eligible']);
    }

    public function test_status_disabled_rejected(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'])],
            [$this->agent('agent-d', ['code_edit'], ['status' => 'disabled'])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertFalse($eval['is_eligible']);
        $this->assertContains('agent_status_ineligible:disabled', $eval['reasons']);
    }

    public function test_invalid_risk_normalized_to_low(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'], ['risk_level' => 'rogue'])],
            [$this->agent('agent-a', ['code_edit'])],
        );
        $this->assertSame('low', $result['evaluation_matrix'][0]['risk_level']);
    }

    public function test_pair_total_counts(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp1', ['code_edit']), $this->task('tp2', ['code_edit'])],
            [$this->agent('agent-a', ['code_edit']), $this->agent('agent-b', ['code_edit'])],
        );
        $this->assertSame(4, $result['pair_total']);
        $this->assertSame(4, $result['eligible_pair_count']);
    }

    public function test_runtime_safety_in_envelope(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'])],
            [$this->agent('agent-a', ['code_edit'])],
        );
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['claim_real_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
    }

    public function test_free_slots_in_evaluation(): void
    {
        $svc = new AgentDispatchPlannerEligibilityEvaluator;
        $result = $svc->evaluate(
            [$this->task('tp', ['code_edit'])],
            [$this->agent('agent-a', ['code_edit'], ['max_parallel_tasks' => 3, 'current_task_count' => 1])],
        );
        $eval = $result['evaluation_matrix'][0]['evaluations'][0];
        $this->assertSame(2, $eval['free_slots']);
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function task(string $id, array $required, array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => $id,
            'task_packet_hash' => hash('sha256', $id),
            'required_capabilities' => $required,
            'risk_level' => 'low',
            'workspace_policy' => 'none',
            'requires_lease' => false,
            'dry_run_only' => false,
            'evidence_required' => true,
        ], $overrides);
    }

    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function agent(string $id, array $capabilities, array $overrides = []): array
    {
        return array_merge([
            'agent_id' => $id,
            'kind' => 'codex',
            'status' => 'available',
            'capabilities' => $capabilities,
            'max_parallel_tasks' => 2,
            'current_task_count' => 0,
            'workspace_isolation_supported' => true,
            'lease_supported' => true,
            'evidence_required' => true,
        ], $overrides);
    }
}
