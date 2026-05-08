<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceiptTest extends TestCase
{
    public function test_result_persistence_execution_ledger_write_receipt_reports_acceptance_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_accepted_by_human',
                decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write',
            ),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_acceptance_reported', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_only_no_ledger_write', $payload['authority']);
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.decision'));
        $this->assertSame('evidence-ledger-append-cli-or-worker', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.execution_surface'));
        $this->assertSame('receipt_schema_and_payload_hash', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.idempotency_key_strategy'));
        $this->assertSame('future_ledger_write_execution_ap_may_consume_receipt_without_ledger_write_here', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_execution_ledger_write_receipt_routes_change_request_to_repair(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes_requested_by_human',
                decision: 'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_changes',
            ),
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_returned_for_repair', $payload['status']);
        $this->assertSame('repair_result_persistence_execution_ledger_write_preflight_then_request_new_decision', $payload['next_action']);
    }

    public function test_result_persistence_execution_ledger_write_receipt_stops_on_rejection(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_rejected_by_human',
                decision: 'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write',
            ),
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_stopped_by_rejection', $payload['status']);
        $this->assertSame('stop_result_persistence_execution_ledger_write_path_until_scope_reopens', $payload['next_action']);
    }

    public function test_result_persistence_execution_ledger_write_receipt_blocks_when_decision_contract_blocks(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision',
                decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write',
            ),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_contract', $payload['status']);
        $this->assertSame('repair_result_persistence_execution_ledger_write_decision_before_receipt', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $status, string $decision): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_contract.v1',
            'status' => $status,
            'work_title' => 'Receipt result persistence execution ledger write decision',
            'resolved_target_ap' => 'AP-273',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_summary' => [
                'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight.v1',
                'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_review',
                'execution_surface' => 'evidence-ledger-append-cli-or-worker',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision' => [
                'value' => $decision,
                'reason' => 'Human reviewed ledger write decision for future receipt only.',
            ],
        ];
    }
}
