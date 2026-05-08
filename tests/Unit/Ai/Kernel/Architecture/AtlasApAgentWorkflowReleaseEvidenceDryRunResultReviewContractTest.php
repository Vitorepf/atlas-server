<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContractTest extends TestCase
{
    public function test_dry_run_result_review_accepts_ready_envelope_without_execution(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-review-ready', 940);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract::class)->review(
                workTitle: 'Review release evidence dry run result',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract.php'],
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
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_dry_run_result_review_contract.v1', $payload['schema_version']);
            $this->assertSame('dry_run_result_accepted_by_human', $payload['status']);
            $this->assertSame('read_only_release_evidence_dry_run_result_review', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_dry_run_result_review_only_no_execution', $payload['authority']);
            $this->assertSame('dry_run_result_envelope_ready_for_human_review', data_get($payload, 'dry_run_result_envelope_summary.status'));
            $this->assertSame('accept_dry_run_result', data_get($payload, 'dry_run_result_review_decision.value'));
            $this->assertSame('future_release_or_ledger_ap_may_consume_accepted_dry_run_result', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
            $this->assertFalse(data_get($payload, 'guardrails.runs_dry_run'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_dry_run_result_review_routes_change_request_with_reason(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-review-change-request', 941);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract::class)->review(
                workTitle: 'Request dry run result changes',
                intendedPaths: ['app/New/DryRunResultReviewChangeRequest.php'],
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
                resultReviewDecision: 'request_dry_run_result_changes',
                resultReviewReason: 'Outcome summary must include replay checksum.',
                docsApPath: $dir,
                requestedApNumber: 942,
            );

            $this->assertSame('dry_run_result_changes_requested_by_human', $payload['status']);
            $this->assertSame('valid', data_get($payload, 'dry_run_result_review_decision.status'));
            $this->assertSame('repair_dry_run_result_envelope_before_new_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_dry_run_result_review_blocks_invalid_decision_or_missing_reason(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-review-invalid', 943);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract::class)->review(
                workTitle: 'Invalid dry run result review',
                intendedPaths: ['app/New/InvalidDryRunResultReview.php'],
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
                resultReviewDecision: 'reject_dry_run_result',
                docsApPath: $dir,
                requestedApNumber: 944,
            );

            $this->assertSame('blocked_invalid_dry_run_result_review_decision', $payload['status']);
            $this->assertSame('invalid', data_get($payload, 'dry_run_result_review_decision.status'));
            $this->assertSame('dry_run_result_review_reason_required', data_get($payload, 'dry_run_result_review_decision.errors.0.id'));
            $this->assertSame('fix_dry_run_result_review_decision_value_or_reason', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_dry_run_result_review_blocks_when_envelope_is_not_ready(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-review-envelope-blocked', 945);
        $resultEvidence = $this->passingResultEvidence();
        $resultEvidence['confirmed_no_publish'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract::class)->review(
                workTitle: 'Blocked dry run result review',
                intendedPaths: ['app/New/BlockedDryRunResultReview.php'],
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
                resultEvidence: $resultEvidence,
                resultReviewDecision: 'accept_dry_run_result',
                docsApPath: $dir,
                requestedApNumber: 946,
            );

            $this->assertSame('blocked_by_dry_run_result_envelope_contract', $payload['status']);
            $this->assertSame('dry_run_result_evidence_incomplete', data_get($payload, 'dry_run_result_envelope_summary.status'));
            $this->assertSame('repair_dry_run_result_envelope_before_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
    private function passingReadinessEvidence(): array
    {
        return [
            'reviewed_candidate_decision_receipt' => true,
            'confirmed_execution_ap_owner' => true,
            'confirmed_payload_schema_final' => true,
            'confirmed_replay_or_rollback_plan' => true,
            'confirmed_privacy_and_policy_review' => true,
            'confirmed_dry_run_required' => true,
            'execution_ap' => 'AP-227',
            'owner' => 'future-release-or-evidence-runtime',
        ];
    }

    /**
     * @return array<string,mixed>
     */
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

    /**
     * @return array<string,mixed>
     */
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
