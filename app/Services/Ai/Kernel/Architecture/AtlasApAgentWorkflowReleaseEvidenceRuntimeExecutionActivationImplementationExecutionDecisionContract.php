<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_runtime_execution_activation_implementation_execution',
        'request_runtime_execution_activation_implementation_execution_changes',
        'reject_runtime_execution_activation_implementation_execution',
    ];

    /**
     * @param  array<string,mixed>  $executionPreflight
     * @return array<string,mixed>
     */
    public function decide(
        array $executionPreflight,
        string $decision,
        ?string $reason = null,
    ): array {
        $decisionPayload = $this->decisionPayload($decision, $reason);
        $status = $this->status($executionPreflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_decision',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_only_no_execution',
            'work_title' => $executionPreflight['work_title'] ?? null,
            'resolved_target_ap' => $executionPreflight['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_preflight_summary' => [
                'schema_version' => $executionPreflight['schema_version'] ?? null,
                'status' => $executionPreflight['status'] ?? null,
                'execution_scope' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.execution_scope'),
                'payload_hash' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.payload_hash'),
                'idempotency_key' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.idempotency_key'),
                'operator_confirmation_surface' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.operator_confirmation_surface'),
                'owner' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.owner'),
                'policy_receipt_source' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.policy_receipt_source'),
                'rollback_plan_ref' => data_get($executionPreflight, 'runtime_execution_activation_implementation_execution_target.rollback_plan_ref'),
            ],
            'runtime_execution_activation_implementation_execution_decision' => $decisionPayload,
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
                'accepts_without_ready_execution_preflight' => false,
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
            $errors[] = [
                'id' => 'runtime_execution_activation_implementation_execution_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, self::ALLOWED_DECISIONS, true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'runtime_execution_activation_implementation_execution_decision_reason_required',
                'decision' => $normalized,
            ];
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
     * @param  array<string,mixed>  $executionPreflight
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $executionPreflight, array $decisionPayload): string
    {
        if (($decisionPayload['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_decision';
        }

        if (($executionPreflight['status'] ?? null) !== 'ready_for_runtime_execution_activation_implementation_execution_review') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_runtime_execution_activation_implementation_execution' => 'runtime_execution_activation_implementation_execution_accepted_by_human',
            'request_runtime_execution_activation_implementation_execution_changes' => 'runtime_execution_activation_implementation_execution_changes_requested_by_human',
            'reject_runtime_execution_activation_implementation_execution' => 'runtime_execution_activation_implementation_execution_rejected_by_human',
            default => 'blocked_invalid_runtime_execution_activation_implementation_execution_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_accepted_by_human' => 'future_runtime_execution_activation_implementation_execution_receipt_ap_may_report_acceptance_without_execution',
            'runtime_execution_activation_implementation_execution_changes_requested_by_human' => 'repair_runtime_execution_activation_implementation_execution_preflight_before_new_decision',
            'runtime_execution_activation_implementation_execution_rejected_by_human' => 'stop_runtime_execution_activation_implementation_execution_path_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_preflight' => 'repair_runtime_execution_activation_implementation_execution_preflight_before_decision',
            'blocked_invalid_runtime_execution_activation_implementation_execution_decision' => 'fix_runtime_execution_activation_implementation_execution_decision_value_or_reason',
            default => 'review_runtime_execution_activation_implementation_execution_decision_status',
        };
    }
}
