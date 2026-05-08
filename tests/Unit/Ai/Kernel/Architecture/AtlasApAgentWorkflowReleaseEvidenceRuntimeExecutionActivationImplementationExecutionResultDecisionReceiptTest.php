<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceiptTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_result_receipt_reports_acceptance_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceipt::class)->receipt(
            reviewPayload: $this->reviewPayload('runtime_execution_activation_implementation_execution_result_accepted_by_human', 'accept_runtime_execution_activation_implementation_execution_result'),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_decision_receipt.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_acceptance_reported', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_decision_receipt', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_receipt_only_no_ledger_write', $payload['authority']);
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result', data_get($payload, 'runtime_execution_activation_implementation_execution_result_decision_summary.decision'));
        $this->assertSame('passed', data_get($payload, 'runtime_execution_activation_implementation_execution_result_decision_summary.result_status'));
        $this->assertSame('future_result_persistence_ap_may_consume_receipt_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_runtime_execution_activation_implementation_execution_result_receipt_routes_change_request_to_repair(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceipt::class)->receipt(
            reviewPayload: $this->reviewPayload('runtime_execution_activation_implementation_execution_result_changes_requested_by_human', 'request_runtime_execution_activation_implementation_execution_result_changes'),
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_returned_for_repair', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_result_envelope_then_request_new_review', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_result_receipt_stops_on_rejection(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceipt::class)->receipt(
            reviewPayload: $this->reviewPayload('runtime_execution_activation_implementation_execution_result_rejected_by_human', 'reject_runtime_execution_activation_implementation_execution_result'),
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_stopped_by_rejection', $payload['status']);
        $this->assertSame('stop_runtime_execution_activation_implementation_execution_result_flow_until_scope_reopens', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_result_receipt_blocks_when_review_contract_blocks(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultDecisionReceipt::class)->receipt(
            reviewPayload: $this->reviewPayload('blocked_invalid_runtime_execution_activation_implementation_execution_result_review_decision', 'accept_runtime_execution_activation_implementation_execution_result'),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_review_contract', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_result_review_before_receipt', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewPayload(string $status, string $decision): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_review_contract.v1',
            'status' => $status,
            'work_title' => 'Receipt runtime execution activation implementation execution result review',
            'resolved_target_ap' => 'AP-262',
            'runtime_execution_activation_implementation_execution_result_envelope_summary' => [
                'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_envelope_contract.v1',
                'status' => 'runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review',
                'result_status' => 'passed',
                'artifact_refs' => ['future-execution-artifact:fixture:001'],
                'executor_receipt_ref' => 'future-executor-receipt:fixture:001',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'runtime_execution_activation_implementation_execution_result_decision' => [
                'value' => $decision,
                'reason' => 'Human reviewed declared result envelope for future receipt only.',
            ],
        ];
    }
}
