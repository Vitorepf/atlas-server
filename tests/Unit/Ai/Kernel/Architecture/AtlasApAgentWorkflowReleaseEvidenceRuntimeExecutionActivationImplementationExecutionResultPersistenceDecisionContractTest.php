<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContractTest extends TestCase
{
    public function test_result_persistence_decision_accepts_ready_preflight_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract::class)->decide(
            persistencePreflight: $this->readyPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence',
            reason: 'Human accepts the persistence plan for a future receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_accepted_by_human', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_decision', $payload['mode']);
        $this->assertSame('evidence_ledger_append_plan', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_preflight_summary.persistence_target'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_decision.value'));
        $this->assertSame('future_result_persistence_receipt_ap_may_report_acceptance_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_decision_routes_change_request_with_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract::class)->decide(
            persistencePreflight: $this->readyPreflight(),
            decision: 'request_runtime_execution_activation_implementation_execution_result_persistence_changes',
            reason: 'Redaction strategy needs a stricter payload reference.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_result_persistence_preflight_before_new_decision', $payload['next_action']);
    }

    public function test_result_persistence_decision_blocks_invalid_decision_or_missing_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract::class)->decide(
            persistencePreflight: $this->readyPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence',
            reason: '',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision', $payload['status']);
        $this->assertSame('fix_result_persistence_decision_value_or_reason', $payload['next_action']);

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract::class)->decide(
            persistencePreflight: $this->readyPreflight(),
            decision: 'persist_now',
            reason: 'Invalid value must block.',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_decision', $payload['status']);
    }

    public function test_result_persistence_decision_blocks_when_preflight_is_not_ready(): void
    {
        $preflight = $this->readyPreflight();
        $preflight['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_preflight_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceDecisionContract::class)->decide(
            persistencePreflight: $preflight,
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence',
            reason: 'Human cannot accept incomplete persistence preflight.',
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_preflight', $payload['status']);
        $this->assertSame('repair_result_persistence_preflight_before_decision', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyPreflight(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight.v1',
            'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_review',
            'work_title' => 'Decide result persistence',
            'resolved_target_ap' => 'AP-264',
            'runtime_execution_activation_implementation_execution_result_receipt_summary' => [
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'persistence_plan' => [
                'persistence_target' => 'evidence_ledger_append_plan',
                'evidence_event_family' => 'AP_AGENT_RUNTIME_EXECUTION_RESULT',
                'redaction_strategy' => 'hash_payload_and_store_refs_only',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
            ],
        ];
    }
}
