<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_receipt.v1';

    /**
     * @param  array<string,mixed>  $decisionPayload
     * @return array<string,mixed>
     */
    public function receipt(array $decisionPayload): array
    {
        $status = $this->status($decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_receipt_only_no_ledger_write',
            'work_title' => $decisionPayload['work_title'] ?? null,
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'] ?? null,
                'status' => $decisionPayload['status'] ?? null,
                'decision' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision.value'),
                'reason' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision.reason'),
                'durable_ledger_write_surface' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.durable_ledger_write_surface'),
                'append_only_write_plan_ref' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.append_only_write_plan_ref'),
                'idempotency_key_strategy' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.operator_confirmation_surface'),
                'rollback_or_replay_plan_ref' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.rollback_or_replay_plan_ref'),
                'payload_hash' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.payload_hash'),
                'ledger_write_receipt_ref' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.ledger_write_receipt_ref'),
                'owner' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.owner'),
                'policy_receipt_source' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.policy_receipt_source'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_receipt' => false,
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
                'bypasses_future_durable_ledger_write_execution_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_accepted_by_human' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_acceptance_reported',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_changes_requested_by_human' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_returned_for_repair',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_rejected_by_human' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_stopped_by_rejection',
            default => 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_acceptance_reported' => 'future_durable_ledger_write_execution_ap_may_consume_receipt_without_ledger_write_here',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_returned_for_repair' => 'repair_durable_result_persistence_execution_ledger_write_execution_preflight_then_request_new_decision',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_stopped_by_rejection' => 'stop_durable_result_persistence_execution_ledger_write_execution_path_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_contract' => 'repair_durable_result_persistence_execution_ledger_write_execution_decision_before_receipt',
            default => 'review_durable_result_persistence_execution_ledger_write_execution_decision_receipt_status',
        };
    }
}
