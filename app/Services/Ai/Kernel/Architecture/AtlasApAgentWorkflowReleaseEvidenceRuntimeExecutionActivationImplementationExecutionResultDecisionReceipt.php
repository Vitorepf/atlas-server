<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_decision_receipt.v1';

    /**
     * @param  array<string,mixed>  $reviewPayload
     * @return array<string,mixed>
     */
    public function receipt(array $reviewPayload): array
    {
        $status = $this->status($reviewPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_receipt_only_no_ledger_write',
            'work_title' => $reviewPayload['work_title'] ?? null,
            'resolved_target_ap' => $reviewPayload['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_decision_summary' => [
                'schema_version' => $reviewPayload['schema_version'] ?? null,
                'status' => $reviewPayload['status'] ?? null,
                'decision' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_decision.value'),
                'reason' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_decision.reason'),
                'result_status' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_envelope_summary.result_status'),
                'artifact_refs' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_envelope_summary.artifact_refs', []),
                'executor_receipt_ref' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_envelope_summary.executor_receipt_ref'),
                'rollback_plan_ref' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_envelope_summary.rollback_plan_ref'),
                'policy_receipt_source' => data_get($reviewPayload, 'runtime_execution_activation_implementation_execution_result_envelope_summary.policy_receipt_source'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_receipt' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'bypasses_future_result_persistence_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $reviewPayload
     */
    private function status(array $reviewPayload): string
    {
        return match ($reviewPayload['status'] ?? null) {
            'runtime_execution_activation_implementation_execution_result_accepted_by_human' => 'runtime_execution_activation_implementation_execution_result_acceptance_reported',
            'runtime_execution_activation_implementation_execution_result_changes_requested_by_human' => 'runtime_execution_activation_implementation_execution_result_returned_for_repair',
            'runtime_execution_activation_implementation_execution_result_rejected_by_human' => 'runtime_execution_activation_implementation_execution_result_stopped_by_rejection',
            default => 'blocked_by_runtime_execution_activation_implementation_execution_result_review_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_acceptance_reported' => 'future_result_persistence_ap_may_consume_receipt_without_ledger_write',
            'runtime_execution_activation_implementation_execution_result_returned_for_repair' => 'repair_runtime_execution_activation_implementation_execution_result_envelope_then_request_new_review',
            'runtime_execution_activation_implementation_execution_result_stopped_by_rejection' => 'stop_runtime_execution_activation_implementation_execution_result_flow_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_result_review_contract' => 'repair_runtime_execution_activation_implementation_execution_result_review_before_receipt',
            default => 'review_runtime_execution_activation_implementation_execution_result_decision_receipt_status',
        };
    }
}
