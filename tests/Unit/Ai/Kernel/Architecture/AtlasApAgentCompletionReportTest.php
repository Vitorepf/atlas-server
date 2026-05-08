<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentCompletionReport;
use Tests\TestCase;

final class AtlasApAgentCompletionReportTest extends TestCase
{
    public function test_completion_report_marks_complete_when_gate_and_checklist_pass(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-completion-report-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-900-completion-report.md', implode("\n", [
                '---',
                'title: AP-900 Completion Report',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentCompletionReport.php',
                '---',
                '# AP-900 Completion Report',
                '',
            ]));

            $payload = app(AtlasApAgentCompletionReport::class)->report(
                workTitle: 'Improve completion report',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentCompletionReport.php'],
                validationEvidence: $this->passingEvidence(),
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_completion_report.v1', $payload['schema_version']);
            $this->assertSame('complete', $payload['status']);
            $this->assertSame('read_only_completion_report', $payload['mode']);
            $this->assertSame('ap_agent_completion_report_only_no_command_execution', $payload['authority']);
            $this->assertSame('AP-900', $payload['resolved_target_ap']);
            $this->assertSame('ready_for_existing_ap_work', data_get($payload, 'session_gate.status'));
            $this->assertSame('valid_shape', data_get($payload, 'validation_evidence_shape.status'));
            $this->assertSame('complete', data_get($payload, 'completion_checklist.status'));
            $this->assertSame(0, data_get($payload, 'completion_checklist.failed_count'));
            $this->assertSame('report_completion_summary_with_validation_evidence', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertTrue(data_get($payload, 'guardrails.requires_explicit_validation_evidence'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_completion_report_blocks_when_session_gate_is_blocked(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-completion-report-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-910-broken.md', implode("\n", [
                '---',
                'title: AP-910 Broken',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/MissingCompletionReportTarget.php',
                '---',
                '# AP-910 Broken',
                '',
            ]));

            $payload = app(AtlasApAgentCompletionReport::class)->report(
                workTitle: 'Blocked completion report',
                intendedPaths: ['app/New/BlockedCompletion.php'],
                validationEvidence: $this->passingEvidence(),
                docsApPath: $dir,
                requestedApNumber: 911,
            );

            $this->assertSame('blocked_by_session_gate', $payload['status']);
            $this->assertSame('blocked_by_documentation_repair', data_get($payload, 'session_gate.status'));
            $this->assertSame(1, data_get($payload, 'session_gate.repair_proposal_count'));
            $this->assertSame('valid_shape', data_get($payload, 'validation_evidence_shape.status'));
            $this->assertSame('complete', data_get($payload, 'completion_checklist.status'));
            $this->assertSame('review_repair_proposals_before_creating_or_editing_ap_docs', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_completion_report_is_incomplete_when_validation_evidence_fails(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-completion-report-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-920-clean.md', "# AP-920 Clean\n");

            $payload = app(AtlasApAgentCompletionReport::class)->report(
                workTitle: 'New incomplete completion report',
                intendedPaths: ['app/New/IncompleteCompletion.php'],
                validationEvidence: [
                    'code_or_doc_changes_scoped' => true,
                    'focused_tests_passed' => false,
                    'docs_health_ok' => true,
                    'architecture_validate_ok' => false,
                    'git_diff_check_passed' => true,
                    'ap_doc_updated' => true,
                    'uncovered_changed_paths' => [],
                ],
                docsApPath: $dir,
                requestedApNumber: 921,
            );

            $failedIds = array_column(data_get($payload, 'completion_checklist.failed_checks'), 'id');

            $this->assertSame('incomplete', $payload['status']);
            $this->assertSame('ready_for_new_ap_work', data_get($payload, 'session_gate.status'));
            $this->assertSame('valid_shape', data_get($payload, 'validation_evidence_shape.status'));
            $this->assertSame('incomplete', data_get($payload, 'completion_checklist.status'));
            $this->assertContains('focused_tests_passed', $failedIds);
            $this->assertContains('architecture_validate_ok', $failedIds);
            $this->assertSame('finish_failed_checks_before_claiming_ap_complete', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_completion_report_blocks_invalid_validation_evidence_shape_before_completion_status(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-completion-report-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-930-clean.md', "# AP-930 Clean\n");

            $payload = app(AtlasApAgentCompletionReport::class)->report(
                workTitle: 'Invalid evidence completion report',
                intendedPaths: ['app/New/InvalidEvidenceCompletion.php'],
                validationEvidence: [
                    'code_or_doc_changes_scoped' => true,
                    'focused_tests_passed' => 'yes',
                    'docs_health_ok' => true,
                    'architecture_validate_ok' => true,
                    'git_diff_check_passed' => true,
                    'uncovered_changed_paths' => [],
                    'unexpected' => true,
                ],
                docsApPath: $dir,
                requestedApNumber: 931,
            );

            $reasons = array_column(data_get($payload, 'validation_evidence_shape.errors'), 'reason');

            $this->assertSame('blocked_by_validation_evidence_shape', $payload['status']);
            $this->assertSame('ready_for_new_ap_work', data_get($payload, 'session_gate.status'));
            $this->assertSame('invalid_shape', data_get($payload, 'validation_evidence_shape.status'));
            $this->assertContains('required_key_must_be_boolean', $reasons);
            $this->assertContains('missing_required_boolean_key', $reasons);
            $this->assertContains('unknown_validation_evidence_key', $reasons);
            $this->assertSame('repair_validation_evidence_shape_before_completion_report', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function passingEvidence(): array
    {
        return [
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => true,
            'docs_health_ok' => true,
            'architecture_validate_ok' => true,
            'git_diff_check_passed' => true,
            'ap_doc_updated' => true,
            'uncovered_changed_paths' => [],
            'commands' => [
                'php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentCompletionReportTest.php',
                'git diff --check',
            ],
            'notes' => ['fixture validation only'],
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
