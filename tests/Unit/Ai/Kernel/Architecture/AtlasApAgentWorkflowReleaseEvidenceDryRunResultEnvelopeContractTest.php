<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContractTest extends TestCase
{
    public function test_dry_run_result_envelope_is_ready_after_accepted_review_and_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-ready', 950);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract::class)->envelope(
                workTitle: 'Envelope release evidence dry run result',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract.php'],
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
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_dry_run_result_envelope_contract.v1', $payload['schema_version']);
            $this->assertSame('dry_run_result_envelope_ready_for_human_review', $payload['status']);
            $this->assertSame('read_only_release_evidence_dry_run_result_envelope', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_dry_run_result_envelope_only_no_execution', $payload['authority']);
            $this->assertSame('dry_run_plan_accepted_by_human', data_get($payload, 'dry_run_review_summary.status'));
            $this->assertSame('complete', data_get($payload, 'dry_run_result_evidence.status'));
            $this->assertSame('offline dry-run fixture runner', data_get($payload, 'dry_run_result.result_source'));
            $this->assertSame('future_ap_may_review_dry_run_result_without_auto_release', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.runs_dry_run'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_dry_run_result_envelope_blocks_when_review_did_not_accept_plan(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-review-blocked', 951);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract::class)->envelope(
                workTitle: 'Blocked dry run result envelope',
                intendedPaths: ['app/New/BlockedDryRunResultEnvelope.php'],
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
                dryRunReviewDecision: 'request_dry_run_plan_changes',
                resultEvidence: $this->passingResultEvidence(),
                dryRunReviewReason: 'Add rollback replay corpus before result review.',
                docsApPath: $dir,
                requestedApNumber: 952,
            );

            $this->assertSame('blocked_by_dry_run_review_contract', $payload['status']);
            $this->assertSame('dry_run_plan_changes_requested_by_human', data_get($payload, 'dry_run_review_summary.status'));
            $this->assertSame('repair_or_accept_dry_run_plan_before_result_envelope', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_dry_run_result_envelope_blocks_incomplete_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-incomplete', 953);
        $resultEvidence = $this->passingResultEvidence();
        $resultEvidence['confirmed_no_ledger_write'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract::class)->envelope(
                workTitle: 'Incomplete dry run result envelope',
                intendedPaths: ['app/New/IncompleteDryRunResultEnvelope.php'],
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
                docsApPath: $dir,
                requestedApNumber: 954,
            );

            $this->assertSame('dry_run_result_evidence_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'dry_run_result_evidence.status'));
            $this->assertSame(['confirmed_no_ledger_write'], data_get($payload, 'dry_run_result_evidence.failed_keys'));
            $this->assertSame('complete_dry_run_result_evidence_before_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_dry_run_result_envelope_blocks_invalid_shape(): void
    {
        $dir = $this->makeApDir('release-evidence-dry-run-result-invalid', 955);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract::class)->envelope(
                workTitle: 'Invalid dry run result envelope',
                intendedPaths: ['app/New/InvalidDryRunResultEnvelope.php'],
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
                resultEvidence: [
                    'reviewed_dry_run_review_decision' => 'yes',
                    'declared_result_source' => true,
                    'declared_fixture_or_corpus_used' => true,
                    'declared_outcome_summary' => true,
                    'declared_failure_observations' => true,
                    'confirmed_no_real_mutation' => true,
                    'confirmed_no_publish' => true,
                    'confirmed_no_ledger_write' => true,
                    'result_source' => '',
                    'fixture_or_corpus_used' => 'offline fixture ledger replay corpus',
                    'outcome_summary' => 'candidate validated',
                    'failure_observations' => 'none observed',
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 956,
            );

            $this->assertSame('blocked_invalid_dry_run_result_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'dry_run_result_evidence.status'));
            $this->assertSame(3, data_get($payload, 'dry_run_result_evidence.shape_error_count'));
            $this->assertSame('fix_dry_run_result_shape_before_review', $payload['next_action']);
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
            'execution_ap' => 'AP-226',
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
