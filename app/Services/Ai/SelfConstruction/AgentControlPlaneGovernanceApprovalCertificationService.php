<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Dedicated certification for Agent Control Plane governance policy and
 * approval receipt planning. It never grants or persists approval.
 */
final class AgentControlPlaneGovernanceApprovalCertificationService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_governance_approval_certification.v1';

    public const MODE = 'read_only_agent_control_plane_governance_approval_certification';

    public function __construct(
        private readonly AgentControlPlaneGovernancePolicyEvaluator $policy = new AgentControlPlaneGovernancePolicyEvaluator,
        private readonly AgentControlPlaneApprovalReceiptPlanner $receiptPlanner = new AgentControlPlaneApprovalReceiptPlanner,
        private readonly AgentDispatchPlannerGovernancePrecheck $dispatchGovernance = new AgentDispatchPlannerGovernancePrecheck,
        private readonly AgentMergeReviewHumanApprovalPlanner $humanApprovalPlanner = new AgentMergeReviewHumanApprovalPlanner,
    ) {}

    /** @return array<string, mixed> */
    public function certify(array $options = []): array
    {
        $blocked = $this->policy->evaluate(['action' => 'dispatch_agent', 'risk_level' => 'high'], [
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
            'operator_approval_present' => false,
        ]);
        $clear = $this->policy->evaluate(['action' => 'dispatch_agent', 'risk_level' => 'high'], [
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
            'operator_approval_present' => true,
        ]);
        $selfProgramming = $this->policy->evaluate(['action' => 'enable_self_programming', 'risk_level' => 'critical'], [
            'kill_switch_state' => 'armed',
            'budget_gate_state' => 'green',
            'operator_approval_present' => true,
        ]);
        $receipt = $this->receiptPlanner->plan($clear, ['required_approvers' => ['operator', 'reviewer']]);
        $dispatchPrecheck = $this->dispatchGovernance->precheck(
            [['task_packet_id' => 'task-high', 'risk_level' => 'high', 'evidence_refs' => ['evidence-1']]],
            [['agent_id' => 'agent-a', 'status' => 'available']],
            ['operator_approved_task_ids' => ['task-high'], 'use_live_quarantine' => false],
        );
        $mergeApproval = $this->humanApprovalPlanner->plan(
            ['packet' => ['packet_id' => 'merge-packet']],
            ['verification' => []],
            ['risk' => ['overall_band' => 'medium', 'blockers' => []]],
        );

        $invariants = [
            $this->inv('high_risk_blocks_without_operator_approval', ($blocked['status'] ?? '') === 'governance_policy_blocked' && in_array('operator_approval_required', (array) ($blocked['blockers'] ?? []), true), 'high risk must require operator approval'),
            $this->inv('high_risk_clears_with_operator_approval', ($clear['status'] ?? '') === 'governance_policy_clear', 'high risk can clear only as a projection when approval is present'),
            $this->inv('self_programming_stays_blocked', ($selfProgramming['status'] ?? '') === 'governance_policy_blocked' && in_array('self_programming_requires_separate_program_stage', (array) ($selfProgramming['blockers'] ?? []), true), 'self-programming remains outside this stage'),
            $this->inv('receipt_plan_does_not_grant_approval', ($receipt['approval_granted'] ?? true) === false && ($receipt['approval_persisted'] ?? true) === false, 'receipt planner must not grant or persist approval'),
            $this->inv('dispatch_governance_precheck_available', ($dispatchPrecheck['status'] ?? '') === 'ok', 'dispatch governance precheck should align with approved high-risk task'),
            $this->inv('merge_human_approval_plan_available', ($mergeApproval['status'] ?? '') === 'agent_merge_review_human_approval_plan_ready', 'merge review approval planner should produce a plan'),
            $this->inv('runtime_safety_all_false', $this->runtimeSafetyAllFalse($clear, $receipt, $dispatchPrecheck, $mergeApproval), 'all approval/runtime flags stay false'),
        ];
        $violations = array_values(array_filter($invariants, static fn (array $i): bool => $i['ok'] === false));
        $allTrue = $violations === [];
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $allTrue ? 'available' : 'blocked',
            'certified_at' => CarbonImmutable::now()->toIso8601String(),
            'policy_blocked_sample' => $blocked,
            'policy_clear_sample' => $clear,
            'self_programming_policy_sample' => $selfProgramming,
            'approval_receipt_plan' => $receipt,
            'dispatch_governance_precheck' => $dispatchPrecheck,
            'merge_human_approval_plan' => $mergeApproval,
            'invariants' => $invariants,
            'invariants_all_true' => $allTrue,
            'violation_count' => count($violations),
            'violations' => $violations,
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'approval_granted' => false,
                'approval_persisted' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'ledger_write_allowed' => false,
                'self_programming_allowed' => false,
                'completion_claim_allowed' => false,
            ],
            'next_action' => $allTrue
                ? 'keep_governance_approval_runtime_read_only_until_signed_approval_persistence_gate'
                : 'repair_governance_approval_runtime_invariants_before_runtime_promotion',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
        ];
        $payload['certification_hash'] = $this->stableHash($payload);

        return $payload;
    }

    private function inv(string $name, bool $ok, string $observation): array
    {
        return ['name' => $name, 'ok' => $ok, 'observation' => $observation];
    }

    private function runtimeSafetyAllFalse(array ...$payloads): bool
    {
        foreach ($payloads as $payload) {
            foreach (['dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'ledger_write_allowed', 'self_programming_allowed', 'completion_claim_allowed'] as $flag) {
                if (($payload[$flag] ?? false) !== false) {
                    return false;
                }
            }
            if (($payload['approval_granted'] ?? false) !== false || ($payload['approval_persisted'] ?? false) !== false) {
                return false;
            }
        }

        return true;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['certified_at'], $payload['certification_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
