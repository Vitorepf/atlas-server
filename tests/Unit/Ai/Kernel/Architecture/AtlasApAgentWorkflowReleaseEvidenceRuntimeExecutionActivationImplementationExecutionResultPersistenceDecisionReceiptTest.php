<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionReceiptTest extends TestCase
{
    public function test_result_persistence_receipt_reports_acceptance_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'runtime_execution_activation_implementation_execution_result_persistence_accepted_by_human',
                decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence',
            ),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_acceptance_reported', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_receipt_only_no_ledger_write', $payload['authority']);
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.decision'));
        $this->assertSame('evidence_ledger_append_plan', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.persistence_target'));
        $this->assertSame('future_result_persistence_execution_ap_may_consume_receipt_without_ledger_write_here', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_receipt_routes_change_request_to_repair(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'runtime_execution_activation_implementation_execution_result_persistence_changes_requested_by_human',
                decision: 'request_runtime_execution_activation_implementation_execution_result_persistence_changes',
            ),
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_returned_for_repair', $payload['status']);
        $this->assertSame('repair_result_persistence_preflight_then_request_new_decision', $payload['next_action']);
    }

    public function test_result_persistence_receipt_stops_on_rejection(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'runtime_execution_activation_implementation_execution_result_persistence_rejected_by_human',
                decision: 'reject_runtime_execution_activation_implementation_execution_result_persistence',
            ),
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_stopped_by_rejection', $payload['status']);
        $this->assertSame('stop_result_persistence_path_until_scope_reopens', $payload['next_action']);
    }

    public function test_result_persistence_receipt_blocks_when_decision_contract_blocks(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionReceipt::class)->receipt(
            decisionPayload: $this->decisionPayload(
                status: 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision',
                decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence',
            ),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_decision_contract', $payload['status']);
        $this->assertSame('repair_result_persistence_decision_before_receipt', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $status, string $decision): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_contract.v1',
            'status' => $status,
            'work_title' => 'Receipt result persistence decision',
            'resolved_target_ap' => 'AP-265',
            'runtime_execution_activation_implementation_execution_result_persistence_preflight_summary' => [
                'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight.v1',
                'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_review',
                'persistence_target' => 'evidence_ledger_append_plan',
                'evidence_event_family' => 'AP_AGENT_RUNTIME_EXECUTION_RESULT',
                'redaction_strategy' => 'hash_payload_and_store_refs_only',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_decision' => [
                'value' => $decision,
                'reason' => 'Human reviewed persistence decision for future receipt only.',
            ],
        ];
    }
}
