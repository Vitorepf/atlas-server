<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowAp520DecisionContract
{
    private const PREFIX = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution';

    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract.v1';

    private const READY_PREFLIGHT_STATUS = 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review';

    private const PREFLIGHT_SUMMARY_KEY = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_summary';

    private const HANDOFF_SUMMARY_KEY = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_summary';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight',
        'request_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_changes',
        'reject_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight',
    ];

    /**
     * @param  array<string,mixed>  $preflight
     * @return array<string,mixed>
     */
    public function decide(array $preflight, string $decision, ?string $reason = null): array
    {
        $decisionPayload = $this->decisionPayload($decision, $reason);
        $status = $this->status($preflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_only_no_ledger_write',
            'work_title' => $preflight['work_title'] ?? null,
            'resolved_target_ap' => $preflight['resolved_target_ap'] ?? null,
            self::PREFLIGHT_SUMMARY_KEY => [
                'schema_version' => $preflight['schema_version'] ?? null,
                'status' => $preflight['status'] ?? null,
                'future_ap' => data_get($preflight, self::HANDOFF_SUMMARY_KEY.'.future_ap'),
                'receipt_ref' => data_get($preflight, self::HANDOFF_SUMMARY_KEY.'.receipt_ref'),
                'package' => data_get($preflight, self::HANDOFF_SUMMARY_KEY.'.package'),
                'real_execution_surface' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.real_execution_surface'),
                'append_only_write_plan_ref' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.append_only_write_plan_ref'),
                'idempotency_key_strategy' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.operator_confirmation_surface'),
                'rollback_or_replay_plan_ref' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.rollback_or_replay_plan_ref'),
                'rate_limit_or_budget_guard_ref' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.rate_limit_or_budget_guard_ref'),
                'runtime_observability_guard_ref' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.runtime_observability_guard_ref'),
                'payload_hash' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.payload_hash'),
                'receipt_destination_ref' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.receipt_destination_ref'),
                'owner' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.owner'),
                'policy_receipt_source' => data_get($preflight, 'real_durable_execution_executor_payload_execution_target.policy_receipt_source'),
            ],
            self::PREFIX.'_decision' => $decisionPayload,
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
                'accepts_without_ready_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight' => false,
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
            $errors[] = ['id' => 'real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_not_allowed', 'decision' => $normalized];
        }

        if (in_array($normalized, self::ALLOWED_DECISIONS, true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = ['id' => 'real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_reason_required', 'decision' => $normalized];
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
            return 'blocked_invalid_'.self::PREFIX.'_decision';
        }

        if (($preflight['status'] ?? null) !== self::READY_PREFLIGHT_STATUS) {
            return 'blocked_by_'.self::PREFIX.'_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight' => self::PREFIX.'_accepted_by_human',
            'request_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_changes' => self::PREFIX.'_changes_requested_by_human',
            'reject_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight' => self::PREFIX.'_rejected_by_human',
            default => 'blocked_invalid_'.self::PREFIX.'_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            self::PREFIX.'_accepted_by_human' => 'future_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_ap_may_report_acceptance_without_ledger_write',
            self::PREFIX.'_changes_requested_by_human' => 'repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_before_new_decision',
            self::PREFIX.'_rejected_by_human' => 'stop_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_path_until_scope_reopens',
            'blocked_by_'.self::PREFIX.'_preflight' => 'repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_before_decision',
            'blocked_invalid_'.self::PREFIX.'_decision' => 'fix_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_value_or_reason',
            default => 'review_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_status',
        };
    }
}
