<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceiptTest extends TestCase
{
    public function test_candidate_decision_receipt_reports_acceptance_without_execution(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-receipt-ready', 970);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt::class)->receipt(
                workTitle: 'Receipt accepted release evidence candidate',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt.php'],
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

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_candidate_decision_receipt.v1', $payload['schema_version']);
            $this->assertSame('release_evidence_candidate_acceptance_reported', $payload['status']);
            $this->assertSame('read_only_release_evidence_candidate_decision_receipt', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_candidate_receipt_only_no_execution', $payload['authority']);
            $this->assertSame('release_evidence_candidate_accepted_by_human', data_get($payload, 'candidate_decision_summary.status'));
            $this->assertSame('accept_candidate', data_get($payload, 'candidate_decision_summary.decision'));
            $this->assertSame('evidence_ledger_candidate', data_get($payload, 'candidate_decision_summary.candidate_kind'));
            $this->assertSame('future_release_or_ledger_ap_may_consume_receipt_without_auto_execution', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_candidate_decision_receipt_routes_change_request_to_repair(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-receipt-repair', 980);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt::class)->receipt(
                workTitle: 'Receipt candidate change request',
                intendedPaths: ['app/New/CandidateReceiptRepair.php'],
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
                candidateDecisionReason: 'Need explicit replay references.',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('release_evidence_candidate_returned_for_repair', $payload['status']);
            $this->assertSame('request_candidate_changes', data_get($payload, 'candidate_decision_summary.decision'));
            $this->assertSame('Need explicit replay references.', data_get($payload, 'candidate_decision_summary.reason'));
            $this->assertSame('repair_candidate_then_request_new_human_decision', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_candidate_decision_receipt_stops_on_rejection(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-receipt-rejected', 990);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt::class)->receipt(
                workTitle: 'Receipt candidate rejection',
                intendedPaths: ['app/New/CandidateReceiptRejected.php'],
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
                candidateDecisionReason: 'Candidate belongs to a future runtime AP.',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('release_evidence_candidate_stopped_by_rejection', $payload['status']);
            $this->assertSame('reject_candidate', data_get($payload, 'candidate_decision_summary.decision'));
            $this->assertSame('stop_candidate_flow_until_scope_reopens', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_candidate_decision_receipt_blocks_when_decision_contract_blocks(): void
    {
        $dir = $this->makeApDir('release-evidence-candidate-receipt-blocked', 995);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt::class)->receipt(
                workTitle: 'Blocked candidate receipt',
                intendedPaths: ['app/New/BlockedCandidateReceipt.php'],
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
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_by_candidate_decision_contract', $payload['status']);
            $this->assertSame('blocked_invalid_candidate_decision', data_get($payload, 'candidate_decision_summary.status'));
            $this->assertSame('repair_candidate_decision_before_receipt', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceiptTest.php'],
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
            'future_ap' => 'AP-222',
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
