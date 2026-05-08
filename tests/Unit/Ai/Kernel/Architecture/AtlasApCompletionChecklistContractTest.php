<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApCompletionChecklistContract;
use Tests\TestCase;

final class AtlasApCompletionChecklistContractTest extends TestCase
{
    public function test_completion_checklist_marks_complete_with_explicit_evidence(): void
    {
        $payload = app(AtlasApCompletionChecklistContract::class)->evaluate([
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => true,
            'docs_health_ok' => true,
            'architecture_validate_ok' => true,
            'git_diff_check_passed' => true,
            'ap_doc_updated' => true,
            'uncovered_changed_paths' => [],
        ]);

        $this->assertSame('atlas.ap_completion_checklist_contract.v1', $payload['schema_version']);
        $this->assertSame('complete', $payload['status']);
        $this->assertSame('read_only_completion_checklist', $payload['mode']);
        $this->assertSame('ap_completion_checklist_only_no_command_execution', $payload['authority']);
        $this->assertSame(7, $payload['passed_count']);
        $this->assertSame(0, $payload['failed_count']);
        $this->assertSame([], $payload['failed_checks']);
        $this->assertSame('mark_ap_block_complete_and_report_validation_evidence', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_explicit_validation_evidence'));
    }

    public function test_completion_checklist_blocks_missing_or_false_evidence(): void
    {
        $payload = app(AtlasApCompletionChecklistContract::class)->evaluate([
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => false,
            'docs_health_ok' => true,
            'architecture_validate_ok' => false,
            'git_diff_check_passed' => true,
            'uncovered_changed_paths' => ['app/New/Undocumented.php'],
        ]);

        $failedIds = array_column($payload['failed_checks'], 'id');

        $this->assertSame('incomplete', $payload['status']);
        $this->assertContains('focused_tests_passed', $failedIds);
        $this->assertContains('architecture_validate_ok', $failedIds);
        $this->assertContains('ap_doc_updated_or_not_needed_with_reason', $failedIds);
        $this->assertContains('ap_change_impact_reviewed', $failedIds);
        $this->assertSame(4, $payload['failed_count']);
        $this->assertSame('finish_failed_checks_before_claiming_ap_complete', $payload['next_action']);
    }

    public function test_completion_checklist_allows_uncovered_paths_only_when_reviewed(): void
    {
        $payload = app(AtlasApCompletionChecklistContract::class)->evaluate([
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => true,
            'docs_health_ok' => true,
            'architecture_validate_ok' => true,
            'git_diff_check_passed' => true,
            'ap_doc_updated' => true,
            'uncovered_changed_paths' => ['app/New/Reviewed.php'],
            'uncovered_paths_reviewed' => true,
        ]);

        $impactCheck = collect($payload['checks'])
            ->firstWhere('id', 'ap_change_impact_reviewed');

        $this->assertSame('complete', $payload['status']);
        $this->assertSame('passed', $impactCheck['status']);
        $this->assertSame(1, $impactCheck['uncovered_changed_path_count']);
        $this->assertTrue($impactCheck['uncovered_paths_reviewed']);
    }
}
