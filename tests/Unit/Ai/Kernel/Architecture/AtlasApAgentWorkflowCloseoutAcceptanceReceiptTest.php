<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowCloseoutAcceptanceReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowCloseoutAcceptanceReceiptTest extends TestCase
{
    public function test_closeout_acceptance_receipt_reports_human_closeout_acceptance(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-closeout-acceptance-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-closeout-acceptance-receipt.md', implode("\n", [
                '---',
                'title: AP-970 Closeout Acceptance Receipt',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceipt.php',
                '---',
                '# AP-970 Closeout Acceptance Receipt',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowCloseoutAcceptanceReceipt::class)->receipt(
                workTitle: 'Report closeout acceptance receipt',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_closeout_acceptance_receipt.v1', $payload['schema_version']);
            $this->assertSame('closeout_acceptance_reported', $payload['status']);
            $this->assertSame('read_only_closeout_acceptance_receipt', $payload['mode']);
            $this->assertSame('ap_agent_workflow_closeout_acceptance_receipt_only_no_execution', $payload['authority']);
            $this->assertSame('closeout_accepted_by_human', data_get($payload, 'closeout_decision_summary.status'));
            $this->assertSame('accept_closeout', data_get($payload, 'closeout_decision_summary.decision'));
            $this->assertSame('handoff_to_future_release_or_evidence_receipt_without_auto_publish', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_closeout_acceptance_receipt_routes_change_request_to_repair(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-closeout-acceptance-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-closeout-acceptance-receipt.md', "# AP-980 Closeout Acceptance Receipt\n");

            $payload = app(AtlasApAgentWorkflowCloseoutAcceptanceReceipt::class)->receipt(
                workTitle: 'Return closeout for repair',
                intendedPaths: ['app/New/ReturnCloseoutForRepair.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'request_closeout_changes',
                closeoutReason: 'Closeout notes need more detail.',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('closeout_returned_for_repair', $payload['status']);
            $this->assertSame('closeout_changes_requested_by_human', data_get($payload, 'closeout_decision_summary.status'));
            $this->assertSame('Closeout notes need more detail.', data_get($payload, 'closeout_decision_summary.reason'));
            $this->assertSame('integrator_repairs_closeout_notes_before_new_audit', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_closeout_acceptance_receipt_stops_on_human_rejection(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-closeout-acceptance-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-closeout-acceptance-receipt.md', "# AP-990 Closeout Acceptance Receipt\n");

            $payload = app(AtlasApAgentWorkflowCloseoutAcceptanceReceipt::class)->receipt(
                workTitle: 'Reject closeout acceptance receipt',
                intendedPaths: ['app/New/RejectCloseoutAcceptanceReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'reject_closeout',
                closeoutReason: 'Scope should not close yet.',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('closeout_stopped_by_rejection', $payload['status']);
            $this->assertSame('closeout_rejected_by_human', data_get($payload, 'closeout_decision_summary.status'));
            $this->assertSame('stop_closeout_flow_until_scope_is_reopened', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_closeout_acceptance_receipt_blocks_when_closeout_decision_is_blocked(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-closeout-acceptance-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-995-closeout-acceptance-receipt.md', "# AP-995 Closeout Acceptance Receipt\n");

            $payload = app(AtlasApAgentWorkflowCloseoutAcceptanceReceipt::class)->receipt(
                workTitle: 'Blocked closeout acceptance receipt',
                intendedPaths: ['app/New/BlockedCloseoutAcceptanceReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_by_closeout_decision_contract', $payload['status']);
            $this->assertSame('blocked_accept_requires_ready_final_audit_packet', data_get($payload, 'closeout_decision_summary.status'));
            $this->assertSame('repair_closeout_decision_before_acceptance_receipt', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceiptTest.php'],
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowCloseoutAcceptanceReceiptTest.php'],
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
