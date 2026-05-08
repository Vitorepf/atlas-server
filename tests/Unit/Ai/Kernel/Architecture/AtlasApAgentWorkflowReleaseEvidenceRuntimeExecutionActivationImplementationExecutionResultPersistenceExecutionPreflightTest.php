<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionPreflight;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionPreflightTest extends TestCase
{
    public function test_result_persistence_execution_preflight_is_ready_after_acceptance_receipt_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionPreflight::class)->preflight(
            persistenceHandoff: $this->readyHandoff(),
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_preflight.v1', $payload['schema_version']);
        $this->assertSame('ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_review', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_evidence.status'));
        $this->assertSame('evidence-ledger-append-cli-or-worker', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_target.execution_surface'));
        $this->assertSame('request_human_decision_before_any_result_persistence_execution_or_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_execution_preflight_blocks_when_handoff_is_not_ready(): void
    {
        $receipt = $this->readyHandoff();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionPreflight::class)->preflight(
            persistenceHandoff: $receipt,
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_handoff_packet', $payload['status']);
        $this->assertSame('repair_result_persistence_handoff_before_execution_preflight', $payload['next_action']);
    }

    public function test_result_persistence_execution_preflight_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['confirmed_no_ledger_write'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionPreflight::class)->preflight(
            persistenceHandoff: $this->readyHandoff(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_ledger_write'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_evidence.failed_keys'));
    }

    public function test_result_persistence_execution_preflight_blocks_invalid_shape(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_persistence_handoff'] = 'yes';
        $evidence['execution_surface'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionPreflight::class)->preflight(
            persistenceHandoff: $this->readyHandoff(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_preflight_evidence.shape_error_count'));
    }

    private function readyHandoff(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_handoff_packet.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_persistence_handoff_ready_for_future_ledger_ap',
            'work_title' => 'Preflight result persistence execution',
            'resolved_target_ap' => 'AP-267',
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_result_persistence_ap' => 'AP-future-result-persistence-execution',
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_receipt_summary' => [
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

    private function passingPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_result_persistence_handoff' => true,
            'declared_persistence_handoff_ready' => true,
            'declared_execution_surface' => true,
            'declared_idempotency_key_strategy' => true,
            'declared_payload_redaction_verified' => true,
            'declared_rollback_plan' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
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
