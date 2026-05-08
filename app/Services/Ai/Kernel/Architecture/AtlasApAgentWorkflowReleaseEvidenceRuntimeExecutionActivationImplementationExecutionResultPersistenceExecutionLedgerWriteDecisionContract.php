<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write',
        'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes',
        'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write',
    ];

    /**
     * @param  array<string,mixed>  $ledgerWritePreflight
     * @return array<string,mixed>
     */
    public function decide(array $ledgerWritePreflight, string $decision, ?string $reason = null): array
    {
        $decisionPayload = $this->decisionPayload($decision, $reason);
        $status = $this->status($ledgerWritePreflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_only_no_ledger_write',
            'work_title' => $ledgerWritePreflight['work_title'] ?? null,
            'resolved_target_ap' => $ledgerWritePreflight['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_summary' => [
                'schema_version' => $ledgerWritePreflight['schema_version'] ?? null,
                'status' => $ledgerWritePreflight['status'] ?? null,
                'execution_surface' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.execution_surface'),
                'idempotency_key_strategy' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.operator_confirmation_surface'),
                'rollback_plan_ref' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.rollback_plan_ref'),
                'payload_hash' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.payload_hash'),
                'ledger_write_plan_ref' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.ledger_write_plan_ref'),
                'owner' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.owner'),
                'policy_receipt_source' => data_get($ledgerWritePreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision' => $decisionPayload,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'persists_result_state' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'accepts_without_ready_ledger_write_preflight' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $decision, ?string $reason): array
    {
        $normalized = trim($decision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $errors = [];

        if (! in_array($normalized, self::ALLOWED_DECISIONS, true)) {
            $errors[] = ['id' => 'result_persistence_execution_ledger_write_decision_not_allowed', 'decision' => $normalized];
        }

        if (in_array($normalized, self::ALLOWED_DECISIONS, true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = ['id' => 'result_persistence_execution_ledger_write_decision_reason_required', 'decision' => $normalized];
        }

        return [
            'value' => $normalized,
            'reason' => $normalizedReason,
            'allowed_values' => self::ALLOWED_DECISIONS,
            'status' => $errors === [] ? 'valid' : 'invalid',
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $ledgerWritePreflight
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $ledgerWritePreflight, array $decisionPayload): string
    {
        if (($decisionPayload['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision';
        }

        if (($ledgerWritePreflight['status'] ?? null) !== 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_review') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_accepted_by_human',
            'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes_requested_by_human',
            'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_rejected_by_human',
            default => 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_accepted_by_human' => 'future_result_persistence_execution_ledger_write_receipt_ap_may_report_acceptance_without_ledger_write',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes_requested_by_human' => 'repair_result_persistence_execution_ledger_write_preflight_before_new_decision',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_rejected_by_human' => 'stop_result_persistence_execution_ledger_write_path_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight' => 'repair_result_persistence_execution_ledger_write_preflight_before_decision',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision' => 'fix_result_persistence_execution_ledger_write_decision_value_or_reason',
            default => 'review_result_persistence_execution_ledger_write_decision_status',
        };
    }
}
