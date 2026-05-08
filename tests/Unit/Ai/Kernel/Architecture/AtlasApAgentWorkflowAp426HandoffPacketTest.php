<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowAp426HandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowAp426HandoffPacketTest extends TestCase
{
    public function test_ready_handoff_packet_is_reported(): void
    {
        $payload = app(AtlasApAgentWorkflowAp426HandoffPacket::class)->packet(
            executorPayloadExecutionReceipt: $this->r(),
            handoffEvidence: $this->h(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_packet.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence.status'));
        $this->assertSame('AP-future-real-durable-ledger-write-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-handoff', data_get($payload, 'handoff_target.future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ap'));
        $this->assertSame('future-receipt-destination:fixture:001', data_get($payload, 'handoff_target.receipt_destination_ref'));
        $this->assertSame('future_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ap_may_review_handoff_without_auto_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_unaccepted_receipt_blocks_handoff(): void
    {
        $receipt = $this->r();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair';

        $payload = app(AtlasApAgentWorkflowAp426HandoffPacket::class)->packet($receipt, $this->h());

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt', $payload['status']);
        $this->assertSame('repair_or_accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_before_handoff', $payload['next_action']);
    }

    public function test_incomplete_evidence_marks_handoff_attention(): void
    {
        $evidence = $this->h();
        $evidence['confirmed_no_ledger_write'] = false;

        $payload = app(AtlasApAgentWorkflowAp426HandoffPacket::class)->packet($this->r(), $evidence);

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_ledger_write'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence.failed_keys'));
    }

    public function test_invalid_handoff_shape_blocks_packet(): void
    {
        $evidence = $this->h();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt'] = 'yes';
        $evidence['payload_hash'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowAp426HandoffPacket::class)->packet($this->r(), $evidence);

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence.shape_error_count'));
    }

    /**
     * @return array<string,mixed>
     */
    private function r(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported',
            'work_title' => 'Prepare executor payload execution execution execution execution execution execution execution execution execution execution execution execution handoff for real durable ledger write execution',
            'resolved_target_ap' => 'AP-426',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_summary' => [
                'decision' => 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution',
                'future_ap' => 'AP-426',
                'real_execution_surface' => 'evidence-ledger-append-worker-confirmed-runtime',
                'append_only_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_or_replay_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'rate_limit_or_budget_guard_ref' => 'runtime-budget-guard:fixture:001',
                'runtime_observability_guard_ref' => 'runtime-observability-guard:fixture:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_receipt_ref' => 'result-persistence-execution-ledger-write-execution-receipt:fixture:001',
                'receipt_destination_ref' => 'future-receipt-destination:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function h(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt' => true,
            'declared_future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ap' => true,
            'declared_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_package' => true,
            'confirmed_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_receipt_only' => true,
            'confirmed_append_only_write_plan_attached' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_idempotency_strategy_attached' => true,
            'confirmed_rollback_or_replay_plan_attached' => true,
            'confirmed_rate_limit_or_budget_guard_attached' => true,
            'confirmed_runtime_observability_guard_attached' => true,
            'confirmed_payload_hash_attached' => true,
            'confirmed_receipt_destination_attached' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_evidence_event_emitted' => true,
            'confirmed_no_payload_executed' => true,
            'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_ap' => 'AP-future-real-durable-ledger-write-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-handoff',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_package' => 'accepted executor payload execution execution execution execution execution execution execution execution execution execution execution execution receipt plus runtime guard references',
            'real_execution_surface' => 'evidence-ledger-append-worker-confirmed-runtime',
            'append_only_write_plan_ref' => 'ledger-write-plan:fixture:001',
            'owner' => 'future-release-or-evidence-runtime',
            'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_ref' => 'real-durable-execution-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-decision-receipt:fixture:001',
            'ledger_write_receipt_ref' => 'result-persistence-execution-ledger-write-execution-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_or_replay_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'rate_limit_or_budget_guard_ref' => 'runtime-budget-guard:fixture:001',
            'runtime_observability_guard_ref' => 'runtime-observability-guard:fixture:001',
            'receipt_destination_ref' => 'future-receipt-destination:fixture:001',
            'idempotency_key_strategy' => 'receipt schema and payload hash',
            'payload_hash' => 'fixture-result-payload-hash',
        ];
    }
}
