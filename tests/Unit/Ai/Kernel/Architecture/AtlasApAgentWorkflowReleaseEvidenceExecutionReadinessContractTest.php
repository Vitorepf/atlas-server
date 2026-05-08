<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContractTest extends TestCase
{
    public function test_execution_readiness_is_ready_after_accepted_receipt_and_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-execution-readiness-ready', 970);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract::class)->evaluate(
                workTitle: 'Prepare release evidence execution readiness',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract.php'],
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
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_execution_readiness_contract.v1', $payload['schema_version']);
            $this->assertSame('ready_for_future_release_or_ledger_execution_ap', $payload['status']);
            $this->assertSame('read_only_release_evidence_execution_readiness', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_execution_readiness_only_no_execution', $payload['authority']);
            $this->assertSame('release_evidence_candidate_acceptance_reported', data_get($payload, 'candidate_decision_receipt_summary.status'));
            $this->assertSame('complete', data_get($payload, 'readiness_evidence.status'));
            $this->assertSame('AP-223', data_get($payload, 'execution_target.execution_ap'));
            $this->assertSame('future_execution_ap_may_review_readiness_without_auto_execution', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_readiness_blocks_when_receipt_is_not_accepted(): void
    {
        $dir = $this->makeApDir('release-evidence-execution-readiness-blocked', 980);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract::class)->evaluate(
                workTitle: 'Blocked release evidence execution readiness',
                intendedPaths: ['app/New/BlockedExecutionReadiness.php'],
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
                candidateDecision: 'request_candidate_changes',
                readinessEvidence: $this->passingReadinessEvidence(),
                candidateDecisionReason: 'Needs replay references.',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_candidate_decision_receipt', $payload['status']);
            $this->assertSame('release_evidence_candidate_returned_for_repair', data_get($payload, 'candidate_decision_receipt_summary.status'));
            $this->assertSame('repair_candidate_decision_receipt_before_execution_readiness', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_readiness_blocks_incomplete_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-execution-readiness-incomplete', 990);
        $readinessEvidence = $this->passingReadinessEvidence();
        $readinessEvidence['confirmed_dry_run_required'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract::class)->evaluate(
                workTitle: 'Incomplete release evidence execution readiness',
                intendedPaths: ['app/New/IncompleteExecutionReadiness.php'],
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
                readinessEvidence: $readinessEvidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('release_evidence_execution_readiness_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'readiness_evidence.status'));
            $this->assertSame(['confirmed_dry_run_required'], data_get($payload, 'readiness_evidence.failed_keys'));
            $this->assertSame('complete_execution_readiness_evidence_before_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_readiness_blocks_invalid_evidence_shape(): void
    {
        $dir = $this->makeApDir('release-evidence-execution-readiness-invalid', 995);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract::class)->evaluate(
                workTitle: 'Invalid release evidence execution readiness',
                intendedPaths: ['app/New/InvalidExecutionReadiness.php'],
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
                readinessEvidence: [
                    'reviewed_candidate_decision_receipt' => 'yes',
                    'confirmed_execution_ap_owner' => true,
                    'confirmed_payload_schema_final' => true,
                    'confirmed_replay_or_rollback_plan' => true,
                    'confirmed_privacy_and_policy_review' => true,
                    'confirmed_dry_run_required' => true,
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_execution_readiness_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'readiness_evidence.status'));
            $this->assertSame(2, data_get($payload, 'readiness_evidence.shape_error_count'));
            $this->assertSame('fix_execution_readiness_evidence_shape_before_review', $payload['next_action']);
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
            'future_ap' => 'AP-223',
            'owner' => 'future-release-or-evidence-layer',
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
            'execution_ap' => 'AP-223',
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
