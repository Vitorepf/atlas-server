<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowReleaseEvidenceHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowReleaseEvidenceHandoffPacketTest extends TestCase
{
    public function test_release_evidence_handoff_packet_is_ready_after_preflight_and_handoff_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-handoff-ready', 970);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceHandoffPacket::class)->packet(
                workTitle: 'Prepare release evidence handoff',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacket.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                handoffEvidence: $this->passingHandoffEvidence(),
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_release_evidence_handoff_packet.v1', $payload['schema_version']);
            $this->assertSame('ready_for_release_evidence_owner_review', $payload['status']);
            $this->assertSame('read_only_release_evidence_handoff_packet', $payload['mode']);
            $this->assertSame('ap_agent_workflow_release_evidence_handoff_only_no_execution', $payload['authority']);
            $this->assertSame('ready_for_future_release_or_evidence_layer', data_get($payload, 'release_evidence_preflight_summary.status'));
            $this->assertSame('complete', data_get($payload, 'handoff_evidence.status'));
            $this->assertSame('AP-219', data_get($payload, 'handoff_target.future_ap'));
            $this->assertSame('future_release_or_evidence_owner_reviews_packet_before_new_ap', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_handoff_packet_blocks_when_preflight_is_not_ready(): void
    {
        $dir = $this->makeApDir('release-evidence-handoff-blocked', 980);
        $preflightEvidence = $this->passingPreflightEvidence();
        $preflightEvidence['confirmed_no_auto_publish'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceHandoffPacket::class)->packet(
                workTitle: 'Blocked release evidence handoff',
                intendedPaths: ['app/New/BlockedReleaseEvidenceHandoff.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $preflightEvidence,
                handoffEvidence: $this->passingHandoffEvidence(),
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_release_evidence_preflight', $payload['status']);
            $this->assertSame('release_evidence_preflight_incomplete', data_get($payload, 'release_evidence_preflight_summary.status'));
            $this->assertSame('repair_release_evidence_preflight_before_handoff_packet', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_handoff_packet_blocks_incomplete_evidence(): void
    {
        $dir = $this->makeApDir('release-evidence-handoff-incomplete', 990);
        $handoffEvidence = $this->passingHandoffEvidence();
        $handoffEvidence['confirmed_no_direct_ledger_write'] = false;

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceHandoffPacket::class)->packet(
                workTitle: 'Incomplete release evidence handoff',
                intendedPaths: ['app/New/IncompleteReleaseEvidenceHandoff.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                handoffEvidence: $handoffEvidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('release_evidence_handoff_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'handoff_evidence.status'));
            $this->assertSame(['confirmed_no_direct_ledger_write'], data_get($payload, 'handoff_evidence.failed_keys'));
            $this->assertSame('complete_release_evidence_handoff_before_owner_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_release_evidence_handoff_packet_blocks_invalid_evidence_shape(): void
    {
        $dir = $this->makeApDir('release-evidence-handoff-invalid', 995);

        try {
            $payload = app(AtlasApAgentWorkflowReleaseEvidenceHandoffPacket::class)->packet(
                workTitle: 'Invalid release evidence handoff',
                intendedPaths: ['app/New/InvalidReleaseEvidenceHandoff.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                closeoutDecision: 'accept_closeout',
                preflightEvidence: $this->passingPreflightEvidence(),
                handoffEvidence: [
                    'reviewed_release_evidence_preflight' => 'yes',
                    'confirmed_future_ap_owner' => true,
                    'confirmed_no_runtime_side_effect' => true,
                    'confirmed_no_direct_release_execution' => true,
                    'confirmed_no_direct_ledger_write' => true,
                    'confirmed_followup_ap_required' => true,
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_release_evidence_handoff_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'handoff_evidence.status'));
            $this->assertSame(2, data_get($payload, 'handoff_evidence.shape_error_count'));
            $this->assertSame('fix_release_evidence_handoff_shape_before_owner_review', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceHandoffPacketTest.php'],
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
            'future_ap' => 'AP-219',
            'owner' => 'future-release-or-evidence-layer',
            'commands' => ['atlas engineering knowledge docs-health'],
            'notes' => ['handoff packet fixture only'],
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
