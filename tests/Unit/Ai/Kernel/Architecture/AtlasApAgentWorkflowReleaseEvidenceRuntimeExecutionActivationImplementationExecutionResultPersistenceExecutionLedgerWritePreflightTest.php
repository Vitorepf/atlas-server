<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflight;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflightTest extends TestCase
{
    public function test_result_persistence_execution_ledger_write_preflight_is_ready_after_handoff_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflight::class)->preflight(
            executionHandoff: $this->readyHandoff(),
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight.v1', $payload['schema_version']);
        $this->assertSame('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_review', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_evidence.status'));
        $this->assertSame('evidence-ledger-append-cli-or-worker', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target.execution_surface'));
        $this->assertSame('request_human_decision_before_any_result_persistence_execution_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_execution_ledger_write_preflight_blocks_when_handoff_is_not_ready(): void
    {
        $handoff = $this->readyHandoff();
        $handoff['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_evidence_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflight::class)->preflight(
            executionHandoff: $handoff,
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_packet', $payload['status']);
        $this->assertSame('repair_result_persistence_execution_handoff_before_ledger_write_preflight', $payload['next_action']);
    }

    public function test_result_persistence_execution_ledger_write_preflight_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['confirmed_no_ledger_write'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflight::class)->preflight(
            executionHandoff: $this->readyHandoff(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_ledger_write'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_evidence.failed_keys'));
    }

    public function test_result_persistence_execution_ledger_write_preflight_blocks_invalid_shape(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff'] = 'yes';
        $evidence['ledger_write_plan_ref'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflight::class)->preflight(
            executionHandoff: $this->readyHandoff(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_evidence.shape_error_count'));
    }

    private function readyHandoff(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_packet.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_ready_for_future_execution_ap',
            'work_title' => 'Preflight result persistence execution ledger write',
            'resolved_target_ap' => 'AP-271',
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ap' => 'AP-future-result-persistence-execution-ledger-write',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary' => [
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

    private function passingPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff' => true,
            'declared_persistence_execution_handoff_ready' => true,
            'declared_ledger_write_execution_surface' => true,
            'declared_ledger_write_plan_ref' => true,
            'declared_idempotency_key_strategy' => true,
            'declared_operator_confirmation_surface' => true,
            'declared_rollback_plan' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_payload_hash_attached' => true,
            'confirmed_redaction_or_hash_strategy_attached' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_evidence_event_emitted' => true,
            'confirmed_no_payload_executed' => true,
            'execution_surface' => 'evidence-ledger-append-cli-or-worker',
            'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
            'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'payload_hash' => 'fixture-result-payload-hash',
            'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
            'owner' => 'future-release-or-evidence-runtime',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
        ];
    }
}
