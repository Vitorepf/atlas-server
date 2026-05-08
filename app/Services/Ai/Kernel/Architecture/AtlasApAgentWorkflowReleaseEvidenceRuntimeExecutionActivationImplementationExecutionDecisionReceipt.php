<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_receipt.v1';

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
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_receipt_only_no_execution',
            'work_title' => $decisionPayload['work_title'] ?? null,
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'] ?? null,
                'status' => $decisionPayload['status'] ?? null,
                'decision' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_decision.value'),
                'reason' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_decision.reason'),
                'execution_scope' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.execution_scope'),
                'payload_hash' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.payload_hash'),
                'idempotency_key' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.idempotency_key'),
                'operator_confirmation_surface' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.operator_confirmation_surface'),
                'owner' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.owner'),
                'policy_receipt_source' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.policy_receipt_source'),
                'rollback_plan_ref' => data_get($decisionPayload, 'runtime_execution_activation_implementation_execution_preflight_summary.rollback_plan_ref'),
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
                'bypasses_future_runtime_execution_activation_implementation_execution_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'runtime_execution_activation_implementation_execution_accepted_by_human' => 'runtime_execution_activation_implementation_execution_acceptance_reported',
            'runtime_execution_activation_implementation_execution_changes_requested_by_human' => 'runtime_execution_activation_implementation_execution_returned_for_repair',
            'runtime_execution_activation_implementation_execution_rejected_by_human' => 'runtime_execution_activation_implementation_execution_stopped_by_rejection',
            default => 'blocked_by_runtime_execution_activation_implementation_execution_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_acceptance_reported' => 'future_runtime_execution_activation_implementation_execution_ap_may_consume_decision_receipt_without_execution',
            'runtime_execution_activation_implementation_execution_returned_for_repair' => 'repair_runtime_execution_activation_implementation_execution_preflight_then_request_new_human_decision',
            'runtime_execution_activation_implementation_execution_stopped_by_rejection' => 'stop_runtime_execution_activation_implementation_execution_flow_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_decision_contract' => 'repair_runtime_execution_activation_implementation_execution_decision_before_receipt',
            default => 'review_runtime_execution_activation_implementation_execution_decision_receipt_status',
        };
    }
}
