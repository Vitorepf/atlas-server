<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowAp377DecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowAp377DecisionReceiptTest extends TestCase
{
    private const PREFIX = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution';

    public function test_acceptance_decision_receipt_is_reported(): void
    {
        $payload = app(AtlasApAgentWorkflowAp377DecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(self::PREFIX.'_accepted_by_human', 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution'),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1', $payload['schema_version']);
        $this->assertSame(self::PREFIX.'_acceptance_reported', $payload['status']);
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution', data_get($payload, self::PREFIX.'_decision_summary.decision'));
        $this->assertSame('evidence-ledger-append-worker-confirmed-runtime', data_get($payload, self::PREFIX.'_decision_summary.real_execution_surface'));
        $this->assertSame('future-receipt-destination:fixture:001', data_get($payload, self::PREFIX.'_decision_summary.receipt_destination_ref'));
        $this->assertSame('future_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_ap_may_be_consumed_without_ledger_write_here', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
    }

    public function test_change_request_decision_receipt_returns_to_repair(): void
    {
        $payload = app(AtlasApAgentWorkflowAp377DecisionReceipt::class)->receipt(
            $this->decisionPayload(self::PREFIX.'_changes_requested_by_human', 'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_changes'),
        );

        $this->assertSame(self::PREFIX.'_returned_for_repair', $payload['status']);
        $this->assertSame('repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_then_request_new_decision', $payload['next_action']);
    }

    public function test_rejection_decision_receipt_stops_path(): void
    {
        $payload = app(AtlasApAgentWorkflowAp377DecisionReceipt::class)->receipt(
            $this->decisionPayload(self::PREFIX.'_rejected_by_human', 'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution'),
        );

        $this->assertSame(self::PREFIX.'_stopped_by_rejection', $payload['status']);
        $this->assertSame('stop_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_path_until_scope_reopens', $payload['next_action']);
    }

    public function test_blocked_decision_contract_blocks_receipt(): void
    {
        $payload = app(AtlasApAgentWorkflowAp377DecisionReceipt::class)->receipt(
            $this->decisionPayload('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision', 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution'),
        );

        $this->assertSame('blocked_by_'.self::PREFIX.'_decision_contract', $payload['status']);
        $this->assertSame('repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_before_receipt', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $status, string $decision): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract.v1',
            'status' => $status,
            'work_title' => 'Receipt AP-376 human decision for future real durable ledger write execution review',
            'resolved_target_ap' => 'AP-377',
            self::PREFIX.'_preflight_summary' => [
                'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight.v1',
                'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review',
                'future_ap' => 'AP-future-real-durable-ledger-write-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-receipt',
                'real_execution_surface' => 'evidence-ledger-append-worker-confirmed-runtime',
                'append_only_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_or_replay_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'rate_limit_or_budget_guard_ref' => 'runtime-budget-guard:fixture:001',
                'runtime_observability_guard_ref' => 'runtime-observability-guard:fixture:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'receipt_destination_ref' => 'future-receipt-destination:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            self::PREFIX.'_decision' => [
                'value' => $decision,
                'reason' => 'Human reviewed AP-376 decision for future receipt only.',
            ],
        ];
    }
}
