<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContractTest extends TestCase
{
    public function test_real_durable_execution_decision_accepts_ready_preflight_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract::class)->decide(
            durableExecutionPreflight: $this->readyPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution',
            reason: 'Human accepts real durable execution preflight for future receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_accepted_by_human', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision', $payload['mode']);
        $this->assertSame('evidence-ledger-append-worker-confirmed-runtime', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_summary.durable_execution_surface'));
        $this->assertSame('runtime-budget-guard:fixture:001', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_summary.rate_limit_or_budget_guard_ref'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution', data_get($payload, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision.value'));
        $this->assertSame('future_real_durable_ledger_write_execution_receipt_ap_may_report_acceptance_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.performs_ledger_write'));
        $this->assertFalse(data_get($payload, 'guardrails.accepts_without_ready_durable_execution_preflight'));
    }

    public function test_real_durable_execution_decision_routes_change_request_with_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract::class)->decide(
            durableExecutionPreflight: $this->readyPreflight(),
            decision: 'request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_changes',
            reason: 'Runtime observability guard needs an explicit dashboard reference.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_real_durable_execution_preflight_before_new_decision', $payload['next_action']);
    }

    public function test_real_durable_execution_decision_routes_rejection_without_ledger_write(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract::class)->decide(
            durableExecutionPreflight: $this->readyPreflight(),
            decision: 'reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution',
            reason: 'Human rejects real durable execution until scope is reopened.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_rejected_by_human', $payload['status']);
        $this->assertSame('stop_real_durable_execution_path_until_scope_reopens', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
    }

    public function test_real_durable_execution_decision_blocks_invalid_decision_or_missing_reason(): void
    {
        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract::class)->decide(
            durableExecutionPreflight: $this->readyPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution',
            reason: '',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision', $payload['status']);
        $this->assertSame('fix_real_durable_execution_decision_value_or_reason', $payload['next_action']);

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract::class)->decide(
            durableExecutionPreflight: $this->readyPreflight(),
            decision: 'perform_real_durable_ledger_write_now',
            reason: 'Invalid value must block.',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision', $payload['status']);
    }

    public function test_real_durable_execution_decision_blocks_when_preflight_is_not_ready(): void
    {
        $preflight = $this->readyPreflight();
        $preflight['status'] = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_incomplete';

        $payload = app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionDecisionContract::class)->decide(
            durableExecutionPreflight: $preflight,
            decision: 'accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution',
            reason: 'Human cannot accept incomplete real durable execution preflight.',
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight', $payload['status']);
        $this->assertSame('repair_real_durable_execution_preflight_before_decision', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyPreflight(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight.v1',
            'status' => 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_review',
            'work_title' => 'Decide real durable ledger write execution',
            'resolved_target_ap' => 'AP-284',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_summary' => [
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
            'durable_execution_target' => [
                'durable_execution_surface' => 'evidence-ledger-append-worker-confirmed-runtime',
                'append_only_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_or_replay_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'rate_limit_or_budget_guard_ref' => 'runtime-budget-guard:fixture:001',
                'runtime_observability_guard_ref' => 'runtime-observability-guard:fixture:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'ledger_write_receipt_ref' => 'result-persistence-execution-ledger-write-execution-receipt:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
            ],
        ];
    }
}
