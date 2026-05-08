<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacketTest extends TestCase
{
    public function test_result_persistence_execution_ledger_write_handoff_is_ready_after_accepted_receipt_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacket::class)->packet(
            ledgerWriteReceipt: $this->acceptedReceipt(),
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_packet.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_ready_for_future_execution_ap', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence.status'));
        $this->assertSame('AP-future-ledger-write-execution', data_get($payload, 'handoff_target.future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap'));
        $this->assertSame('future-human-reviewed-inbox-action', data_get($payload, 'handoff_target.operator_confirmation_surface'));
        $this->assertSame('future_ledger_write_execution_ap_may_review_handoff_without_auto_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_execution_ledger_write_handoff_blocks_when_receipt_is_not_accepted(): void
    {
        $receipt = $this->acceptedReceipt();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_returned_for_repair';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacket::class)->packet(
            ledgerWriteReceipt: $receipt,
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt', $payload['status']);
        $this->assertSame('repair_or_accept_result_persistence_execution_ledger_write_decision_before_handoff', $payload['next_action']);
    }

    public function test_result_persistence_execution_ledger_write_handoff_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingHandoffEvidence();
        $evidence['confirmed_no_payload_executed'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacket::class)->packet(
            ledgerWriteReceipt: $this->acceptedReceipt(),
            handoffEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_payload_executed'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence.failed_keys'));
    }

    public function test_result_persistence_execution_ledger_write_handoff_blocks_invalid_shape(): void
    {
        $evidence = $this->passingHandoffEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt'] = 'yes';
        $evidence['future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacket::class)->packet(
            ledgerWriteReceipt: $this->acceptedReceipt(),
            handoffEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence.shape_error_count'));
    }

    private function acceptedReceipt(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_acceptance_reported',
            'work_title' => 'Prepare result persistence execution ledger write handoff',
            'resolved_target_ap' => 'AP-274',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary' => [
                'decision' => 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write',
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
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt' => true,
            'declared_future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap' => true,
            'declared_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package' => true,
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
            'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap' => 'AP-future-ledger-write-execution',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package' => 'accepted ledger write receipt plus future execution references',
            'owner' => 'future-release-or-evidence-runtime',
            'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_ref' => 'result-persistence-execution-ledger-write-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'receipt schema and payload hash',
        ];
    }
}
