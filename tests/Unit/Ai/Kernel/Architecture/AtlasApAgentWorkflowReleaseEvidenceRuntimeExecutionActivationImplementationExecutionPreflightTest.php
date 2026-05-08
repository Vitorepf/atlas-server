<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionPreflight;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionPreflightTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_preflight_is_ready_after_handoff_and_evidence(): void
    {
        $payload = $this->evaluate(
            handoffPacket: $this->passingHandoffPacket(),
            executionPreflightEvidence: $this->passingExecutionPreflightEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_preflight.v1', $payload['schema_version']);
        $this->assertSame('ready_for_runtime_execution_activation_implementation_execution_review', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_preflight', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_preflight_only_no_execution', $payload['authority']);
        $this->assertSame('runtime_execution_activation_implementation_handoff_ready_for_future_execution_ap', data_get($payload, 'runtime_execution_activation_implementation_handoff_summary.status'));
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_preflight_evidence.status'));
        $this->assertSame('sha256:runtime-payload-fixture', data_get($payload, 'runtime_execution_activation_implementation_execution_target.payload_hash'));
        $this->assertSame('human_may_review_runtime_execution_activation_implementation_execution_without_auto_execution', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_activation'));
    }

    public function test_runtime_execution_activation_implementation_execution_preflight_blocks_when_handoff_is_not_ready(): void
    {
        $handoffPacket = $this->passingHandoffPacket();
        $handoffPacket['status'] = 'runtime_execution_activation_implementation_handoff_evidence_incomplete';

        $payload = $this->evaluate(
            handoffPacket: $handoffPacket,
            executionPreflightEvidence: $this->passingExecutionPreflightEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_handoff_packet', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_handoff_before_execution_preflight', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_preflight_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingExecutionPreflightEvidence();
        $evidence['confirmed_no_command_execution'] = false;

        $payload = $this->evaluate(
            handoffPacket: $this->passingHandoffPacket(),
            executionPreflightEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_preflight_incomplete', $payload['status']);
        $this->assertSame('incomplete', data_get($payload, 'runtime_execution_activation_implementation_execution_preflight_evidence.status'));
        $this->assertSame(['confirmed_no_command_execution'], data_get($payload, 'runtime_execution_activation_implementation_execution_preflight_evidence.failed_keys'));
        $this->assertSame('complete_runtime_execution_activation_implementation_execution_preflight_evidence_before_review', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_preflight_blocks_invalid_shape(): void
    {
        $evidence = $this->passingExecutionPreflightEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_handoff_packet'] = 'yes';
        $evidence['payload_hash'] = '';
        $evidence['extra'] = true;

        $payload = $this->evaluate(
            handoffPacket: $this->passingHandoffPacket(),
            executionPreflightEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_preflight_shape', $payload['status']);
        $this->assertSame('invalid_shape', data_get($payload, 'runtime_execution_activation_implementation_execution_preflight_evidence.status'));
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_preflight_evidence.shape_error_count'));
        $this->assertSame('fix_runtime_execution_activation_implementation_execution_preflight_shape_before_review', $payload['next_action']);
    }

    /**
     * @param  array<string,mixed>  $handoffPacket
     * @param  array<string,mixed>  $executionPreflightEvidence
     * @return array<string,mixed>
     */
    private function evaluate(array $handoffPacket, array $executionPreflightEvidence): array
    {
        return app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionPreflight::class)->evaluate(
            handoffPacket: $handoffPacket,
            executionPreflightEvidence: $executionPreflightEvidence,
            workTitle: 'Preflight runtime execution activation implementation execution',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function passingHandoffPacket(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_handoff_packet.v1',
            'status' => 'runtime_execution_activation_implementation_handoff_ready_for_future_execution_ap',
            'work_title' => 'Prepare runtime execution activation implementation handoff',
            'resolved_target_ap' => ['status' => 'existing_ap'],
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_ap' => 'AP-future-runtime-execution-activation-implementation',
                'owner' => 'future-runtime-execution-activation-implementation',
            ],
            'runtime_execution_activation_implementation_package' => 'accepted runtime execution activation implementation receipt plus policy and rollback references',
            'runtime_execution_activation_implementation_receipt_ref' => 'runtime-execution-activation-implementation-receipt:fixture:001',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function passingExecutionPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_handoff_packet' => true,
            'declared_runtime_execution_activation_implementation_execution_scope' => true,
            'declared_runtime_execution_activation_implementation_payload_hash' => true,
            'declared_runtime_execution_activation_implementation_idempotency_key' => true,
            'declared_operator_confirmation_surface' => true,
            'confirmed_handoff_ready' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_runtime_payload_not_executed' => true,
            'confirmed_runtime_job_not_created' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_activation_side_effect' => true,
            'execution_scope' => 'future runtime execution activation implementation execution review only',
            'payload_hash' => 'sha256:runtime-payload-fixture',
            'idempotency_key' => 'runtime-activation-implementation:fixture:001',
            'operator_confirmation_surface' => 'human-review-required-before-any-runtime-execution',
            'owner' => 'future-runtime-execution-activation-implementation',
            'policy_receipt_source' => 'policy-receipt:fixture:001',
            'rollback_plan_ref' => 'rollback-plan:fixture:001',
        ];
    }
}
