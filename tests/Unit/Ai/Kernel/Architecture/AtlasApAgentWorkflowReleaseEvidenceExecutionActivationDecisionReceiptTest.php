<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceiptTest extends TestCase
{
    public function test_execution_activation_receipt_reports_acceptance_without_activation(): void
    {
        $dir = $this->makeApDir('execution-activation-receipt-accepted', 961);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                activationDecision: 'accept_execution_activation',
                activationDecisionReason: 'Human accepted activation review for future runtime AP only.',
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_execution_activation_decision_receipt.v1', $payload['schema_version']);
            $this->assertSame('execution_activation_acceptance_reported', $payload['status']);
            $this->assertSame('read_only_release_evidence_execution_activation_decision_receipt', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_execution_activation_receipt_only_no_execution', $payload['authority']);
            $this->assertSame('execution_activation_accepted_by_human', data_get($payload, 'execution_activation_decision_summary.status'));
            $this->assertSame('accept_execution_activation', data_get($payload, 'execution_activation_decision_summary.decision'));
            $this->assertSame('evidence_ledger_execution_activation', data_get($payload, 'execution_activation_decision_summary.activation_surface'));
            $this->assertSame('future_runtime_execution_ap_may_consume_activation_receipt_without_auto_execution', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
            $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
            $this->assertFalse(data_get($payload, 'guardrails.performs_activation'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_activation_receipt_routes_change_request_to_repair(): void
    {
        $dir = $this->makeApDir('execution-activation-receipt-repair', 962);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                activationDecision: 'request_execution_activation_changes',
                activationDecisionReason: 'Clarify final operator confirmation before activation receipt.',
            );

            $this->assertSame('execution_activation_returned_for_repair', $payload['status']);
            $this->assertSame('request_execution_activation_changes', data_get($payload, 'execution_activation_decision_summary.decision'));
            $this->assertSame('repair_execution_activation_preflight_then_request_new_human_decision', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_activation_receipt_stops_on_rejection(): void
    {
        $dir = $this->makeApDir('execution-activation-receipt-rejected', 963);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                activationDecision: 'reject_execution_activation',
                activationDecisionReason: 'Activation must wait for a new operational scope.',
            );

            $this->assertSame('execution_activation_stopped_by_rejection', $payload['status']);
            $this->assertSame('reject_execution_activation', data_get($payload, 'execution_activation_decision_summary.decision'));
            $this->assertSame('stop_execution_activation_flow_until_scope_reopens', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_activation_receipt_blocks_when_decision_contract_blocks(): void
    {
        $dir = $this->makeApDir('execution-activation-receipt-blocked', 964);

        try {
            $payload = $this->receipt(
                docsApPath: $dir,
                activationDecision: 'accept_execution_activation',
            );

            $this->assertSame('blocked_by_execution_activation_decision_contract', $payload['status']);
            $this->assertSame('blocked_invalid_execution_activation_decision', data_get($payload, 'execution_activation_decision_summary.status'));
            $this->assertSame('repair_execution_activation_decision_before_receipt', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function receipt(
        string $docsApPath,
        string $activationDecision,
        ?string $activationDecisionReason = null,
    ): array {
        return app(AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceipt::class)->receipt(
            workTitle: 'Receipt execution activation decision',
            intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceipt.php'],
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
            activationDecision: $activationDecision,
            activationDecisionReason: $activationDecisionReason,
            implementationDecisionReason: 'Human accepted implementation for future activation preflight only.',
            authorizationDecisionReason: 'Human approved future implementation review only.',
            docsApPath: $docsApPath,
        );
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
            'future_execution_ap' => 'AP-240',
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
            'execution_ap' => 'AP-240',
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
            'future_consumer_ap' => 'AP-240',
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
