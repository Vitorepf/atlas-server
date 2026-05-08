<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContractTest extends TestCase
{
    public function test_durable_ledger_write_execution_decision_accepts_ready_preflight_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract::class)->decide(
            durablePreflight: $this->readyDurablePreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable',
            reason: 'Human accepts durable ledger write execution preflight for future receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_accepted_by_human', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision', $payload['mode']);
        $this->assertSame('evidence-ledger-append-worker', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.durable_ledger_write_surface'));
        $this->assertSame('ledger-write-plan:fixture:001', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary.append_only_write_plan_ref'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision.value'));
        $this->assertSame('future_durable_ledger_write_receipt_ap_may_report_acceptance_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.accepts_without_ready_durable_preflight'));
    }

    public function test_durable_ledger_write_execution_decision_routes_change_request_with_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract::class)->decide(
            durablePreflight: $this->readyDurablePreflight(),
            decision: 'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_changes',
            reason: 'Replay plan needs a stronger operator confirmation reference.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_durable_ledger_write_execution_preflight_before_new_decision', $payload['next_action']);
    }

    public function test_durable_ledger_write_execution_decision_routes_rejection_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract::class)->decide(
            durablePreflight: $this->readyDurablePreflight(),
            decision: 'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable',
            reason: 'Human rejects this durable path until scope is reopened.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_rejected_by_human', $payload['status']);
        $this->assertSame('stop_durable_ledger_write_execution_path_until_scope_reopens', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
    }

    public function test_durable_ledger_write_execution_decision_blocks_invalid_decision_or_missing_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract::class)->decide(
            durablePreflight: $this->readyDurablePreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable',
            reason: '',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision', $payload['status']);
        $this->assertSame('fix_durable_ledger_write_execution_decision_value_or_reason', $payload['next_action']);

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract::class)->decide(
            durablePreflight: $this->readyDurablePreflight(),
            decision: 'perform_durable_ledger_write_now',
            reason: 'Invalid value must block.',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision', $payload['status']);
    }

    public function test_durable_ledger_write_execution_decision_blocks_when_preflight_is_not_ready(): void
    {
        $preflight = $this->readyDurablePreflight();
        $preflight['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract::class)->decide(
            durablePreflight: $preflight,
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable',
            reason: 'Human cannot accept incomplete durable ledger write execution preflight.',
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight', $payload['status']);
        $this->assertSame('repair_durable_ledger_write_execution_preflight_before_decision', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyDurablePreflight(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight.v1',
            'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_review',
            'work_title' => 'Decide durable ledger write execution',
            'resolved_target_ap' => 'AP-280',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_summary' => [
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'durable_ledger_write_execution_target' => [
                'durable_ledger_write_surface' => 'evidence-ledger-append-worker',
                'append_only_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_or_replay_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_receipt_ref' => 'result-persistence-execution-ledger-write-execution-receipt:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
            ],
        ];
    }
}
