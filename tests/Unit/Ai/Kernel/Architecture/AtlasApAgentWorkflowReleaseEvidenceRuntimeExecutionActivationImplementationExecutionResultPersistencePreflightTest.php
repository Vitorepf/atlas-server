<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflight;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflightTest extends TestCase
{
    public function test_result_persistence_preflight_is_ready_after_accepted_receipt_and_evidence(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflight::class)->preflight(
            resultReceipt: $this->acceptedReceipt(),
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight.v1', $payload['schema_version']);
        $this->assertSame('ready_for_runtime_execution_activation_implementation_execution_result_persistence_review', $payload['status']);
        $this->assertSame('complete', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_preflight_evidence.status'));
        $this->assertSame('evidence_ledger_append_plan', data_get($payload, 'persistence_plan.persistence_target'));
        $this->assertSame('request_human_decision_before_any_result_persistence_or_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_preflight_blocks_when_receipt_is_not_accepted(): void
    {
        $receipt = $this->acceptedReceipt();
        $receipt['status'] = 'runtime_execution_activation_implementation_execution_result_returned_for_repair';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflight::class)->preflight(
            resultReceipt: $receipt,
            preflightEvidence: $this->passingPreflightEvidence(),
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_decision_receipt', $payload['status']);
        $this->assertSame('repair_or_accept_result_review_before_persistence_preflight', $payload['next_action']);
    }

    public function test_result_persistence_preflight_blocks_incomplete_evidence(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['confirmed_no_ledger_write'] = false;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflight::class)->preflight(
            resultReceipt: $this->acceptedReceipt(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_preflight_incomplete', $payload['status']);
        $this->assertSame(['confirmed_no_ledger_write'], data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_preflight_evidence.failed_keys'));
    }

    public function test_result_persistence_preflight_blocks_invalid_shape(): void
    {
        $evidence = $this->passingPreflightEvidence();
        $evidence['reviewed_runtime_execution_activation_implementation_execution_result_receipt'] = 'yes';
        $evidence['payload_hash'] = '';
        $evidence['extra'] = true;

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflight::class)->preflight(
            resultReceipt: $this->acceptedReceipt(),
            preflightEvidence: $evidence,
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_preflight_shape', $payload['status']);
        $this->assertSame(3, data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_preflight_evidence.shape_error_count'));
    }

    private function acceptedReceipt(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_decision_receipt.v1',
            'status' => 'runtime_execution_activation_implementation_execution_result_acceptance_reported',
            'work_title' => 'Preflight result persistence',
            'resolved_target_ap' => 'AP-263',
            'runtime_execution_activation_implementation_execution_result_decision_summary' => [
                'decision' => 'accept_runtime_execution_activation_implementation_execution_result',
                'result_status' => 'passed',
                'artifact_refs' => ['future-execution-artifact:fixture:001'],
                'executor_receipt_ref' => 'future-executor-receipt:fixture:001',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
        ];
    }

    private function passingPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_implementation_execution_result_receipt' => true,
            'declared_result_acceptance_reported' => true,
            'declared_persistence_target' => true,
            'declared_evidence_event_family' => true,
            'declared_payload_redaction' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_no_auto_persistence' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'persistence_target' => 'evidence_ledger_append_plan',
            'evidence_event_family' => 'AP_AGENT_RUNTIME_EXECUTION_RESULT',
            'redaction_strategy' => 'hash_payload_and_store_refs_only',
            'payload_hash' => 'fixture-result-payload-hash',
            'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
            'owner' => 'future-release-or-evidence-runtime',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
        ];
    }
}
