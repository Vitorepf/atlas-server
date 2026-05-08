<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowManualIntegrationReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowManualIntegrationReceiptTest extends TestCase
{
    public function test_manual_integration_receipt_reports_integration_after_readiness_and_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-manual-integration-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-manual-integration-receipt.md', implode("\n", [
                '---',
                'title: AP-970 Manual Integration Receipt',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceipt.php',
                '---',
                '# AP-970 Manual Integration Receipt',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowManualIntegrationReceipt::class)->receipt(
                workTitle: 'Report manual integration receipt',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_manual_integration_receipt.v1', $payload['schema_version']);
            $this->assertSame('manual_integration_reported', $payload['status']);
            $this->assertSame('read_only_manual_integration_receipt', $payload['mode']);
            $this->assertSame('ap_agent_workflow_manual_integration_receipt_only_no_execution', $payload['authority']);
            $this->assertSame('ready_for_manual_integration', data_get($payload, 'readiness_summary.status'));
            $this->assertSame('complete', data_get($payload, 'manual_integration_evidence.status'));
            $this->assertSame(6, data_get($payload, 'manual_integration_evidence.passed_count'));
            $this->assertSame('present_manual_integration_receipt_for_final_human_audit', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.auto_merges_work'));
            $this->assertFalse(data_get($payload, 'guardrails.claims_integration_without_readiness'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_manual_integration_receipt_blocks_when_readiness_is_not_ready(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-manual-integration-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-manual-integration-receipt.md', "# AP-980 Manual Integration Receipt\n");

            $payload = app(AtlasApAgentWorkflowManualIntegrationReceipt::class)->receipt(
                workTitle: 'Blocked manual integration receipt',
                intendedPaths: ['app/New/BlockedManualIntegrationReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_integrator_readiness', $payload['status']);
            $this->assertSame('blocked_by_integrator_handoff', data_get($payload, 'readiness_summary.status'));
            $this->assertSame('repair_integrator_readiness_before_reporting_integration', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_manual_integration_receipt_blocks_incomplete_integration_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-manual-integration-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-manual-integration-receipt.md', "# AP-990 Manual Integration Receipt\n");
            $integrationEvidence = $this->passingIntegrationEvidence();
            $integrationEvidence['final_docs_health_checked'] = false;

            $payload = app(AtlasApAgentWorkflowManualIntegrationReceipt::class)->receipt(
                workTitle: 'Incomplete manual integration receipt',
                intendedPaths: ['app/New/IncompleteManualIntegrationReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $integrationEvidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('manual_integration_evidence_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'manual_integration_evidence.status'));
            $this->assertSame(['final_docs_health_checked'], data_get($payload, 'manual_integration_evidence.failed_keys'));
            $this->assertSame('complete_manual_integration_evidence_before_receipt', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_manual_integration_receipt_blocks_invalid_integration_evidence_shape(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-manual-integration-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-995-manual-integration-receipt.md', "# AP-995 Manual Integration Receipt\n");

            $payload = app(AtlasApAgentWorkflowManualIntegrationReceipt::class)->receipt(
                workTitle: 'Invalid manual integration receipt',
                intendedPaths: ['app/New/InvalidManualIntegrationReceipt.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: [
                    'manually_applied_by_integrator' => 'yes',
                    'applied_paths_match_handoff_scope' => true,
                    'final_diff_reviewed' => true,
                    'final_validation_reran' => true,
                    'final_docs_health_checked' => true,
                    'no_unrelated_work_included' => true,
                    'unknown' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_manual_integration_evidence_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'manual_integration_evidence.status'));
            $this->assertSame(2, data_get($payload, 'manual_integration_evidence.shape_error_count'));
            $this->assertSame('fix_manual_integration_evidence_shape_before_receipt', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceiptTest.php'],
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowManualIntegrationReceiptTest.php'],
            'notes' => ['manual integration receipt fixture'],
            'integration_reference' => 'local-manual-review',
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
