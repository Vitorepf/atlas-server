<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowHumanReviewPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowHumanReviewPacketTest extends TestCase
{
    public function test_human_review_packet_is_ready_when_execution_receipt_is_accepted(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-human-review-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-human-review.md', implode("\n", [
                '---',
                'title: AP-970 Human Review',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanReviewPacket.php',
                '---',
                '# AP-970 Human Review',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowHumanReviewPacket::class)->packet(
                workTitle: 'Improve human review packet',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanReviewPacket.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_human_review_packet.v1', $payload['schema_version']);
            $this->assertSame('ready_for_human_acceptance', $payload['status']);
            $this->assertSame('read_only_human_review_packet', $payload['mode']);
            $this->assertSame('ap_agent_workflow_human_review_packet_only_no_execution', $payload['authority']);
            $this->assertSame('human_can_accept_or_request_extra_review', $payload['review_decision']);
            $this->assertSame('accepted', data_get($payload, 'review_summary.execution_receipt_status'));
            $this->assertSame('valid_trace', data_get($payload, 'review_summary.trace_status'));
            $this->assertSame('complete', data_get($payload, 'review_summary.completion_status'));
            $this->assertSame('present_execution_receipt_summary_to_human_reviewer', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.overrides_execution_receipt'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_human_review_packet_routes_trace_blockers_to_trace_repair_review(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-human-review-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-human-review.md', "# AP-980 Human Review\n");

            $payload = app(AtlasApAgentWorkflowHumanReviewPacket::class)->packet(
                workTitle: 'Trace blocked human review',
                intendedPaths: ['app/New/TraceBlockedHumanReview.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('requires_human_repair_review', $payload['status']);
            $this->assertSame('human_should_review_workflow_trace_repair', $payload['review_decision']);
            $this->assertSame('blocked_by_trace_audit', data_get($payload, 'review_summary.execution_receipt_status'));
            $this->assertSame('invalid_trace', data_get($payload, 'review_summary.trace_status'));
            $this->assertSame('repair_agent_workflow_trace_before_claiming_ordered_execution', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_human_review_packet_routes_completion_blockers_to_completion_repair_review(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-human-review-packet-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-human-review.md', "# AP-990 Human Review\n");
            $evidence = $this->passingEvidence();
            $evidence['docs_health_ok'] = false;

            $payload = app(AtlasApAgentWorkflowHumanReviewPacket::class)->packet(
                workTitle: 'Completion blocked human review',
                intendedPaths: ['app/New/CompletionBlockedHumanReview.php'],
                validationEvidence: $evidence,
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('requires_human_repair_review', $payload['status']);
            $this->assertSame('human_should_review_completion_evidence_repair', $payload['review_decision']);
            $this->assertSame('blocked_by_completion_report', data_get($payload, 'review_summary.execution_receipt_status'));
            $this->assertSame('incomplete', data_get($payload, 'review_summary.completion_status'));
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanReviewPacketTest.php'],
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
