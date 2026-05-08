<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacketTest extends TestCase
{
    public function test_result_persistence_handoff_is_ready_after_accepted_receipt_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacket::class)->packet(
            persistenceReceipt: $this->acceptedReceipt(),
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_handoff_packet.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_handoff_ready_for_future_ledger_ap', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence.status'));
        $this->assertSame('AP-future-result-persistence-execution', data_get($payload, 'handoff_target.future_runtime_execution_activation_implementation_execution_result_persistence_ap'));
        $this->assertSame('future_result_persistence_execution_ap_may_review_handoff_without_auto_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_handoff_blocks_when_receipt_is_not_accepted(): void
    {
        $receipt = $this->acceptedReceipt();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_returned_for_repair';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacket::class)->packet(
            persistenceReceipt: $receipt,
            handoffEvidence: $this->passingHandoffEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt', $payload['status']);
        $this->assertSame('repair_or_accept_result_persistence_decision_before_handoff', $payload['next_action']);
    }

    public function test_result_persistence_handoff_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingHandoffEvidence();
        $evidence['confirmed_no_payload_executed'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacket::class)->packet(
            persistenceReceipt: $this->acceptedReceipt(),
            handoffEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_payload_executed'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence.failed_keys'));
    }

    public function test_result_persistence_handoff_blocks_invalid_shape(): void
    {
        $evidence = $this->passingHandoffEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_persistence_receipt'] = 'yes';
        $evidence['future_runtime_execution_activation_implementation_execution_result_persistence_ap'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacket::class)->packet(
            persistenceReceipt: $this->acceptedReceipt(),
            handoffEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_handoff_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence.shape_error_count'));
    }

    private function acceptedReceipt(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_acceptance_reported',
            'work_title' => 'Prepare result persistence handoff',
            'resolved_target_ap' => 'AP-266',
            'runtime_execution_activation_implementation_execution_result_persistence_decision_summary' => [
                'decision' => 'accept_runtime_execution_activation_implementation_execution_result_persistence',
                'persistence_target' => 'evidence_ledger_append_plan',
                'evidence_event_family' => 'AP_AGENT_RUNTIME_EXECUTION_RESULT',
                'redaction_strategy' => 'hash_payload_and_store_refs_only',
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
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_receipt' => true,
            'declared_future_runtime_execution_activation_implementation_execution_result_persistence_ap' => true,
            'declared_runtime_execution_activation_implementation_execution_result_persistence_package' => true,
            'confirmed_acceptance_receipt_only' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'future_runtime_execution_activation_implementation_execution_result_persistence_ap' => 'AP-future-result-persistence-execution',
            'runtime_execution_activation_implementation_execution_result_persistence_package' => 'accepted result persistence receipt plus ledger plan references',
            'owner' => 'future-release-or-evidence-runtime',
            'runtime_execution_activation_implementation_execution_result_persistence_receipt_ref' => 'result-persistence-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'result persistence receipt hash plus payload hash',
        ];
    }
}
