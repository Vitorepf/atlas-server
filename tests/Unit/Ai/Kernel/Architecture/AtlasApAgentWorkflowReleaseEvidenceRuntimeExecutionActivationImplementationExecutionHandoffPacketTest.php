<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacketTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_handoff_is_ready_after_accepted_receipt_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacket::class)->packet(
            decisionReceipt: $this->acceptedReceipt(),
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_handoff_packet.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_handoff_packet', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_handoff_only_no_execution', $payload['authority']);
        $this->assertSame('runtime_execution_activation_implementation_execution_acceptance_reported', data_get($payload, 'runtime_execution_activation_implementation_execution_receipt_summary.status'));
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_handoff_evidence.status'));
        $this->assertSame('AP-future-runtime-execution-activation-implementation-execution', data_get($payload, 'handoff_target.future_runtime_execution_activation_implementation_execution_ap'));
        $this->assertSame('future_runtime_execution_activation_implementation_execution_ap_may_review_handoff_without_auto_execution', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_activation'));
    }

    public function test_runtime_execution_activation_implementation_execution_handoff_blocks_when_receipt_is_not_accepted(): void
    {
        $receipt = $this->acceptedReceipt();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_returned_for_repair';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacket::class)->packet(
            decisionReceipt: $receipt,
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_decision_receipt', $payload['status']);
        $this->assertSame('repair_or_accept_runtime_execution_activation_implementation_execution_decision_before_handoff', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_handoff_blocks_incomplete_evidence(): void
    {
        $handoffEvidence = $this->passingHandoffEvidence();
        $handoffEvidence['confirmed_no_payload_executed'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacket::class)->packet(
            decisionReceipt: $this->acceptedReceipt(),
            handoffEvidence: $handoffEvidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_handoff_evidence_incomplete', $payload['status']);
        $this->assertSame('incomplete', data_get($payload, 'runtime_execution_activation_implementation_execution_handoff_evidence.status'));
        $this->assertSame(['confirmed_no_payload_executed'], data_get($payload, 'runtime_execution_activation_implementation_execution_handoff_evidence.failed_keys'));
        $this->assertSame('complete_runtime_execution_activation_implementation_execution_handoff_evidence_before_review', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_handoff_blocks_invalid_shape(): void
    {
        $handoffEvidence = $this->passingHandoffEvidence();
        $handoffEvidence['reviewed_runtime_execution_activation_implementation_execution_receipt'] = 'yes';
        $handoffEvidence['future_runtime_execution_activation_implementation_execution_ap'] = '';
        $handoffEvidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacket::class)->packet(
            decisionReceipt: $this->acceptedReceipt(),
            handoffEvidence: $handoffEvidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_handoff_shape', $payload['status']);
        $this->assertSame('invalid_shape', data_get($payload, 'runtime_execution_activation_implementation_execution_handoff_evidence.status'));
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_handoff_evidence.shape_error_count'));
        $this->assertSame('fix_runtime_execution_activation_implementation_execution_handoff_shape_before_review', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptedReceipt(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_receipt.v1',
            'status' => 'runtime_execution_activation_implementation_execution_acceptance_reported',
            'work_title' => 'Prepare runtime execution activation implementation execution handoff',
            'resolved_target_ap' => 'AP-259',
            'runtime_execution_activation_implementation_execution_decision_summary' => [
                'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_contract.v1',
                'status' => 'runtime_execution_activation_implementation_execution_accepted_by_human',
                'decision' => 'accept_runtime_execution_activation_implementation_execution',
                'reason' => 'Human accepted runtime execution activation implementation execution review for future handoff only.',
                'execution_scope' => 'fixture-runtime-execution-activation-implementation-execution-scope',
                'payload_hash' => 'fixture-payload-hash',
                'idempotency_key' => 'fixture-idempotency-key',
                'operator_confirmation_surface' => 'fixture-operator-confirmation-surface',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function passingHandoffEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_receipt' => true,
            'declared_future_runtime_execution_activation_implementation_execution_ap' => true,
            'declared_runtime_execution_activation_implementation_execution_package' => true,
            'confirmed_acceptance_receipt_only' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'future_runtime_execution_activation_implementation_execution_ap' => 'AP-future-runtime-execution-activation-implementation-execution',
            'runtime_execution_activation_implementation_execution_package' => 'accepted runtime execution activation implementation execution receipt plus execution package references',
            'owner' => 'future-release-or-evidence-runtime',
            'runtime_execution_activation_implementation_execution_receipt_ref' => 'runtime-execution-activation-implementation-execution-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'runtime execution activation implementation execution receipt hash plus payload hash',
        ];
    }
}
