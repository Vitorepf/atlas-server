<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowAp521DecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1';

    private const SUMMARY_KEY = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_summary';

    private const DECISION_KEY = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision';

    private const PREFLIGHT_SUMMARY_KEY = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_summary';

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
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_only_no_ledger_write',
            'work_title' => $decisionPayload['work_title'] ?? null,
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'] ?? null,
            self::SUMMARY_KEY => [
                'schema_version' => $decisionPayload['schema_version'] ?? null,
                'status' => $decisionPayload['status'] ?? null,
                'decision' => data_get($decisionPayload, self::DECISION_KEY.'.value'),
                'reason' => data_get($decisionPayload, self::DECISION_KEY.'.reason'),
                'future_ap' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.future_ap'),
                'receipt_ref' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.receipt_ref'),
                'package' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.package'),
                'real_execution_surface' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.real_execution_surface'),
                'append_only_write_plan_ref' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.append_only_write_plan_ref'),
                'idempotency_key_strategy' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.operator_confirmation_surface'),
                'rollback_or_replay_plan_ref' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.rollback_or_replay_plan_ref'),
                'rate_limit_or_budget_guard_ref' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.rate_limit_or_budget_guard_ref'),
                'runtime_observability_guard_ref' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.runtime_observability_guard_ref'),
                'payload_hash' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.payload_hash'),
                'receipt_destination_ref' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.receipt_destination_ref'),
                'owner' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.owner'),
                'policy_receipt_source' => data_get($decisionPayload, self::PREFLIGHT_SUMMARY_KEY.'.policy_receipt_source'),
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
                'bypasses_future_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_accepted_by_human' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_changes_requested_by_human' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_rejected_by_human' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection',
            default => 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported' => 'future_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_ap_may_be_consumed_without_ledger_write_here',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair' => 'repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_then_request_new_decision',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection' => 'stop_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_path_until_scope_reopens',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract' => 'repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_before_receipt',
            default => 'review_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt_status',
        };
    }
}
