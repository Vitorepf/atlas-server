<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContractTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_result_review_accepts_ready_envelope_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract::class)->decide(
            resultEnvelope: $this->readyEnvelope(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result',
            reason: 'Human accepted declared result envelope for future receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_review_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_accepted_by_human', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_review', $payload['mode']);
        $this->assertSame('passed', data_get($payload, 'runtime_execution_activation_implementation_execution_result_envelope_summary.result_status'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result', data_get($payload, 'runtime_execution_activation_implementation_execution_result_decision.value'));
        $this->assertSame('emit_runtime_execution_activation_implementation_execution_result_receipt_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_runtime_execution_activation_implementation_execution_result_review_routes_change_request_with_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract::class)->decide(
            resultEnvelope: $this->readyEnvelope(),
            decision: 'request_runtime_execution_activation_implementation_execution_result_changes',
            reason: 'Add missing artifact reference before receipt.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_result_envelope_then_review_again', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_result_review_blocks_invalid_decision_or_missing_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract::class)->decide(
            resultEnvelope: $this->readyEnvelope(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result',
            reason: '',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_review_decision', $payload['status']);
        $this->assertSame('provide_valid_result_review_decision_and_reason', $payload['next_action']);

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract::class)->decide(
            resultEnvelope: $this->readyEnvelope(),
            decision: 'ship_it',
            reason: 'Invalid value must block.',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_review_decision', $payload['status']);
    }

    public function test_runtime_execution_activation_implementation_execution_result_review_blocks_when_envelope_is_not_ready(): void
    {
        $envelope = $this->readyEnvelope();
        $envelope['status'] = 'runtime_execution_activation_implementation_execution_result_evidence_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultReviewContract::class)->decide(
            resultEnvelope: $envelope,
            decision: 'accept_runtime_execution_activation_implementation_execution_result',
            reason: 'Human cannot accept incomplete envelope.',
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_envelope', $payload['status']);
        $this->assertSame('repair_result_envelope_before_human_review', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyEnvelope(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_envelope_contract.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review',
            'work_title' => 'Review runtime execution activation implementation execution result',
            'resolved_target_ap' => 'AP-261',
            'result_summary' => [
                'result_status' => 'passed',
                'artifact_refs' => ['future-execution-artifact:fixture:001'],
                'executor_receipt_ref' => 'future-executor-receipt:fixture:001',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
        ];
    }
}
