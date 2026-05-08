<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflightTest extends TestCase
{
    public function test_a(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight::class)->preflight(
            executorPayloadHandoff: $this->readyHandoff(),
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_preflight.v1', $payload['schema_version']);
        $this->assertSame('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_review', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_preflight_evidence.status'));
        $this->assertSame('evidence-ledger-append-worker-confirmed-runtime', data_get($payload, 'real_durable_execution_executor_payload_execution_target.real_execution_surface'));
        $this->assertSame('request_human_decision_before_any_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_handoff', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_b(): void
    {
        $handoff = $this->readyHandoff();
        $handoff['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_evidence_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight::class)->preflight(
            executorPayloadHandoff: $handoff,
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_packet', $payload['status']);
        $this->assertSame('repair_real_durable_execution_executor_payload_execution_execution_handoff_before_preflight', $payload['next_action']);
    }

    public function test_c(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['confirmed_no_payload_executed'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight::class)->preflight(
            executorPayloadHandoff: $this->readyHandoff(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_preflight_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_payload_executed'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_preflight_evidence.failed_keys'));
    }

    public function test_d(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['declared_runtime_observability_guard'] = 'yes';
        $evidence['payload_hash'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionPreflight::class)->preflight(
            executorPayloadHandoff: $this->readyHandoff(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_preflight_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_preflight_evidence.shape_error_count'));
    }

    /**
     * @return array<string,mixed>
     */
    private function readyHandoff(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_packet.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap',
            'work_title' => 'Preflight real durable ledger write executor payload execution execution execution execution',
            'resolved_target_ap' => 'AP-307',
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_ap' => 'AP-future-real-durable-ledger-write-executor-payload-execution-execution-execution',
                'owner' => 'future-release-or-evidence-runtime',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'receipt_destination_ref' => 'future-receipt-destination:fixture:001',
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_receipt_summary' => [
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
    private function passingPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_handoff' => true,
            'declared_handoff_ready_for_future_execution_ap' => true,
            'declared_real_execution_surface' => true,
            'declared_append_only_write_plan' => true,
            'declared_idempotency_key_strategy' => true,
            'declared_payload_hash_verified' => true,
            'declared_policy_receipt_attached' => true,
            'declared_operator_confirmation_surface' => true,
            'declared_rollback_or_replay_plan' => true,
            'declared_rate_limit_or_budget_guard' => true,
            'declared_runtime_observability_guard' => true,
            'declared_receipt_destination' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_evidence_event_emitted' => true,
            'confirmed_no_payload_executed' => true,
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
        ];
    }
}
