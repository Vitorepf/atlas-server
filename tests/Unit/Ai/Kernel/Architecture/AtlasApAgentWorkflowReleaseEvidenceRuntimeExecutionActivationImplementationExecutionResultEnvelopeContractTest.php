<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContractTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_result_envelope_is_ready_after_handoff_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContract::class)->envelope(
            handoffPacket: $this->readyHandoff(),
            resultEvidence: $this->passingResultEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_envelope_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_envelope', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_envelope_only_no_execution', $payload['authority']);
        $this->assertSame('runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap', data_get($payload, 'runtime_execution_activation_implementation_execution_handoff_summary.status'));
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_evidence.status'));
        $this->assertSame('passed', data_get($payload, 'result_summary.result_status'));
        $this->assertSame('review_runtime_execution_activation_implementation_execution_result_before_any_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_runtime_execution_activation_implementation_execution_result_envelope_blocks_when_handoff_is_not_ready(): void
    {
        $handoff = $this->readyHandoff();
        $handoff['status'] = 'blocked_by_runtime_execution_activation_implementation_execution_decision_receipt';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContract::class)->envelope(
            handoffPacket: $handoff,
            resultEvidence: $this->passingResultEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_handoff_packet', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_handoff_before_result_envelope', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_result_envelope_blocks_incomplete_evidence(): void
    {
        $resultEvidence = $this->passingResultEvidence();
        $resultEvidence['confirmed_no_payload_executed_by_this_contract'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContract::class)->envelope(
            handoffPacket: $this->readyHandoff(),
            resultEvidence: $resultEvidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_evidence_incomplete', $payload['status']);
        $this->assertSame('incomplete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_evidence.status'));
        $this->assertSame(['confirmed_no_payload_executed_by_this_contract'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_evidence.failed_keys'));
        $this->assertSame('complete_runtime_execution_activation_implementation_execution_result_evidence_before_review', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_result_envelope_blocks_invalid_shape(): void
    {
        $resultEvidence = $this->passingResultEvidence();
        $resultEvidence['reviewed_runtime_execution_activation_implementation_execution_handoff'] = 'yes';
        $resultEvidence['result_status'] = '';
        $resultEvidence['artifact_refs'] = [];
        $resultEvidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContract::class)->envelope(
            handoffPacket: $this->readyHandoff(),
            resultEvidence: $resultEvidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_shape', $payload['status']);
        $this->assertSame('invalid_shape', data_get($payload, 'runtime_execution_activation_implementation_execution_result_evidence.status'));
        $this->assertSame(4, data_get($payload, 'runtime_execution_activation_implementation_execution_result_evidence.shape_error_count'));
        $this->assertSame('fix_runtime_execution_activation_implementation_execution_result_shape_before_review', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyHandoff(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_handoff_packet.v1',
            'status' => 'runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap',
            'work_title' => 'Prepare runtime execution activation implementation execution result envelope',
            'resolved_target_ap' => 'AP-260',
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_ap' => 'AP-future-runtime-execution-activation-implementation-execution',
                'owner' => 'future-release-or-evidence-runtime',
            ],
            'runtime_execution_activation_implementation_execution_package' => 'accepted execution handoff package',
            'runtime_execution_activation_implementation_execution_receipt_ref' => 'runtime-execution-activation-implementation-execution-receipt:fixture:001',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function passingResultEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_handoff' => true,
            'declared_runtime_execution_activation_implementation_execution_result' => true,
            'declared_result_status' => true,
            'declared_result_artifacts' => true,
            'declared_result_validation_summary' => true,
            'confirmed_execution_happened_outside_this_contract' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_preserved' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed_by_this_contract' => true,
            'result_status' => 'passed',
            'result_summary' => 'Future executor reported completion; this AP only sealed the declared result envelope.',
            'result_validation_summary' => 'Focused tests, docs-health and architecture-validate were reported by the future executor.',
            'artifact_refs' => ['future-execution-artifact:fixture:001'],
            'executor_receipt_ref' => 'future-executor-receipt:fixture:001',
            'owner' => 'future-release-or-evidence-runtime',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
        ];
    }
}
