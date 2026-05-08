<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacketTest extends TestCase
{
    public function test_post_dry_run_handoff_is_ready_after_accepted_result_review_and_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-post-dry-run-handoff-ready', 930);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket::class)->packet(
                workTitle: 'Prepare post dry run release evidence handoff',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket.php'],
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
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_post_dry_run_handoff_packet.v1', $payload['schema_version']);
            $this->assertSame('post_dry_run_handoff_ready_for_future_release_or_ledger_ap', $payload['status']);
            $this->assertSame('read_only_release_evidence_post_dry_run_handoff_packet', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_post_dry_run_handoff_only_no_execution', $payload['authority']);
            $this->assertSame('dry_run_result_accepted_by_human', data_get($payload, 'dry_run_result_review_summary.status'));
            $this->assertSame('complete', data_get($payload, 'post_dry_run_handoff_evidence.status'));
            $this->assertSame('AP-228', data_get($payload, 'handoff_target.future_consumer_ap'));
            $this->assertSame('future_release_or_ledger_ap_may_review_handoff_without_auto_execution', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
            $this->assertFalse(data_get($payload, 'guardrails.creates_runtime_job'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_post_dry_run_handoff_blocks_when_result_review_is_not_accepted(): void
    {
        $dir = $this->makeApDir('release-evidence-post-dry-run-handoff-review-blocked', 931);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket::class)->packet(
                workTitle: 'Blocked post dry run handoff',
                intendedPaths: ['app/New/BlockedPostDryRunHandoff.php'],
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
                postDryRunHandoffEvidence: $this->passingPostDryRunHandoffEvidence(),
                resultReviewReason: 'Replay checksum must be added before handoff.',
                docsApPath: $dir,
                requestedApNumber: 932,
            );

            $this->assertSame('blocked_by_dry_run_result_review_contract', $payload['status']);
            $this->assertSame('dry_run_result_changes_requested_by_human', data_get($payload, 'dry_run_result_review_summary.status'));
            $this->assertSame('repair_or_accept_dry_run_result_review_before_handoff', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_post_dry_run_handoff_blocks_incomplete_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-post-dry-run-handoff-incomplete', 933);
        $postDryRunHandoffEvidence = $this->passingPostDryRunHandoffEvidence();
        $postDryRunHandoffEvidence['confirmed_no_runtime_job'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket::class)->packet(
                workTitle: 'Incomplete post dry run handoff',
                intendedPaths: ['app/New/IncompletePostDryRunHandoff.php'],
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
                postDryRunHandoffEvidence: $postDryRunHandoffEvidence,
                docsApPath: $dir,
                requestedApNumber: 934,
            );

            $this->assertSame('post_dry_run_handoff_evidence_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'post_dry_run_handoff_evidence.status'));
            $this->assertSame(['confirmed_no_runtime_job'], data_get($payload, 'post_dry_run_handoff_evidence.failed_keys'));
            $this->assertSame('complete_post_dry_run_handoff_evidence_before_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_post_dry_run_handoff_blocks_invalid_shape(): void
    {
        $dir = $this->makeApDir('release-evidence-post-dry-run-handoff-invalid', 935);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket::class)->packet(
                workTitle: 'Invalid post dry run handoff',
                intendedPaths: ['app/New/InvalidPostDryRunHandoff.php'],
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
                postDryRunHandoffEvidence: [
                    'reviewed_dry_run_result_review' => 'yes',
                    'declared_future_consumer_ap' => true,
                    'declared_handoff_package' => true,
                    'confirmed_accepted_result_only' => true,
                    'confirmed_no_auto_release' => true,
                    'confirmed_no_ledger_write' => true,
                    'confirmed_no_runtime_job' => true,
                    'future_consumer_ap' => '',
                    'handoff_package' => 'accepted dry-run result plus review summary',
                    'owner' => 'future-release-or-evidence-runtime',
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 936,
            );

            $this->assertSame('blocked_invalid_post_dry_run_handoff_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'post_dry_run_handoff_evidence.status'));
            $this->assertSame(3, data_get($payload, 'post_dry_run_handoff_evidence.shape_error_count'));
            $this->assertSame('fix_post_dry_run_handoff_shape_before_review', $payload['next_action']);
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
            'execution_ap' => 'AP-228',
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

    /**
     * @return array<string,mixed>
     */
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
            'future_consumer_ap' => 'AP-228',
            'handoff_package' => 'accepted dry-run result plus review summary',
            'owner' => 'future-release-or-evidence-runtime',
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
