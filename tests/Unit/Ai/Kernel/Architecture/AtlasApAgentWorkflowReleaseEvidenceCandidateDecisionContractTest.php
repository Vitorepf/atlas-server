<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContractTest extends TestCase
{
    public function test_candidate_decision_accepts_ready_candidate_without_execution(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-decision-ready', 970);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract::class)->decide(
                workTitle: 'Accept release evidence candidate',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract.php'],
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
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_candidate_decision_contract.v1', $payload['schema_version']);
            $this->assertSame('release_evidence_candidate_accepted_by_human', $payload['status']);
            $this->assertSame('read_only_release_evidence_candidate_decision', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_candidate_decision_only_no_execution', $payload['authority']);
            $this->assertSame('release_evidence_candidate_ready_for_human_review', data_get($payload, 'release_evidence_candidate_summary.status'));
            $this->assertSame('accept_candidate', data_get($payload, 'candidate_decision.value'));
            $this->assertSame('valid', data_get($payload, 'candidate_decision.status'));
            $this->assertSame('future_ap_may_consume_accepted_candidate_without_auto_execution', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_candidate_decision_routes_change_request_with_reason(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-decision-changes', 980);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract::class)->decide(
                workTitle: 'Request candidate changes',
                intendedPaths: ['app/New/CandidateDecisionChanges.php'],
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
                candidateDecisionReason: 'Payload schema needs human-readable replay fields.',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('release_evidence_candidate_changes_requested_by_human', $payload['status']);
            $this->assertSame('request_candidate_changes', data_get($payload, 'candidate_decision.value'));
            $this->assertSame('Payload schema needs human-readable replay fields.', data_get($payload, 'candidate_decision.reason'));
            $this->assertSame('repair_candidate_contract_before_new_human_decision', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_candidate_decision_requires_reason_for_changes_or_rejection(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-decision-invalid', 990);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract::class)->decide(
                workTitle: 'Invalid candidate decision',
                intendedPaths: ['app/New/InvalidCandidateDecision.php'],
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
                candidateDecision: 'reject_candidate',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('blocked_invalid_candidate_decision', $payload['status']);
            $this->assertSame('invalid', data_get($payload, 'candidate_decision.status'));
            $this->assertSame('candidate_decision_reason_required', data_get($payload, 'candidate_decision.errors.0.id'));
            $this->assertSame('fix_candidate_decision_value_or_reason_before_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_candidate_decision_blocks_accept_when_candidate_is_not_ready(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-decision-blocked', 995);
        $candidateEvidence = $this->passingCandidateEvidence();
        $candidateEvidence['confirmed_no_runtime_mutation'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract::class)->decide(
                workTitle: 'Blocked candidate decision',
                intendedPaths: ['app/New/BlockedCandidateDecision.php'],
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
                candidateDecision: 'accept_candidate',
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_by_release_evidence_candidate_contract', $payload['status']);
            $this->assertSame('release_evidence_candidate_incomplete', data_get($payload, 'release_evidence_candidate_summary.status'));
            $this->assertSame('repair_release_evidence_candidate_before_human_decision', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContractTest.php'],
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
            'future_ap' => 'AP-221',
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
