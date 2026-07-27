<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Evaluates operator governance policy for future runtime actions without
 * granting approval or mutating state.
 */
final class AgentControlPlaneGovernancePolicyEvaluator
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_governance_policy_evaluation.v1';

    public const MODE = 'read_only_agent_control_plane_governance_policy_evaluation';

    public const ACTIONS_REQUIRING_APPROVAL = [
        'dispatch_agent',
        'start_provider',
        'apply_patch',
        'promote_completion',
        'write_evidence_ledger',
        'enable_self_programming',
    ];

    /** @return array<string, mixed> */
    public function evaluate(array $request = [], array $policy = []): array
    {
        $action = trim((string) ($request['action'] ?? 'dispatch_agent'));
        $risk = strtolower(trim((string) ($request['risk_level'] ?? 'low')));
        if (! in_array($risk, ['low', 'medium', 'high', 'critical'], true)) {
            $risk = 'unknown';
        }

        $killSwitch = (string) ($policy['kill_switch_state'] ?? 'armed');
        $budgetGate = (string) ($policy['budget_gate_state'] ?? 'green');
        $operatorApprovalPresent = (bool) ($policy['operator_approval_present'] ?? false);
        $approvalRequired = in_array($action, self::ACTIONS_REQUIRING_APPROVAL, true)
            || in_array($risk, ['high', 'critical', 'unknown'], true);

        $blockers = [];
        if ($killSwitch !== 'armed') {
            $blockers[] = 'kill_switch_not_armed:'.$killSwitch;
        }
        if ($budgetGate !== 'green') {
            $blockers[] = 'budget_gate_not_green:'.$budgetGate;
        }
        if ($approvalRequired && ! $operatorApprovalPresent) {
            $blockers[] = 'operator_approval_required';
        }
        if ($action === 'enable_self_programming') {
            $blockers[] = 'self_programming_requires_separate_program_stage';
        }

        $evaluation = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $blockers === [] ? 'governance_policy_clear' : 'governance_policy_blocked',
            'action' => $action,
            'risk_level' => $risk,
            'approval_required' => $approvalRequired,
            'operator_approval_present' => $operatorApprovalPresent,
            'kill_switch_state' => $killSwitch,
            'budget_gate_state' => $budgetGate,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'approval_granted' => false,
            'approval_persisted' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'runtime_safety' => $this->runtimeSafety(),
        ];
        $evaluation['policy_evaluation_hash'] = $this->stableHash($evaluation);

        return $evaluation;
    }

    /**
     * A compact runtime_action_matrix: classifies canonical request shapes (read-only,
     * dispatch, ledger-write, provider-call, self-programming) as clear or blocked under the
     * SAME policy, without ever granting runtime authority — every entry's evaluation carries
     * the same hardcoded-false runtime_safety block as evaluate() itself.
     *
     * @param  array<string,mixed>  $policy
     * @return array<string, array<string,mixed>>
     */
    public function runtimeActionMatrix(array $policy = [], string $riskLevel = 'low'): array
    {
        $actionsByLane = [
            'read_only' => 'read_only_analysis',
            'dispatch' => 'dispatch_agent',
            'ledger_write' => 'write_evidence_ledger',
            'provider_call' => 'start_provider',
            'self_programming' => 'enable_self_programming',
        ];

        $matrix = [];
        foreach ($actionsByLane as $lane => $action) {
            $evaluation = $this->evaluate(['action' => $action, 'risk_level' => $riskLevel], $policy);
            $matrix[$lane] = [
                'action' => $action,
                'classification' => $evaluation['status'] === 'governance_policy_clear' ? 'clear' : 'blocked',
                'blockers' => $evaluation['blockers'],
                'runtime_safety' => $evaluation['runtime_safety'],
            ];
        }

        return $matrix;
    }

    /** @return array<string, bool> */
    public function runtimeSafety(): array
    {
        return [
            'runtime_safety_all_false' => true,
            'approval_granted' => false,
            'approval_persisted' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
        ];
    }

    private function stableHash(array $payload): string
    {
        unset($payload['policy_evaluation_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
