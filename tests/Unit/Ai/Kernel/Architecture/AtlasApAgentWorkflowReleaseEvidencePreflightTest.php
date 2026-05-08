<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidencePreflight;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidencePreflightTest extends TestCase
{
    public function test_release_evidence_preflight_is_ready_after_closeout_acceptance_and_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-release-evidence-preflight-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-release-evidence-preflight.md', implode("\n", [
                '---',
                'title: AP-970 Release Evidence Preflight',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflight.php',
                '---',
                '# AP-970 Release Evidence Preflight',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowReleaseEvidencePreflight::class)->preflight(
                workTitle: 'Prepare release evidence preflight',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflight.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_preflight.v1', $payload['schema_version']);
            $this->assertSame('ready_for_future_release_or_evidence_layer', $payload['status']);
            $this->assertSame('read_only_release_evidence_preflight', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_preflight_only_no_execution', $payload['authority']);
            $this->assertSame('closeout_acceptance_reported', data_get($payload, 'closeout_acceptance_summary.status'));
            $this->assertSame('complete', data_get($payload, 'preflight_evidence.status'));
            $this->assertSame('future_release_or_evidence_ap_may_consume_preflight_without_auto_execution', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.emits_evidence_event'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_preflight_blocks_when_closeout_receipt_is_not_accepted(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-release-evidence-preflight-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-release-evidence-preflight.md', "# AP-980 Release Evidence Preflight\n");

            $payload = app(AtlasApAgentWorkflowReleaseEvidencePreflight::class)->preflight(
                workTitle: 'Blocked release evidence preflight',
                intendedPaths: ['app/New/BlockedReleaseEvidencePreflight.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_closeout_acceptance_receipt', $payload['status']);
            $this->assertSame('blocked_by_closeout_decision_contract', data_get($payload, 'closeout_acceptance_summary.status'));
            $this->assertSame('repair_closeout_acceptance_receipt_before_preflight', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_preflight_blocks_incomplete_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-release-evidence-preflight-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-release-evidence-preflight.md', "# AP-990 Release Evidence Preflight\n");
            $preflightEvidence = $this->passingPreflightEvidence();
            $preflightEvidence['confirmed_no_auto_evidence_emit'] = false;

            $payload = app(AtlasApAgentWorkflowReleaseEvidencePreflight::class)->preflight(
                workTitle: 'Incomplete release evidence preflight',
                intendedPaths: ['app/New/IncompleteReleaseEvidencePreflight.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $preflightEvidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('release_evidence_preflight_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'preflight_evidence.status'));
            $this->assertSame(['confirmed_no_auto_evidence_emit'], data_get($payload, 'preflight_evidence.failed_keys'));
            $this->assertSame('complete_release_evidence_preflight_before_handoff', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_preflight_blocks_invalid_evidence_shape(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-release-evidence-preflight-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-995-release-evidence-preflight.md', "# AP-995 Release Evidence Preflight\n");

            $payload = app(AtlasApAgentWorkflowReleaseEvidencePreflight::class)->preflight(
                workTitle: 'Invalid release evidence preflight',
                intendedPaths: ['app/New/InvalidReleaseEvidencePreflight.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: [
                    'reviewed_closeout_acceptance_receipt' => 'yes',
                    'selected_future_surface' => true,
                    'confirmed_release_or_ledger_owner' => true,
                    'confirmed_no_auto_publish' => true,
                    'confirmed_no_auto_evidence_emit' => true,
                    'confirmed_post_closeout_risks_recorded' => true,
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_release_evidence_preflight_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'preflight_evidence.status'));
            $this->assertSame(2, data_get($payload, 'preflight_evidence.shape_error_count'));
            $this->assertSame('fix_release_evidence_preflight_shape_before_handoff', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidencePreflightTest.php'],
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

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
