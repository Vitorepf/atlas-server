<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Plans an approval receipt envelope without accepting signatures,
 * granting approvals or persisting approval state.
 */
final class AgentControlPlaneApprovalReceiptPlanner
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_approval_receipt_plan.v1';

    public const MODE = 'read_only_agent_control_plane_approval_receipt_plan';

    /** @return array<string, mixed> */
    public function plan(array $policyEvaluation, array $options = []): array
    {
        $approvers = $this->normalizeList((array) ($options['required_approvers'] ?? ['operator']));
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'approval_receipt_plan_ready',
            'action' => (string) ($policyEvaluation['action'] ?? ''),
            'risk_level' => (string) ($policyEvaluation['risk_level'] ?? ''),
            'policy_evaluation_hash' => (string) ($policyEvaluation['policy_evaluation_hash'] ?? ''),
            'required_approvers' => $approvers,
            'required_approver_count' => count($approvers),
            'signature_required' => true,
            'human_reason_required' => true,
            'operator_identity_required' => true,
            'approval_granted' => false,
            'approval_persisted' => false,
            'signature_accepted' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
        ];
        $receipt['approval_receipt_plan_hash'] = $this->stableHash($receipt);

        return $receipt;
    }

    /** @return list<string> */
    private function normalizeList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $clean = trim((string) $value);
            if ($clean !== '') {
                $out[$clean] = true;
            }
        }
        $keys = array_keys($out);
        sort($keys);

        return $keys;
    }

    private function stableHash(array $payload): string
    {
        unset($payload['approval_receipt_plan_hash']);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
