<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowAp360DecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowAp360DecisionContractTest extends TestCase
{
    private const PREFIX = 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution';

    public function test_acceptance_decision_is_normalized(): void
    {
        $payload = app(AtlasApAgentWorkflowAp360DecisionContract::class)->decide(
            preflight: $this->p(),
            decision: 'accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight',
            reason: 'Human accepts AP-359 preflight for future receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract.v1', $payload['schema_version']);
        $this->assertSame(self::PREFIX.'_accepted_by_human', $payload['status']);
        $this->assertSame('AP-future-real-durable-ledger-write-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-handoff', data_get($payload, self::PREFIX.'_preflight_summary.future_ap'));
        $this->assertSame('future-policy-receipt:fixture:001', data_get($payload, self::PREFIX.'_preflight_summary.policy_receipt_source'));
        $this->assertSame('evidence-ledger-append-worker-confirmed-runtime', data_get($payload, self::PREFIX.'_preflight_summary.real_execution_surface'));
        $this->assertSame('future_real_durable_ledger_write_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_ap_may_report_acceptance_without_ledger_write', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_change_request_decision_returns_to_preflight_repair(): void
    {
        $payload = app(AtlasApAgentWorkflowAp360DecisionContract::class)->decide(
            $this->p(),
            'request_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_changes',
            'Operator confirmation surface needs a more explicit human checkpoint.',
        );

        $this->assertSame(self::PREFIX.'_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_before_new_decision', $payload['next_action']);
    }

    public function test_rejection_decision_stops_future_path(): void
    {
        $payload = app(AtlasApAgentWorkflowAp360DecisionContract::class)->decide(
            $this->p(),
            'reject_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight',
            'Human rejects the path until execution scope is reopened.',
        );

        $this->assertSame(self::PREFIX.'_rejected_by_human', $payload['status']);
        $this->assertSame('stop_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_path_until_scope_reopens', $payload['next_action']);
    }

    public function test_invalid_decision_or_empty_reason_blocks(): void
    {
        $payload = app(AtlasApAgentWorkflowAp360DecisionContract::class)->decide(
            $this->p(),
            'execute_real_durable_ledger_write_payload_now',
            'Invalid operational value must block.',
        );

        $this->assertSame('blocked_invalid_'.self::PREFIX.'_decision', $payload['status']);

        $payload = app(AtlasApAgentWorkflowAp360DecisionContract::class)->decide(
            $this->p(),
            'accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight',
            '',
        );

        $this->assertSame('fix_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_value_or_reason', $payload['next_action']);
    }

    public function test_unready_preflight_blocks_human_acceptance(): void
    {
        $preflight = $this->p();
        $preflight['status'] = self::PREFIX.'_preflight_incomplete';

        $payload = app(AtlasApAgentWorkflowAp360DecisionContract::class)->decide(
            $preflight,
            'accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight',
            'Human cannot accept incomplete AP-359 preflight.',
        );

        $this->assertSame('blocked_by_'.self::PREFIX.'_preflight', $payload['status']);
        $this->assertSame('repair_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_before_decision', $payload['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function p(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight.v1',
            'status' => 'ready_for_'.self::PREFIX.'_review',
            'work_title' => 'Decide AP-359 preflight for future real durable ledger write execution',
            'resolved_target_ap' => 'AP-360',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_summary' => [
                'future_ap' => 'AP-future-real-durable-ledger-write-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-handoff',
                'receipt_ref' => 'real-durable-execution-executor-payload-execution-execution-execution-execution-execution-execution-execution-execution-decision-receipt:fixture:001',
                'package' => 'accepted executor payload execution execution execution execution execution execution execution execution receipt plus runtime guard references',
            ],
            'real_durable_execution_executor_payload_execution_target' => [
                'real_execution_surface' => 'evidence-ledger-append-worker-confirmed-runtime',
                'append_only_write_plan_ref' => 'ledger-write-plan:fixture:001',
                'idempotency_key_strategy' => 'receipt_schema_and_payload_hash',
                'operator_confirmation_surface' => 'future-human-reviewed-inbox-action',
                'rollback_or_replay_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
                'rate_limit_or_budget_guard_ref' => 'runtime-budget-guard:fixture:001',
                'runtime_observability_guard_ref' => 'runtime-observability-guard:fixture:001',
                'payload_hash' => 'fixture-result-payload-hash',
                'receipt_destination_ref' => 'future-receipt-destination:fixture:001',
                'owner' => 'future-release-or-evidence-runtime',
                'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            ],
        ];
    }
}
