<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionHandoffPacketTest extends TestCase
{
    public function test_result_persistence_execution_handoff_is_ready_after_accepted_receipt_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionHandoffPacket::class)->packet(
            executionReceipt: $this->acceptedReceipt(),
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_packet.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_ready_for_future_ledger_ap', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_evidence.status'));
        $this->assertSame('AP-future-result-persistence-execution-ledger-write', data_get($payload, 'handoff_target.future_runtime_execution_activation_implementation_execution_result_persistence_execution_ap'));
        $this->assertSame('future-human-reviewed-inbox-action', data_get($payload, 'handoff_target.operator_confirmation_surface'));
        $this->assertSame('future_result_persistence_execution_ap_may_review_execution_handoff_without_auto_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_execution_handoff_blocks_when_receipt_is_not_accepted(): void
    {
        $receipt = $this->acceptedReceipt();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_returned_for_repair';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionHandoffPacket::class)->packet(
            executionReceipt: $receipt,
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_decision_receipt', $payload['status']);
        $this->assertSame('repair_or_accept_result_persistence_execution_decision_before_handoff', $payload['next_action']);
    }

    public function test_result_persistence_execution_handoff_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingHandoffEvidence();
        $evidence['confirmed_no_payload_executed'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionHandoffPacket::class)->packet(
            executionReceipt: $this->acceptedReceipt(),
            handoffEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_evidence_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_payload_executed'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_evidence.failed_keys'));
    }

    public function test_result_persistence_execution_handoff_blocks_invalid_shape(): void
    {
        $evidence = $this->passingHandoffEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_receipt'] = 'yes';
        $evidence['future_runtime_execution_activation_implementation_execution_result_persistence_execution_ap'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionHandoffPacket::class)->packet(
            executionReceipt: $this->acceptedReceipt(),
            handoffEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_evidence.shape_error_count'));
    }

    private function acceptedReceipt(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_decision_receipt.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_acceptance_reported',
            'work_title' => 'Prepare result persistence execution handoff',
            'resolved_target_ap' => 'AP-270',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_decision_summary' => [
                'decision' => 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution',
                'execution_surface' => 'evidence-ledger-append-cli-or-worker',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
        ];
    }

    private function passingHandoffEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_receipt' => true,
            'declared_future_runtime_execution_activation_implementation_execution_result_persistence_execution_ap' => true,
            'declared_runtime_execution_activation_implementation_execution_result_persistence_execution_package' => true,
            'confirmed_acceptance_receipt_only' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_idempotency_strategy_attached' => true,
            'confirmed_rollback_plan_attached' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ap' => 'AP-future-result-persistence-execution-ledger-write',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_package' => 'accepted execution receipt plus ledger write execution references',
            'owner' => 'future-release-or-evidence-runtime',
            'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_ref' => 'result-persistence-execution-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'receipt schema and payload hash',
        ];
    }
}
