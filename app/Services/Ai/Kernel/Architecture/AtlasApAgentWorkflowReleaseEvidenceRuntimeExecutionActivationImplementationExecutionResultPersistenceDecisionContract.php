<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_runtime_execution_activation_implementation_execution_result_persistence',
        'request_runtime_execution_activation_implementation_execution_result_persistence_changes',
        'reject_runtime_execution_activation_implementation_execution_result_persistence',
    ];

    /**
     * @param  array<string,mixed>  $persistencePreflight
     * @return array<string,mixed>
     */
    public function decide(array $persistencePreflight, string $decision, ?string $reason = null): array
    {
        $decisionPayload = $this->decisionPayload($decision, $reason);
        $status = $this->status($persistencePreflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_only_no_ledger_write',
            'work_title' => $persistencePreflight['work_title'] ?? null,
            'resolved_target_ap' => $persistencePreflight['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_preflight_summary' => [
                'schema_version' => $persistencePreflight['schema_version'] ?? null,
                'status' => $persistencePreflight['status'] ?? null,
                'persistence_target' => data_get($persistencePreflight, 'persistence_plan.persistence_target'),
                'evidence_event_family' => data_get($persistencePreflight, 'persistence_plan.evidence_event_family'),
                'redaction_strategy' => data_get($persistencePreflight, 'persistence_plan.redaction_strategy'),
                'payload_hash' => data_get($persistencePreflight, 'persistence_plan.payload_hash'),
                'ledger_write_plan_ref' => data_get($persistencePreflight, 'persistence_plan.ledger_write_plan_ref'),
                'owner' => data_get($persistencePreflight, 'persistence_plan.owner'),
                'policy_receipt_source' => data_get($persistencePreflight, 'runtime_execution_activation_implementation_execution_result_receipt_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_decision' => $decisionPayload,
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
                'accepts_without_ready_persistence_preflight' => false,
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
                'id' => 'runtime_execution_activation_implementation_execution_result_persistence_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, self::ALLOWED_DECISIONS, true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'runtime_execution_activation_implementation_execution_result_persistence_decision_reason_required',
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
     * @param  array<string,mixed>  $persistencePreflight
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $persistencePreflight, array $decisionPayload): string
    {
        if (($decisionPayload['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision';
        }

        if (($persistencePreflight['status'] ?? null) !== 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_review') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_runtime_execution_activation_implementation_execution_result_persistence' => 'runtime_execution_activation_implementation_execution_result_persistence_accepted_by_human',
            'request_runtime_execution_activation_implementation_execution_result_persistence_changes' => 'runtime_execution_activation_implementation_execution_result_persistence_changes_requested_by_human',
            'reject_runtime_execution_activation_implementation_execution_result_persistence' => 'runtime_execution_activation_implementation_execution_result_persistence_rejected_by_human',
            default => 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_accepted_by_human' => 'future_result_persistence_receipt_ap_may_report_acceptance_without_ledger_write',
            'runtime_execution_activation_implementation_execution_result_persistence_changes_requested_by_human' => 'repair_result_persistence_preflight_before_new_decision',
            'runtime_execution_activation_implementation_execution_result_persistence_rejected_by_human' => 'stop_result_persistence_path_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_preflight' => 'repair_result_persistence_preflight_before_decision',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision' => 'fix_result_persistence_decision_value_or_reason',
            default => 'review_result_persistence_decision_status',
        };
    }
}
