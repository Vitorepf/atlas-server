<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowExecutionReceipt;
use Tests\TestCase;

final class AtlasApAgentWorkflowExecutionReceiptTest extends TestCase
{
    public function test_execution_receipt_is_accepted_when_trace_and_completion_are_valid(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-execution-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-940-execution-receipt.md', implode("\n", [
                '---',
                'title: AP-940 Execution Receipt',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceipt.php',
                '---',
                '# AP-940 Execution Receipt',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowExecutionReceipt::class)->receipt(
                workTitle: 'Improve execution receipt',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceipt.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_execution_receipt.v1', $payload['schema_version']);
            $this->assertSame('accepted', $payload['status']);
            $this->assertSame('read_only_execution_receipt', $payload['mode']);
            $this->assertSame('ap_agent_workflow_execution_receipt_only_no_execution', $payload['authority']);
            $this->assertSame('AP-940', $payload['resolved_target_ap']);
            $this->assertSame('valid_trace', data_get($payload, 'trace_audit.status'));
            $this->assertSame('complete', data_get($payload, 'completion_report.status'));
            $this->assertSame('accept_execution_receipt_for_human_review_summary', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.replaces_evidence_ledger'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_receipt_blocks_invalid_trace_even_when_completion_passes(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-execution-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-950-execution-receipt.md', "# AP-950 Execution Receipt\n");

            $payload = app(AtlasApAgentWorkflowExecutionReceipt::class)->receipt(
                workTitle: 'Invalid trace receipt',
                intendedPaths: ['app/New/InvalidTraceReceipt.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                docsApPath: $dir,
                requestedApNumber: 951,
            );

            $this->assertSame('blocked_by_trace_audit', $payload['status']);
            $this->assertSame('invalid_trace', data_get($payload, 'trace_audit.status'));
            $this->assertSame(1, data_get($payload, 'trace_audit.blocked_transition_count'));
            $this->assertSame('complete', data_get($payload, 'completion_report.status'));
            $this->assertSame('repair_agent_workflow_trace_before_claiming_ordered_execution', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_execution_receipt_blocks_incomplete_completion_even_when_trace_is_valid(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-execution-receipt-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-960-execution-receipt.md', "# AP-960 Execution Receipt\n");

            $evidence = $this->passingEvidence();
            $evidence['focused_tests_passed'] = false;

            $payload = app(AtlasApAgentWorkflowExecutionReceipt::class)->receipt(
                workTitle: 'Incomplete completion receipt',
                intendedPaths: ['app/New/IncompleteCompletionReceipt.php'],
                validationEvidence: $evidence,
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                docsApPath: $dir,
                requestedApNumber: 961,
            );

            $this->assertSame('blocked_by_completion_report', $payload['status']);
            $this->assertSame('valid_trace', data_get($payload, 'trace_audit.status'));
            $this->assertSame('incomplete', data_get($payload, 'completion_report.status'));
            $this->assertSame('finish_failed_checks_before_claiming_ap_complete', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceiptTest.php'],
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
