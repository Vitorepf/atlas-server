<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceCandidateContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceCandidateContractTest extends TestCase
{
    public function test_release_evidence_candidate_is_ready_after_handoff_and_candidate_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-ready', 970);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateContract::class)->candidate(
                workTitle: 'Prepare release evidence candidate',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContract.php'],
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
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_candidate_contract.v1', $payload['schema_version']);
            $this->assertSame('release_evidence_candidate_ready_for_human_review', $payload['status']);
            $this->assertSame('read_only_release_evidence_candidate_contract', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_candidate_only_no_execution', $payload['authority']);
            $this->assertSame('ready_for_release_evidence_owner_review', data_get($payload, 'release_evidence_handoff_summary.status'));
            $this->assertSame('complete', data_get($payload, 'candidate_evidence.status'));
            $this->assertSame('evidence_ledger_candidate', data_get($payload, 'candidate.kind'));
            $this->assertSame('atlas.evidence_ledger.candidate.v1', data_get($payload, 'candidate.payload_schema'));
            $this->assertSame('human_reviews_candidate_before_any_release_or_ledger_ap_executes', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_candidate_blocks_when_handoff_is_not_ready(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-blocked', 980);
        $handoffEvidence = $this->passingHandoffEvidence();
        $handoffEvidence['confirmed_followup_ap_required'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateContract::class)->candidate(
                workTitle: 'Blocked release evidence candidate',
                intendedPaths: ['app/New/BlockedReleaseEvidenceCandidate.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                handoffEvidence: $handoffEvidence,
                candidateEvidence: $this->passingCandidateEvidence(),
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_release_evidence_handoff_packet', $payload['status']);
            $this->assertSame('release_evidence_handoff_incomplete', data_get($payload, 'release_evidence_handoff_summary.status'));
            $this->assertSame('repair_release_evidence_handoff_packet_before_candidate', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_candidate_blocks_incomplete_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-incomplete', 990);
        $candidateEvidence = $this->passingCandidateEvidence();
        $candidateEvidence['confirmed_no_runtime_mutation'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateContract::class)->candidate(
                workTitle: 'Incomplete release evidence candidate',
                intendedPaths: ['app/New/IncompleteReleaseEvidenceCandidate.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                handoffEvidence: $this->passingHandoffEvidence(),
                candidateEvidence: $candidateEvidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('release_evidence_candidate_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'candidate_evidence.status'));
            $this->assertSame(['confirmed_no_runtime_mutation'], data_get($payload, 'candidate_evidence.failed_keys'));
            $this->assertSame('complete_release_evidence_candidate_before_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_candidate_blocks_invalid_candidate_shape(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-invalid', 995);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateContract::class)->candidate(
                workTitle: 'Invalid release evidence candidate',
                intendedPaths: ['app/New/InvalidReleaseEvidenceCandidate.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                handoffEvidence: $this->passingHandoffEvidence(),
                candidateEvidence: [
                    'reviewed_release_evidence_handoff_packet' => 'yes',
                    'selected_candidate_kind' => true,
                    'described_payload_schema' => true,
                    'confirmed_append_only_or_release_review' => true,
                    'confirmed_human_review_before_execution' => true,
                    'confirmed_no_runtime_mutation' => true,
                    'candidate_kind' => 'auto_publish_release',
                    'payload_schema' => '',
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_release_evidence_candidate_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'candidate_evidence.status'));
            $this->assertSame(4, data_get($payload, 'candidate_evidence.shape_error_count'));
            $this->assertSame('fix_release_evidence_candidate_shape_before_review', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateContractTest.php'],
            'notes' => ['fixture validation only'],
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
            'future_surface' => 'future_ap_release_or_evidence_layer',
            'commands' => ['git diff --check'],
            'notes' => ['release evidence preflight fixture'],
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
            'future_ap' => 'AP-220',
            'owner' => 'future-release-or-evidence-layer',
            'commands' => ['php artisan atlas:engineering:knowledge docs-health --json'],
            'notes' => ['handoff packet fixture only'],
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
            'commands' => ['git diff --check'],
            'notes' => ['candidate fixture only'],
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
