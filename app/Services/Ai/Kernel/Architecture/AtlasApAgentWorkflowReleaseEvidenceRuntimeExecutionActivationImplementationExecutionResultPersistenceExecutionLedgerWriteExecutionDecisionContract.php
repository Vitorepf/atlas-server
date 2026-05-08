<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution',
        'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes',
        'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution',
    ];

    /**
     * @param  array<string,mixed>  $ledgerWriteExecutionPreflight
     * @return array<string,mixed>
     */
    public function decide(array $ledgerWriteExecutionPreflight, string $decision, ?string $reason = null): array
    {
        $decisionPayload = $this->decisionPayload($decision, $reason);
        $status = $this->status($ledgerWriteExecutionPreflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision_only_no_ledger_write',
            'work_title' => $ledgerWriteExecutionPreflight['work_title'] ?? null,
            'resolved_target_ap' => $ledgerWriteExecutionPreflight['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight_summary' => [
                'schema_version' => $ledgerWriteExecutionPreflight['schema_version'] ?? null,
                'status' => $ledgerWriteExecutionPreflight['status'] ?? null,
                'execution_surface' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.execution_surface'),
                'idempotency_key_strategy' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.operator_confirmation_surface'),
                'rollback_plan_ref' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.rollback_plan_ref'),
                'payload_hash' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.payload_hash'),
                'ledger_write_plan_ref' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.ledger_write_plan_ref'),
                'owner' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target.owner'),
                'policy_receipt_source' => data_get($ledgerWriteExecutionPreflight, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision' => $decisionPayload,
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
                'performs_ledger_write' => false,
                'accepts_without_ready_ledger_write_execution_preflight' => false,
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
            $errors[] = ['id' => 'result_persistence_execution_ledger_write_execution_decision_not_allowed', 'decision' => $normalized];
        }

        if (in_array($normalized, self::ALLOWED_DECISIONS, true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = ['id' => 'result_persistence_execution_ledger_write_execution_decision_reason_required', 'decision' => $normalized];
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
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $preflight, array $decisionPayload): string
    {
        if (($decisionPayload['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision';
        }

        if (($preflight['status'] ?? null) !== 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_review') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_accepted_by_human',
            'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes_requested_by_human',
            'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_rejected_by_human',
            default => 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_accepted_by_human' => 'future_ledger_write_execution_receipt_ap_may_report_acceptance_without_ledger_write',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes_requested_by_human' => 'repair_result_persistence_execution_ledger_write_execution_preflight_before_new_decision',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_rejected_by_human' => 'stop_result_persistence_execution_ledger_write_execution_path_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight' => 'repair_result_persistence_execution_ledger_write_execution_preflight_before_decision',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision' => 'fix_result_persistence_execution_ledger_write_execution_decision_value_or_reason',
            default => 'review_result_persistence_execution_ledger_write_execution_decision_status',
        };
    }
}
