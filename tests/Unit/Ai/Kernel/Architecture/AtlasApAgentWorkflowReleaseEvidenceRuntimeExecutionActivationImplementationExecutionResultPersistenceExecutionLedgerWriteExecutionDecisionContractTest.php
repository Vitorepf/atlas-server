<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContractTest extends TestCase
{
    public function test_result_persistence_execution_ledger_write_execution_decision_accepts_ready_preflight_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract::class)->decide(
            ledgerWriteExecutionPreflight: $this->readyPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution',
            reason: 'Human accepts future ledger write execution plan for receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_accepted_by_human', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision', $payload['mode']);
        $this->assertSame('evidence-ledger-append-cli-or-worker', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight_summary.execution_surface'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision.value'));
        $this->assertSame('future_ledger_write_execution_receipt_ap_may_report_acceptance_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_result_persistence_execution_ledger_write_execution_decision_routes_change_request_with_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract::class)->decide(
            ledgerWriteExecutionPreflight: $this->readyPreflight(),
            decision: 'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes',
            reason: 'Operator confirmation surface needs one more reference.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_result_persistence_execution_ledger_write_execution_preflight_before_new_decision', $payload['next_action']);
    }

    public function test_result_persistence_execution_ledger_write_execution_decision_blocks_invalid_decision_or_missing_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract::class)->decide(
            ledgerWriteExecutionPreflight: $this->readyPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution',
            reason: '',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision', $payload['status']);
        $this->assertSame('fix_result_persistence_execution_ledger_write_execution_decision_value_or_reason', $payload['next_action']);

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract::class)->decide(
            ledgerWriteExecutionPreflight: $this->readyPreflight(),
            decision: 'execute_ledger_write_now',
            reason: 'Invalid value must block.',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_decision', $payload['status']);
    }

    public function test_result_persistence_execution_ledger_write_execution_decision_blocks_when_preflight_is_not_ready(): void
    {
        $preflight = $this->readyPreflight();
        $preflight['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDecisionContract::class)->decide(
            ledgerWriteExecutionPreflight: $preflight,
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution',
            reason: 'Human cannot accept incomplete ledger write execution preflight.',
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight', $payload['status']);
        $this->assertSame('repair_result_persistence_execution_ledger_write_execution_preflight_before_decision', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyPreflight(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_preflight.v1',
            'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_review',
            'work_title' => 'Decide result persistence execution ledger write execution',
            'resolved_target_ap' => 'AP-276',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_summary' => [
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_target' => [
                'execution_surface' => 'evidence-ledger-append-cli-or-worker',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
            ],
        ];
    }
}
