<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionReceiptTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_receipt_reports_acceptance_without_execution(): void
    {
        $payload = $this->receipt($this->decisionPayload('runtime_execution_activation_implementation_execution_accepted_by_human'));

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_receipt.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_acceptance_reported', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_decision_receipt', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_receipt_only_no_execution', $payload['authority']);
        $this->assertSame('runtime_execution_activation_implementation_execution_accepted_by_human', data_get($payload, 'runtime_execution_activation_implementation_execution_decision_summary.status'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution', data_get($payload, 'runtime_execution_activation_implementation_execution_decision_summary.decision'));
        $this->assertSame('sha256:runtime-payload-fixture', data_get($payload, 'runtime_execution_activation_implementation_execution_decision_summary.payload_hash'));
        $this->assertSame('future_runtime_execution_activation_implementation_execution_ap_may_consume_decision_receipt_without_execution', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_runtime_execution_activation_implementation_execution_receipt_routes_change_request_to_repair(): void
    {
        $payload = $this->receipt($this->decisionPayload(
            'runtime_execution_activation_implementation_execution_changes_requested_by_human',
            'request_runtime_execution_activation_implementation_execution_changes',
        ));

        $this->assertSame('runtime_execution_activation_implementation_execution_returned_for_repair', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_preflight_then_request_new_human_decision', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_receipt_stops_on_rejection(): void
    {
        $payload = $this->receipt($this->decisionPayload(
            'runtime_execution_activation_implementation_execution_rejected_by_human',
            'reject_runtime_execution_activation_implementation_execution',
        ));

        $this->assertSame('runtime_execution_activation_implementation_execution_stopped_by_rejection', $payload['status']);
        $this->assertSame('stop_runtime_execution_activation_implementation_execution_flow_until_scope_reopens', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_receipt_blocks_when_decision_contract_blocks(): void
    {
        $payload = $this->receipt($this->decisionPayload(
            'blocked_invalid_runtime_execution_activation_implementation_execution_decision',
            'execute_now',
        ));

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_decision_contract', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_decision_before_receipt', $payload['next_action']);
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     * @return array<string,mixed>
     */
    private function receipt(array $decisionPayload): array
    {
        return app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionReceipt::class)->receipt($decisionPayload);
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(
        string $status,
        string $decision = 'accept_runtime_execution_activation_implementation_execution',
    ): array {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_contract.v1',
            'status' => $status,
            'work_title' => 'Decide runtime execution activation implementation execution',
            'resolved_target_ap' => ['status' => 'existing_ap'],
            'runtime_execution_activation_implementation_execution_preflight_summary' => [
                'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_preflight.v1',
                'status' => 'ready_for_runtime_execution_activation_implementation_execution_review',
                'execution_scope' => 'future runtime execution activation implementation execution review only',
                'payload_hash' => 'sha256:runtime-payload-fixture',
                'idempotency_key' => 'runtime-activation-implementation:fixture:001',
                'operator_confirmation_surface' => 'human-review-required-before-any-runtime-execution',
                'owner' => 'future-runtime-execution-activation-implementation',
                'policy_receipt_source' => 'policy-receipt:fixture:001',
                'rollback_plan_ref' => 'rollback-plan:fixture:001',
            ],
            'runtime_execution_activation_implementation_execution_decision' => [
                'value' => $decision,
                'reason' => 'Human reviewed runtime execution activation implementation execution.',
                'status' => str_starts_with($status, 'blocked_') ? 'invalid' : 'valid',
            ],
        ];
    }
}
