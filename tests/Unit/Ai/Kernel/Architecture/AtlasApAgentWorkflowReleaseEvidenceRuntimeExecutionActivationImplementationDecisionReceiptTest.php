<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationDecisionReceiptTest extends TestCase
{
    public function test_runtime_execution_activation_implementation_receipt_reports_acceptance_without_activation(): void
    {
        $dir = $this->makeApDir('runtime-execution-activation-implementation-decision-accepted', 1013);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                runtimeExecutionImplementationPreflightEvidence: $this->passingRuntimeExecutionImplementationPreflightEvidence(),
                runtimeExecutionImplementationDecision: 'accept_runtime_execution_implementation',
                runtimeExecutionImplementationDecisionReason: 'Human accepted runtime execution implementation preflight for future receipt only.',
                runtimeExecutionActivationPreflightEvidence: $this->passingRuntimeExecutionActivationPreflightEvidence(),
                runtimeExecutionActivationDecision: 'accept_runtime_execution_activation',
                runtimeExecutionActivationDecisionReason: 'Human accepted runtime execution activation review for future receipt only.',
                runtimeExecutionActivationHandoffEvidence: $this->passingRuntimeExecutionActivationHandoffEvidence(),
                runtimeExecutionActivationImplementationPreflightEvidence: $this->passingRuntimeExecutionActivationImplementationPreflightEvidence(),
                runtimeExecutionActivationImplementationDecision: 'accept_runtime_execution_activation_implementation',
                runtimeExecutionActivationImplementationDecisionReason: 'Human accepted runtime execution activation implementation preflight for future receipt only.',
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_decision_receipt.v1', $payload['schema_version']);
            $this->assertSame('runtime_execution_activation_implementation_acceptance_reported', $payload['status']);
            $this->assertSame('read_only_release_evidence_runtime_execution_activation_implementation_decision_receipt', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_receipt_only_no_execution', $payload['authority']);
            $this->assertSame('runtime_execution_activation_implementation_accepted_by_human', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.status'));
            $this->assertSame('accept_runtime_execution_activation_implementation', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.decision'));
            $this->assertSame('evidence_ledger_runtime_activation_executor', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.activation_surface'));
            $this->assertSame('runtime activation handoff hash plus activation surface', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.idempotency_key_strategy'));
            $this->assertSame('future_runtime_execution_activation_implementation_ap_may_consume_decision_receipt_without_activation', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
            $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
            $this->assertFalse(data_get($payload, 'guardrails.executes_runtime_payload'));
            $this->assertFalse(data_get($payload, 'guardrails.performs_activation'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_runtime_execution_activation_implementation_receipt_routes_change_request_to_repair(): void
    {
        $dir = $this->makeApDir('runtime-execution-activation-implementation-decision-repair', 1014);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                runtimeExecutionImplementationPreflightEvidence: $this->passingRuntimeExecutionImplementationPreflightEvidence(),
                runtimeExecutionImplementationDecision: 'accept_runtime_execution_implementation',
                runtimeExecutionImplementationDecisionReason: 'Human accepted runtime execution implementation preflight for future receipt only.',
                runtimeExecutionActivationPreflightEvidence: $this->passingRuntimeExecutionActivationPreflightEvidence(),
                runtimeExecutionActivationDecision: 'accept_runtime_execution_activation',
                runtimeExecutionActivationDecisionReason: 'Human accepted runtime execution activation review for future receipt only.',
                runtimeExecutionActivationHandoffEvidence: $this->passingRuntimeExecutionActivationHandoffEvidence(),
                runtimeExecutionActivationImplementationPreflightEvidence: $this->passingRuntimeExecutionActivationImplementationPreflightEvidence(),
                runtimeExecutionActivationImplementationDecision: 'request_runtime_execution_activation_implementation_changes',
                runtimeExecutionActivationImplementationDecisionReason: 'Add final runtime activation implementation rollback proof.',
            );

            $this->assertSame('runtime_execution_activation_implementation_returned_for_repair', $payload['status']);
            $this->assertSame('request_runtime_execution_activation_implementation_changes', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.decision'));
            $this->assertSame('repair_runtime_execution_activation_implementation_preflight_then_request_new_human_decision', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_runtime_execution_activation_implementation_receipt_blocks_invalid_decision_contract(): void
    {
        $dir = $this->makeApDir('runtime-execution-activation-implementation-decision-invalid', 1015);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                runtimeExecutionImplementationPreflightEvidence: $this->passingRuntimeExecutionImplementationPreflightEvidence(),
                runtimeExecutionImplementationDecision: 'accept_runtime_execution_implementation',
                runtimeExecutionImplementationDecisionReason: 'Human accepted runtime execution implementation preflight for future receipt only.',
                runtimeExecutionActivationPreflightEvidence: $this->passingRuntimeExecutionActivationPreflightEvidence(),
                runtimeExecutionActivationDecision: 'accept_runtime_execution_activation',
                runtimeExecutionActivationDecisionReason: 'Human accepted runtime execution activation review for future receipt only.',
                runtimeExecutionActivationHandoffEvidence: $this->passingRuntimeExecutionActivationHandoffEvidence(),
                runtimeExecutionActivationImplementationPreflightEvidence: $this->passingRuntimeExecutionActivationImplementationPreflightEvidence(),
                runtimeExecutionActivationImplementationDecision: 'accept_runtime_execution_activation_implementation',
            );

            $this->assertSame('blocked_by_runtime_execution_activation_implementation_decision_contract', $payload['status']);
            $this->assertSame('blocked_invalid_runtime_execution_activation_implementation_decision', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.status'));
            $this->assertSame('repair_runtime_execution_activation_implementation_decision_before_receipt', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_runtime_execution_activation_implementation_receipt_blocks_when_decision_contract_blocks_on_preflight(): void
    {
        $dir = $this->makeApDir('runtime-execution-activation-implementation-decision-preflight-blocked', 1016);
        $implementationEvidence = $this->passingRuntimeExecutionActivationImplementationPreflightEvidence();
        $implementationEvidence['confirmed_no_runtime_job_created'] = false;

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                runtimeExecutionImplementationPreflightEvidence: $this->passingRuntimeExecutionImplementationPreflightEvidence(),
                runtimeExecutionImplementationDecision: 'accept_runtime_execution_implementation',
                runtimeExecutionImplementationDecisionReason: 'Human accepted runtime execution implementation preflight for future receipt only.',
                runtimeExecutionActivationPreflightEvidence: $this->passingRuntimeExecutionActivationPreflightEvidence(),
                runtimeExecutionActivationDecision: 'accept_runtime_execution_activation',
                runtimeExecutionActivationDecisionReason: 'Human accepted runtime execution activation review for future receipt only.',
                runtimeExecutionActivationHandoffEvidence: $this->passingRuntimeExecutionActivationHandoffEvidence(),
                runtimeExecutionActivationImplementationPreflightEvidence: $implementationEvidence,
                runtimeExecutionActivationImplementationDecision: 'accept_runtime_execution_activation_implementation',
                runtimeExecutionActivationImplementationDecisionReason: 'Human accepted runtime execution activation implementation preflight for future receipt only.',
            );

            $this->assertSame('blocked_by_runtime_execution_activation_implementation_decision_contract', $payload['status']);
            $this->assertSame('blocked_by_runtime_execution_activation_implementation_preflight', data_get($payload, 'runtime_execution_activation_implementation_decision_summary.status'));
            $this->assertSame('repair_runtime_execution_activation_implementation_decision_before_receipt', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param  array<string,mixed>  $runtimePreflightEvidence
     * @return array<string,mixed>
     */
    private function receipt(
        string $docsApPath,
        array $runtimeExecutionImplementationPreflightEvidence,
        string $runtimeExecutionImplementationDecision,
        array $runtimeExecutionActivationPreflightEvidence,
        string $runtimeExecutionActivationDecision,
        array $runtimeExecutionActivationHandoffEvidence,
        array $runtimeExecutionActivationImplementationPreflightEvidence,
        string $runtimeExecutionActivationImplementationDecision,
        ?string $runtimeExecutionActivationDecisionReason = null,
        ?string $runtimeExecutionImplementationDecisionReason = null,
        ?string $runtimeExecutionActivationImplementationDecisionReason = null,
    ): array {
        return app(AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationDecisionReceipt::class)->receipt(
            workTitle: 'Receipt runtime execution activation implementation decision',
            intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationDecisionReceipt.php'],
            validationEvidence: $this->passingValidationEvidence(),
            traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
            decision: 'accept',
            integratorEvidence: $this->passingIntegratorEvidence(),
            integrationEvidence: $this->passingIntegrationEvidence(),
            finalAuditEvidence: $this->passingFinalAuditEvidence(),
            closeoutDecision: 'accept_closeout',
            preflightEvidence: $this->passingPreflightEvidence(),
            handoffEvidence: $this->passingHandoffEvidence(),
            candidateEvidence: $this->passingCandidateEvidence(),
            candidateDecision: 'accept_candidate',
            readinessEvidence: $this->passingReadinessEvidence(),
            dryRunEvidence: $this->passingDryRunEvidence(),
            dryRunReviewDecision: 'accept_dry_run_plan',
            resultEvidence: $this->passingResultEvidence(),
            resultReviewDecision: 'accept_dry_run_result',
            postDryRunHandoffEvidence: $this->passingPostDryRunHandoffEvidence(),
            consumerReadinessEvidence: $this->passingConsumerReadinessEvidence(),
            consumerReadinessDecision: 'accept_consumer_readiness',
            authorizationEvidence: $this->passingAuthorizationEvidence(),
            authorizationDecision: 'authorize_execution',
            executionAuthorizationHandoffEvidence: $this->passingExecutionAuthorizationHandoffEvidence(),
            implementationPreflightEvidence: $this->passingImplementationPreflightEvidence(),
            implementationDecision: 'accept_execution_implementation',
            activationPreflightEvidence: $this->passingActivationPreflightEvidence(),
            activationDecision: 'accept_execution_activation',
            runtimePreflightEvidence: $this->passingRuntimePreflightEvidence(),
            runtimeExecutionDecision: 'accept_runtime_execution',
            runtimeExecutionHandoffEvidence: $this->passingRuntimeExecutionHandoffEvidence(),
            runtimeExecutionImplementationPreflightEvidence: $runtimeExecutionImplementationPreflightEvidence,
            runtimeExecutionImplementationDecision: $runtimeExecutionImplementationDecision,
            runtimeExecutionActivationPreflightEvidence: $runtimeExecutionActivationPreflightEvidence,
            runtimeExecutionActivationDecision: $runtimeExecutionActivationDecision,
            runtimeExecutionActivationHandoffEvidence: $runtimeExecutionActivationHandoffEvidence,
            runtimeExecutionActivationImplementationPreflightEvidence: $runtimeExecutionActivationImplementationPreflightEvidence,
            runtimeExecutionActivationImplementationDecision: $runtimeExecutionActivationImplementationDecision,
            runtimeExecutionActivationImplementationDecisionReason: $runtimeExecutionActivationImplementationDecisionReason,
            runtimeExecutionActivationDecisionReason: $runtimeExecutionActivationDecisionReason,
            runtimeExecutionImplementationDecisionReason: $runtimeExecutionImplementationDecisionReason,
            runtimeExecutionDecisionReason: 'Human accepted runtime execution review for future receipt only.',
            activationDecisionReason: 'Human accepted activation review for future runtime preflight only.',
            implementationDecisionReason: 'Human accepted implementation for future activation preflight only.',
            authorizationDecisionReason: 'Human approved future implementation review only.',
            docsApPath: $docsApPath,
        );
    }

    private function passingRuntimeExecutionHandoffEvidence(): array
    {
        return [
            'reviewed_runtime_execution_receipt' => true,
            'declared_future_runtime_execution_ap' => true,
            'declared_runtime_execution_package' => true,
            'confirmed_acceptance_receipt_only' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'future_runtime_execution_ap' => 'AP-future-runtime-execution',
            'runtime_execution_package' => 'accepted runtime execution receipt plus payload schema and rollback references',
            'owner' => 'future-release-or-evidence-runtime',
            'runtime_execution_receipt_ref' => 'runtime-execution-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'runtime receipt hash plus payload hash',
        ];
    }

    private function passingRuntimeExecutionActivationHandoffEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_receipt' => true,
            'declared_future_runtime_execution_activation_ap' => true,
            'declared_runtime_execution_activation_package' => true,
            'confirmed_acceptance_receipt_only' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_no_auto_execution' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'future_runtime_execution_activation_ap' => 'AP-future-runtime-execution-activation',
            'runtime_execution_activation_package' => 'accepted runtime execution activation receipt plus activation references',
            'owner' => 'future-release-or-evidence-runtime',
            'runtime_execution_activation_receipt_ref' => 'runtime-execution-activation-receipt:fixture:001',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'runtime activation receipt hash plus activation surface',
        ];
    }

    private function passingRuntimeExecutionImplementationPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_handoff' => true,
            'declared_runtime_execution_surface' => true,
            'declared_runtime_execution_entrypoint' => true,
            'declared_payload_boundary' => true,
            'declared_evidence_event_schema' => true,
            'confirmed_operator_owner' => true,
            'confirmed_policy_receipt_required' => true,
            'confirmed_replay_window_defined' => true,
            'confirmed_rollback_plan_available' => true,
            'confirmed_idempotency_key_strategy' => true,
            'confirmed_no_immediate_execution' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'runtime_execution_surface' => 'evidence_ledger_runtime_executor',
            'runtime_execution_entrypoint' => 'future AP controlled runtime execution command',
            'operator_owner' => 'future-release-or-evidence-runtime',
            'payload_boundary' => 'single accepted runtime execution package after policy receipt',
            'evidence_event_schema' => 'atlas.runtime_execution.evidence.v1',
            'replay_window' => 'fixture ledger replay before and after future runtime execution',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'runtime execution receipt hash plus payload hash',
        ];
    }

    private function passingRuntimeExecutionActivationImplementationPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_activation_handoff' => true,
            'declared_activation_surface' => true,
            'declared_activation_entrypoint' => true,
            'declared_activation_boundary' => true,
            'declared_evidence_event_schema' => true,
            'confirmed_operator_owner' => true,
            'confirmed_policy_receipt_required' => true,
            'confirmed_replay_window_defined' => true,
            'confirmed_rollback_plan_available' => true,
            'confirmed_idempotency_key_strategy' => true,
            'confirmed_no_immediate_activation' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_payload_executed' => true,
            'activation_surface' => 'evidence_ledger_runtime_activation_executor',
            'activation_entrypoint' => 'future AP controlled runtime activation command',
            'operator_owner' => 'future-release-or-evidence-runtime',
            'activation_boundary' => 'single accepted runtime activation package after policy receipt',
            'evidence_event_schema' => 'atlas.runtime_execution.activation.evidence.v1',
            'replay_window' => 'fixture ledger replay before and after future runtime activation',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'idempotency_key_strategy' => 'runtime activation handoff hash plus activation surface',
        ];
    }

    private function passingRuntimePreflightEvidence(): array
    {
        return [
            'reviewed_execution_activation_receipt' => true,
            'declared_runtime_surface' => true,
            'declared_runtime_entrypoint' => true,
            'declared_runtime_owner' => true,
            'declared_execution_payload_schema' => true,
            'confirmed_activation_receipt_accepted' => true,
            'confirmed_policy_receipt_attached' => true,
            'confirmed_operator_confirmation_required' => true,
            'confirmed_idempotency_key_ready' => true,
            'confirmed_observability_ready' => true,
            'confirmed_replay_window_ready' => true,
            'confirmed_rollback_plan_ready' => true,
            'confirmed_no_runtime_execution_performed' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'runtime_surface' => 'evidence_ledger_runtime_executor',
            'runtime_entrypoint' => 'future AP controlled runtime execution command',
            'runtime_owner' => 'future-release-or-evidence-runtime',
            'execution_payload_schema' => 'atlas.runtime.execution_payload.v1',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'operator_confirmation_surface' => 'human reviewed runtime execution console',
            'replay_window' => 'fixture ledger replay immediately before future runtime execution',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'observability_hooks' => 'runtime audit logs plus provider performance counters',
            'idempotency_key_strategy' => 'activation receipt hash plus runtime surface plus payload hash',
        ];
    }

    private function passingActivationPreflightEvidence(): array
    {
        return [
            'reviewed_execution_implementation_receipt' => true,
            'declared_activation_surface' => true,
            'declared_activation_entrypoint' => true,
            'declared_policy_receipt_source' => true,
            'declared_operator_confirmation_surface' => true,
            'confirmed_execution_receipt_accepted' => true,
            'confirmed_final_replay_window' => true,
            'confirmed_final_rollback_plan' => true,
            'confirmed_observability_hooks' => true,
            'confirmed_idempotency_key_strategy' => true,
            'confirmed_no_activation_performed' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'activation_surface' => 'evidence_ledger_execution_activation',
            'activation_entrypoint' => 'future AP controlled activation command',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'operator_confirmation_surface' => 'human reviewed activation console',
            'owner' => 'future-release-or-evidence-runtime',
            'replay_window' => 'fixture ledger replay immediately before future activation',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'observability_hooks' => 'activation audit logs plus provider performance counters',
            'idempotency_key_strategy' => 'target-ap plus receipt hash plus activation surface',
        ];
    }

    private function passingRuntimeExecutionActivationPreflightEvidence(): array
    {
        return [
            'reviewed_runtime_execution_implementation_receipt' => true,
            'declared_activation_surface' => true,
            'declared_activation_entrypoint' => true,
            'declared_policy_receipt_source' => true,
            'declared_operator_confirmation_surface' => true,
            'confirmed_runtime_execution_receipt_accepted' => true,
            'confirmed_final_replay_window' => true,
            'confirmed_final_rollback_plan' => true,
            'confirmed_observability_hooks' => true,
            'confirmed_idempotency_key_strategy' => true,
            'confirmed_no_activation_performed' => true,
            'confirmed_no_runtime_job_created' => true,
            'confirmed_no_ledger_write' => true,
            'activation_surface' => 'evidence_ledger_runtime_execution_activation',
            'activation_entrypoint' => 'future AP controlled runtime activation command',
            'policy_receipt_source' => 'future-policy-receipt:fixture:001',
            'operator_confirmation_surface' => 'human reviewed runtime activation console',
            'owner' => 'future-release-or-evidence-runtime',
            'replay_window' => 'fixture ledger replay immediately before future runtime activation',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
            'observability_hooks' => 'runtime activation audit logs plus provider performance counters',
            'idempotency_key_strategy' => 'runtime implementation receipt hash plus activation surface',
        ];
    }

    private function passingImplementationPreflightEvidence(): array
    {
        return [
            'reviewed_execution_authorization_handoff' => true,
            'declared_execution_surface' => true,
            'declared_execution_entrypoint' => true,
            'declared_mutation_boundary' => true,
            'confirmed_operator_owner' => true,
            'confirmed_policy_receipt_required' => true,
            'confirmed_replay_window_defined' => true,
            'confirmed_rollback_plan_available' => true,
            'confirmed_evidence_write_schema_locked' => true,
            'confirmed_no_immediate_execution' => true,
            'confirmed_no_background_job_created' => true,
            'execution_surface' => 'evidence_ledger_append_worker',
            'execution_entrypoint' => 'future AP controlled command handler',
            'operator_owner' => 'future-release-or-evidence-runtime',
            'mutation_boundary' => 'append-only evidence event after future policy receipt',
            'evidence_event_schema' => 'atlas.release_evidence.execution.v1',
            'replay_window' => 'fixture ledger replay before and after future write',
            'rollback_plan_ref' => 'fixture-ledger-replay:rollback-plan:001',
        ];
    }

    private function passingExecutionAuthorizationHandoffEvidence(): array
    {
        return [
            'reviewed_execution_authorization_receipt' => true,
            'declared_future_execution_ap' => true,
            'declared_execution_package' => true,
            'confirmed_approval_receipt_only' => true,
            'confirmed_no_auto_release' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_runtime_job' => true,
            'confirmed_no_command_execution' => true,
            'confirmed_no_authorized_work_execution' => true,
            'future_execution_ap' => 'AP-241',
            'execution_package' => 'approved authorization receipt plus schema and rollback references',
            'owner' => 'future-release-or-evidence-runtime',
            'authorization_receipt_ref' => 'execution-authorization-receipt:fixture:001',
        ];
    }

    private function passingAuthorizationEvidence(): array
    {
        return [
            'reviewed_consumer_readiness_receipt' => true,
            'confirmed_execution_owner' => true,
            'confirmed_payload_schema_locked' => true,
            'confirmed_replay_or_rollback_ready' => true,
            'confirmed_policy_and_privacy_clearance' => true,
            'confirmed_human_authorization_required' => true,
            'confirmed_no_auto_release' => true,
            'confirmed_no_auto_ledger_write' => true,
            'confirmed_no_runtime_job' => true,
            'authorization_surface' => 'evidence_ledger_execution_authorization',
            'authorization_scope' => 'authorize append-only candidate execution in a future AP only',
            'execution_owner' => 'future-release-or-evidence-runtime',
            'payload_schema' => 'atlas.evidence_ledger.candidate.v1',
            'rollback_reference' => 'fixture-ledger-replay:rollback-plan:001',
        ];
    }

    private function passingValidationEvidence(): array
    {
        return [
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => true,
            'docs_health_ok' => true,
            'architecture_validate_ok' => true,
            'git_diff_check_passed' => true,
            'ap_doc_updated' => true,
            'uncovered_changed_paths' => [],
        ];
    }

    private function passingIntegratorEvidence(): array
    {
        return [
            'reviewed_handoff_packet' => true,
            'reviewed_diff_scope' => true,
            'reviewed_validation_output' => true,
            'confirmed_no_hot_file_conflict' => true,
            'confirmed_no_unrelated_reverts' => true,
            'confirmed_manual_integration_owner' => true,
        ];
    }

    private function passingIntegrationEvidence(): array
    {
        return [
            'manually_applied_by_integrator' => true,
            'applied_paths_match_handoff_scope' => true,
            'final_diff_reviewed' => true,
            'final_validation_reran' => true,
            'final_docs_health_checked' => true,
            'no_unrelated_work_included' => true,
        ];
    }

    private function passingFinalAuditEvidence(): array
    {
        return [
            'reviewed_manual_integration_receipt' => true,
            'reviewed_final_validation_commands' => true,
            'reviewed_documentation_status' => true,
            'reviewed_no_untracked_surprise' => true,
            'reviewed_no_parallel_flow_created' => true,
            'reviewed_remaining_risks' => true,
        ];
    }

    private function passingPreflightEvidence(): array
    {
        return [
            'reviewed_closeout_acceptance_receipt' => true,
            'selected_future_surface' => true,
            'confirmed_release_or_ledger_owner' => true,
            'confirmed_no_auto_publish' => true,
            'confirmed_no_auto_evidence_emit' => true,
            'confirmed_post_closeout_risks_recorded' => true,
        ];
    }

    private function passingHandoffEvidence(): array
    {
        return [
            'reviewed_release_evidence_preflight' => true,
            'confirmed_future_ap_owner' => true,
            'confirmed_no_runtime_side_effect' => true,
            'confirmed_no_direct_release_execution' => true,
            'confirmed_no_direct_ledger_write' => true,
            'confirmed_followup_ap_required' => true,
        ];
    }

    private function passingCandidateEvidence(): array
    {
        return [
            'reviewed_release_evidence_handoff_packet' => true,
            'selected_candidate_kind' => true,
            'described_payload_schema' => true,
            'confirmed_append_only_or_release_review' => true,
            'confirmed_human_review_before_execution' => true,
            'confirmed_no_runtime_mutation' => true,
            'candidate_kind' => 'evidence_ledger_candidate',
            'payload_schema' => 'atlas.evidence_ledger.candidate.v1',
        ];
    }

    private function passingReadinessEvidence(): array
    {
        return [
            'reviewed_candidate_decision_receipt' => true,
            'confirmed_execution_ap_owner' => true,
            'confirmed_payload_schema_final' => true,
            'confirmed_replay_or_rollback_plan' => true,
            'confirmed_privacy_and_policy_review' => true,
            'confirmed_dry_run_required' => true,
            'execution_ap' => 'AP-241',
            'owner' => 'future-release-or-evidence-runtime',
        ];
    }

    private function passingDryRunEvidence(): array
    {
        return [
            'reviewed_execution_readiness' => true,
            'declared_simulation_scope' => true,
            'declared_fixture_or_corpus' => true,
            'declared_success_criteria' => true,
            'declared_failure_criteria' => true,
            'confirmed_no_real_mutation' => true,
            'simulation_scope' => 'simulate append-only candidate against fixture ledger',
            'fixture_or_corpus' => 'offline fixture ledger replay corpus',
            'success_criteria' => 'candidate validates and produces no mutation',
            'failure_criteria' => 'any publish, ledger write, runtime job, or schema drift',
        ];
    }

    private function passingResultEvidence(): array
    {
        return [
            'reviewed_dry_run_review_decision' => true,
            'declared_result_source' => true,
            'declared_fixture_or_corpus_used' => true,
            'declared_outcome_summary' => true,
            'declared_failure_observations' => true,
            'confirmed_no_real_mutation' => true,
            'confirmed_no_publish' => true,
            'confirmed_no_ledger_write' => true,
            'result_source' => 'offline dry-run fixture runner',
            'fixture_or_corpus_used' => 'offline fixture ledger replay corpus',
            'outcome_summary' => 'candidate validates with no mutation attempts',
            'failure_observations' => 'none observed',
            'runtime_trace_ref' => 'dry-run-trace:fixture:001',
        ];
    }

    private function passingPostDryRunHandoffEvidence(): array
    {
        return [
            'reviewed_dry_run_result_review' => true,
            'declared_future_consumer_ap' => true,
            'declared_handoff_package' => true,
            'confirmed_accepted_result_only' => true,
            'confirmed_no_auto_release' => true,
            'confirmed_no_ledger_write' => true,
            'confirmed_no_runtime_job' => true,
            'future_consumer_ap' => 'AP-241',
            'handoff_package' => 'accepted dry-run result plus review summary',
            'owner' => 'future-release-or-evidence-runtime',
        ];
    }

    private function passingConsumerReadinessEvidence(): array
    {
        return [
            'reviewed_post_dry_run_handoff' => true,
            'confirmed_future_consumer_owner' => true,
            'confirmed_payload_schema_final' => true,
            'confirmed_policy_and_privacy_review' => true,
            'confirmed_replay_or_rollback_plan' => true,
            'confirmed_no_auto_release' => true,
            'confirmed_no_auto_ledger_write' => true,
            'confirmed_no_runtime_job' => true,
            'target_surface' => 'evidence_ledger_execution_decision',
            'future_consumer_owner' => 'future-release-or-evidence-runtime',
            'payload_schema' => 'atlas.evidence_ledger.candidate.v1',
            'replay_or_rollback_plan' => 'replay fixture before any future write',
        ];
    }

    private function makeApDir(string $slug, int $apNumber): string
    {
        $dir = sys_get_temp_dir().'/atlas-ap-'.$slug.'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/AP-'.$apNumber.'-'.$slug.'.md', "# AP-$apNumber $slug\n");

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($dir);
    }
}
