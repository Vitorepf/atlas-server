<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneApprovalReceiptPlanner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneGovernanceApprovalCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneGovernancePolicyEvaluator;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AgentControlPlaneGovernanceApprovalRuntimeTest extends TestCase
{
    public function test_policy_blocks_high_risk_without_operator_approval(): void
    {
        $evaluation = (new AgentControlPlaneGovernancePolicyEvaluator)->evaluate(
            ['action' => 'dispatch_agent', 'risk_level' => 'high'],
            ['kill_switch_state' => 'armed', 'budget_gate_state' => 'green', 'operator_approval_present' => false],
        );

        $this->assertSame('governance_policy_blocked', $evaluation['status']);
        $this->assertContains('operator_approval_required', $evaluation['blockers']);
        $this->assertTrue($evaluation['approval_required']);
        $this->assertFalse($evaluation['approval_granted']);
        $this->assertFalse($evaluation['dispatch_allowed']);
        $this->assertFalse($evaluation['provider_call_allowed']);
        $this->assertFalse($evaluation['token_spend_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evaluation['policy_evaluation_hash']);
    }

    public function test_policy_can_clear_projection_with_approval_without_granting_runtime(): void
    {
        $evaluation = (new AgentControlPlaneGovernancePolicyEvaluator)->evaluate(
            ['action' => 'dispatch_agent', 'risk_level' => 'high'],
            ['kill_switch_state' => 'armed', 'budget_gate_state' => 'green', 'operator_approval_present' => true],
        );

        $this->assertSame('governance_policy_clear', $evaluation['status']);
        $this->assertSame([], $evaluation['blockers']);
        $this->assertTrue($evaluation['operator_approval_present']);
        $this->assertFalse($evaluation['approval_granted']);
        $this->assertFalse($evaluation['approval_persisted']);
        $this->assertFalse($evaluation['ledger_write_allowed']);
        $this->assertFalse($evaluation['self_programming_allowed']);
        $this->assertTrue($evaluation['runtime_safety']['runtime_safety_all_false']);
    }

    public function test_self_programming_stays_blocked_even_with_operator_approval(): void
    {
        $evaluation = (new AgentControlPlaneGovernancePolicyEvaluator)->evaluate(
            ['action' => 'enable_self_programming', 'risk_level' => 'critical'],
            ['kill_switch_state' => 'armed', 'budget_gate_state' => 'green', 'operator_approval_present' => true],
        );

        $this->assertSame('governance_policy_blocked', $evaluation['status']);
        $this->assertContains('self_programming_requires_separate_program_stage', $evaluation['blockers']);
        $this->assertFalse($evaluation['self_programming_allowed']);
        $this->assertFalse($evaluation['completion_claim_allowed']);
    }

    public function test_approval_receipt_planner_is_stable_and_non_granting(): void
    {
        $policy = (new AgentControlPlaneGovernancePolicyEvaluator)->evaluate(
            ['action' => 'apply_patch', 'risk_level' => 'medium'],
            ['operator_approval_present' => true],
        );
        $planner = new AgentControlPlaneApprovalReceiptPlanner;

        $first = $planner->plan($policy, ['required_approvers' => ['reviewer', 'operator', 'operator']]);
        $second = $planner->plan($policy, ['required_approvers' => ['operator', 'reviewer']]);

        $this->assertSame('approval_receipt_plan_ready', $first['status']);
        $this->assertSame(['operator', 'reviewer'], $first['required_approvers']);
        $this->assertSame($first['approval_receipt_plan_hash'], $second['approval_receipt_plan_hash']);
        $this->assertFalse($first['approval_granted']);
        $this->assertFalse($first['approval_persisted']);
        $this->assertFalse($first['signature_accepted']);
        $this->assertFalse($first['dispatch_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['approval_receipt_plan_hash']);
    }

    public function test_certification_is_available_and_runtime_safe(): void
    {
        $cert = (new AgentControlPlaneGovernanceApprovalCertificationService)->certify();

        $this->assertSame('available', $cert['status']);
        $this->assertTrue($cert['invariants_all_true']);
        $this->assertSame(0, $cert['violation_count']);
        $this->assertSame('governance_policy_blocked', $cert['policy_blocked_sample']['status']);
        $this->assertSame('governance_policy_clear', $cert['policy_clear_sample']['status']);
        $this->assertSame('approval_receipt_plan_ready', $cert['approval_receipt_plan']['status']);
        $this->assertTrue($cert['runtime_safety']['runtime_safety_all_false']);
        $this->assertFalse($cert['dispatch_allowed']);
        $this->assertFalse($cert['provider_call_allowed']);
        $this->assertFalse($cert['token_spend_allowed']);
        $this->assertFalse($cert['ledger_write_allowed']);
        $this->assertFalse($cert['self_programming_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $cert['certification_hash']);
    }

    public function test_command_exposes_governance_approval_runtime_quartet(): void
    {
        foreach ([
            '--agent-control-plane-governance-approval-runtime-contract' => 'atlas.self_construction_agent_control_plane_governance_approval_runtime_contract.v1',
            '--agent-control-plane-governance-approval-runtime-preflight' => 'atlas.self_construction_agent_control_plane_governance_approval_runtime_preflight.v1',
            '--agent-control-plane-governance-approval-runtime-implementation-packet' => 'atlas.self_construction_agent_control_plane_governance_approval_runtime_implementation_packet.v1',
            '--agent-control-plane-governance-approval-runtime-status' => 'atlas.self_construction_agent_control_plane_governance_approval_runtime_status.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
            $this->assertFalse($payload['ledger_write_allowed']);
            $this->assertFalse($payload['runtime_write_allowed']);
        }
    }
}
