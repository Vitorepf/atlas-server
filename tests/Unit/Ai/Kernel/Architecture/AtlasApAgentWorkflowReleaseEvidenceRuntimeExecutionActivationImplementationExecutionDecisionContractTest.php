<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContractTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_execution_decision_accepts_ready_preflight_without_execution(): void
    {
        $payload = $this->decide(
            executionPreflight: $this->passingExecutionPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution',
            reason: 'Human accepted execution preflight for future receipt only.',
        );

        $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_contract.v1', $payload['schema_version']);
        $this->assertSame('runtime_execution_activation_implementation_execution_accepted_by_human', $payload['status']);
        $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_execution_decision', $payload['mode']);
        $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_decision_only_no_execution', $payload['authority']);
        $this->assertSame('ready_for_runtime_execution_activation_implementation_execution_review', data_get($payload, 'runtime_execution_activation_implementation_execution_preflight_summary.status'));
        $this->assertSame('accept_runtime_execution_activation_implementation_execution', data_get($payload, 'runtime_execution_activation_implementation_execution_decision.value'));
        $this->assertSame('valid', data_get($payload, 'runtime_execution_activation_implementation_execution_decision.status'));
        $this->assertSame('future_runtime_execution_activation_implementation_execution_receipt_ap_may_report_acceptance_without_execution', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
    }

    public function test_runtime_execution_activation_implementation_execution_decision_routes_change_request_with_reason(): void
    {
        $payload = $this->decide(
            executionPreflight: $this->passingExecutionPreflight(),
            decision: 'request_runtime_execution_activation_implementation_execution_changes',
            reason: 'Add final operator confirmation screen evidence.',
        );

        $this->assertSame('runtime_execution_activation_implementation_execution_changes_requested_by_human', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_preflight_before_new_decision', $payload['next_action']);
    }

    public function test_runtime_execution_activation_implementation_execution_decision_requires_valid_value_and_reason(): void
    {
        $payload = $this->decide(
            executionPreflight: $this->passingExecutionPreflight(),
            decision: 'accept_runtime_execution_activation_implementation_execution',
            reason: '',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_decision', $payload['status']);
        $this->assertSame('invalid', data_get($payload, 'runtime_execution_activation_implementation_execution_decision.status'));
        $this->assertSame('fix_runtime_execution_activation_implementation_execution_decision_value_or_reason', $payload['next_action']);

        $unknown = $this->decide(
            executionPreflight: $this->passingExecutionPreflight(),
            decision: 'execute_now',
            reason: 'Bad shortcut.',
        );

        $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_execution_decision', $unknown['status']);
    }

    public function test_runtime_execution_activation_implementation_execution_decision_blocks_when_preflight_is_not_ready(): void
    {
        $preflight = $this->passingExecutionPreflight();
        $preflight['status'] = 'runtime_execution_activation_implementation_execution_preflight_incomplete';

        $payload = $this->decide(
            executionPreflight: $preflight,
            decision: 'accept_runtime_execution_activation_implementation_execution',
            reason: 'Human accepted execution preflight for future receipt only.',
        );

        $this->assertSame('blocked_by_runtime_execution_activation_implementation_execution_preflight', $payload['status']);
        $this->assertSame('repair_runtime_execution_activation_implementation_execution_preflight_before_decision', $payload['next_action']);
    }

    /**
     * @param  array<string,mixed>  $executionPreflight
     * @return array<string,mixed>
     */
    private function decide(array $executionPreflight, string $decision, ?string $reason): array
    {
        return app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionDecisionContract::class)->decide(
            executionPreflight: $executionPreflight,
            decision: $decision,
            reason: $reason,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function passingExecutionPreflight(): array
    {
        return [
            'schema_version' => 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_preflight.v1',
            'status' => 'ready_for_runtime_execution_activation_implementation_execution_review',
            'work_title' => 'Preflight runtime execution activation implementation execution',
            'resolved_target_ap' => ['status' => 'existing_ap'],
            'runtime_execution_activation_implementation_execution_target' => [
                'execution_scope' => 'future runtime execution activation implementation execution review only',
                'payload_hash' => 'sha256:runtime-payload-fixture',
                'idempotency_key' => 'runtime-activation-implementation:fixture:001',
                'operator_confirmation_surface' => 'human-review-required-before-any-runtime-execution',
                'owner' => 'future-runtime-execution-activation-implementation',
                'policy_receipt_source' => 'policy-receipt:fixture:001',
                'rollback_plan_ref' => 'rollback-plan:fixture:001',
            ],
        ];
    }
}
