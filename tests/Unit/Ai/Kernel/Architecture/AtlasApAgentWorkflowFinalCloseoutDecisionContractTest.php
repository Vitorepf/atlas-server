<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowFinalCloseoutDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowFinalCloseoutDecisionContractTest extends TestCase
{
    public function test_closeout_decision_accepts_ready_final_audit_packet_without_execution(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-closeout-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-final-closeout-decision.md', implode("\n", [
                '---',
                'title: AP-970 Final Closeout Decision',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContract.php',
                '---',
                '# AP-970 Final Closeout Decision',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowFinalCloseoutDecisionContract::class)->decide(
                workTitle: 'Accept final closeout decision',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContract.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_final_closeout_decision_contract.v1', $payload['schema_version']);
            $this->assertSame('closeout_accepted_by_human', $payload['status']);
            $this->assertSame('read_only_final_closeout_decision_contract', $payload['mode']);
            $this->assertSame('ap_agent_workflow_final_closeout_decision_only_no_execution', $payload['authority']);
            $this->assertSame('accept_closeout', data_get($payload, 'closeout_decision.value'));
            $this->assertFalse(data_get($payload, 'closeout_decision.reason_required'));
            $this->assertSame('ready_for_final_human_closeout_review', data_get($payload, 'final_audit_packet_summary.status'));
            $this->assertSame('handoff_closeout_acceptance_to_release_or_evidence_layer_without_auto_publish', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.auto_closes_work'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_closeout_decision_blocks_accept_when_final_audit_packet_is_not_ready(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-closeout-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-final-closeout-decision.md', "# AP-980 Final Closeout Decision\n");

            $payload = app(AtlasApAgentWorkflowFinalCloseoutDecisionContract::class)->decide(
                workTitle: 'Blocked final closeout decision',
                intendedPaths: ['app/New/BlockedFinalCloseoutDecision.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_accept_requires_ready_final_audit_packet', $payload['status']);
            $this->assertSame('blocked_by_manual_integration_receipt', data_get($payload, 'final_audit_packet_summary.status'));
            $this->assertSame('repair_final_audit_packet_before_closeout_acceptance', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_closeout_decision_requires_reason_for_changes_or_rejection(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-closeout-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-final-closeout-decision.md', "# AP-990 Final Closeout Decision\n");

            $missingReason = app(AtlasApAgentWorkflowFinalCloseoutDecisionContract::class)->decide(
                workTitle: 'Missing closeout reason',
                intendedPaths: ['app/New/MissingCloseoutReason.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'request_closeout_changes',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $withReason = app(AtlasApAgentWorkflowFinalCloseoutDecisionContract::class)->decide(
                workTitle: 'Request closeout changes',
                intendedPaths: ['app/New/RequestCloseoutChanges.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'request_closeout_changes',
                closeoutReason: 'Final risk summary needs one more concrete note.',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('blocked_missing_closeout_reason', $missingReason['status']);
            $this->assertSame('provide_closeout_reason_before_decision', $missingReason['next_action']);
            $this->assertSame('closeout_changes_requested_by_human', $withReason['status']);
            $this->assertSame('Final risk summary needs one more concrete note.', data_get($withReason, 'closeout_decision.reason'));
            $this->assertSame('return_closeout_notes_to_integrator_for_repair', $withReason['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_closeout_decision_blocks_unknown_decision_value(): void
    {
        $payload = app(AtlasApAgentWorkflowFinalCloseoutDecisionContract::class)->decide(
            workTitle: 'Unknown closeout decision',
            intendedPaths: ['app/New/UnknownCloseoutDecision.php'],
            validationEvidence: $this->passingValidationEvidence(),
            traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
            decision: 'accept',
            integratorEvidence: $this->passingIntegratorEvidence(),
            integrationEvidence: $this->passingIntegrationEvidence(),
            finalAuditEvidence: $this->passingFinalAuditEvidence(),
            closeoutDecision: 'publish_release',
            requestedApNumber: 215,
        );

        $this->assertSame('blocked_invalid_closeout_decision', $payload['status']);
        $this->assertSame(['accept_closeout', 'request_closeout_changes', 'reject_closeout'], data_get($payload, 'closeout_decision.allowed_values'));
        $this->assertSame('choose_allowed_closeout_decision_value', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContractTest.php'],
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
            'commands' => ['git diff --check'],
            'notes' => ['manual integrator evidence fixture'],
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalCloseoutDecisionContractTest.php'],
            'notes' => ['manual integration receipt fixture'],
            'integration_reference' => 'local-manual-review',
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
            'commands' => ['git status --short', 'git diff --check'],
            'notes' => ['final audit fixture'],
            'risk_summary' => 'No unresolved fixture risk.',
        ];
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
