<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowFinalAuditPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowFinalAuditPacketTest extends TestCase
{
    public function test_final_audit_packet_is_ready_after_manual_receipt_and_audit_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-audit-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-final-audit-packet.md', implode("\n", [
                '---',
                'title: AP-970 Final Audit Packet',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalAuditPacket.php',
                '---',
                '# AP-970 Final Audit Packet',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowFinalAuditPacket::class)->packet(
                workTitle: 'Prepare final audit packet',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalAuditPacket.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_final_audit_packet.v1', $payload['schema_version']);
            $this->assertSame('ready_for_final_human_closeout_review', $payload['status']);
            $this->assertSame('read_only_final_audit_packet', $payload['mode']);
            $this->assertSame('ap_agent_workflow_final_audit_packet_only_no_execution', $payload['authority']);
            $this->assertSame('manual_integration_reported', data_get($payload, 'manual_integration_receipt_summary.status'));
            $this->assertSame('complete', data_get($payload, 'final_audit_evidence.status'));
            $this->assertSame(6, data_get($payload, 'final_audit_evidence.passed_count'));
            $this->assertSame('present_final_audit_packet_to_human_before_closeout', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.publishes_release'));
            $this->assertFalse(data_get($payload, 'guardrails.closes_without_final_audit_evidence'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_final_audit_packet_blocks_when_manual_receipt_is_not_reported(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-audit-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-final-audit-packet.md', "# AP-980 Final Audit Packet\n");

            $payload = app(AtlasApAgentWorkflowFinalAuditPacket::class)->packet(
                workTitle: 'Blocked final audit packet',
                intendedPaths: ['app/New/BlockedFinalAuditPacket.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $this->passingFinalAuditEvidence(),
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_by_manual_integration_receipt', $payload['status']);
            $this->assertSame('blocked_by_integrator_readiness', data_get($payload, 'manual_integration_receipt_summary.status'));
            $this->assertSame('repair_manual_integration_receipt_before_final_audit', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_final_audit_packet_blocks_incomplete_final_audit_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-audit-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-final-audit-packet.md', "# AP-990 Final Audit Packet\n");
            $auditEvidence = $this->passingFinalAuditEvidence();
            $auditEvidence['reviewed_remaining_risks'] = false;

            $payload = app(AtlasApAgentWorkflowFinalAuditPacket::class)->packet(
                workTitle: 'Incomplete final audit packet',
                intendedPaths: ['app/New/IncompleteFinalAuditPacket.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: $auditEvidence,
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('final_audit_incomplete', $payload['status']);
            $this->assertSame('incomplete', data_get($payload, 'final_audit_evidence.status'));
            $this->assertSame(['reviewed_remaining_risks'], data_get($payload, 'final_audit_evidence.failed_keys'));
            $this->assertSame('complete_final_audit_evidence_before_closeout_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_final_audit_packet_blocks_invalid_final_audit_evidence_shape(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-final-audit-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-995-final-audit-packet.md', "# AP-995 Final Audit Packet\n");

            $payload = app(AtlasApAgentWorkflowFinalAuditPacket::class)->packet(
                workTitle: 'Invalid final audit packet',
                intendedPaths: ['app/New/InvalidFinalAuditPacket.php'],
                validationEvidence: $this->passingValidationEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                integratorEvidence: $this->passingIntegratorEvidence(),
                integrationEvidence: $this->passingIntegrationEvidence(),
                finalAuditEvidence: [
                    'reviewed_manual_integration_receipt' => 'yes',
                    'reviewed_final_validation_commands' => true,
                    'reviewed_documentation_status' => true,
                    'reviewed_no_untracked_surprise' => true,
                    'reviewed_no_parallel_flow_created' => true,
                    'reviewed_remaining_risks' => true,
                    'extra' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_invalid_final_audit_evidence_shape', $payload['status']);
            $this->assertSame('invalid_shape', data_get($payload, 'final_audit_evidence.status'));
            $this->assertSame(2, data_get($payload, 'final_audit_evidence.shape_error_count'));
            $this->assertSame('fix_final_audit_evidence_shape_before_closeout_review', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalAuditPacketTest.php'],
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowFinalAuditPacketTest.php'],
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
