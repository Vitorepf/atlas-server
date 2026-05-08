<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_review_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_runtime_execution_activation_implementation_execution_result',
        'request_runtime_execution_activation_implementation_execution_result_changes',
        'reject_runtime_execution_activation_implementation_execution_result',
    ];

    /**
     * @param  array<string,mixed>  $resultEnvelope
     * @return array<string,mixed>
     */
    public function decide(array $resultEnvelope, string $decision, ?string $reason = null): array
    {
        $normalizedDecision = trim($decision);
        $normalizedReason = is_string($reason) ? trim($reason) : null;
        $status = $this->status($resultEnvelope, $normalizedDecision, $normalizedReason);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_review',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_review_only_no_execution',
            'work_title' => $resultEnvelope['work_title'] ?? null,
            'resolved_target_ap' => $resultEnvelope['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_envelope_summary' => [
                'schema_version' => $resultEnvelope['schema_version'] ?? null,
                'status' => $resultEnvelope['status'] ?? null,
                'result_status' => data_get($resultEnvelope, 'result_summary.result_status'),
                'artifact_refs' => data_get($resultEnvelope, 'result_summary.artifact_refs', []),
                'executor_receipt_ref' => data_get($resultEnvelope, 'result_summary.executor_receipt_ref'),
                'rollback_plan_ref' => data_get($resultEnvelope, 'result_summary.rollback_plan_ref'),
                'policy_receipt_source' => data_get($resultEnvelope, 'result_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_decision' => [
                'value' => $normalizedDecision,
                'reason' => $normalizedReason,
                'allowed_values' => self::ALLOWED_DECISIONS,
                'requires_reason' => true,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'accepts_without_result_envelope' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $resultEnvelope
     */
    private function status(array $resultEnvelope, string $decision, ?string $reason): string
    {
        if (($resultEnvelope['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_envelope';
        }

        if (! in_array($decision, self::ALLOWED_DECISIONS, true) || $reason === null || $reason === '') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_review_decision';
        }

        return match ($decision) {
            'accept_runtime_execution_activation_implementation_execution_result' => 'runtime_execution_activation_implementation_execution_result_accepted_by_human',
            'request_runtime_execution_activation_implementation_execution_result_changes' => 'runtime_execution_activation_implementation_execution_result_changes_requested_by_human',
            'reject_runtime_execution_activation_implementation_execution_result' => 'runtime_execution_activation_implementation_execution_result_rejected_by_human',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_accepted_by_human' => 'emit_runtime_execution_activation_implementation_execution_result_receipt_without_ledger_write',
            'runtime_execution_activation_implementation_execution_result_changes_requested_by_human' => 'repair_runtime_execution_activation_implementation_execution_result_envelope_then_review_again',
            'runtime_execution_activation_implementation_execution_result_rejected_by_human' => 'stop_runtime_execution_activation_implementation_execution_result_flow_until_scope_reopens',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_review_decision' => 'provide_valid_result_review_decision_and_reason',
            'blocked_by_runtime_execution_activation_implementation_execution_result_envelope' => 'repair_result_envelope_before_human_review',
            default => 'review_runtime_execution_activation_implementation_execution_result_review_status',
        };
    }
}
