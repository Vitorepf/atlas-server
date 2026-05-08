<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowHumanDecisionContract;
use Tests\TestCase;

final class AtlasApAgentWorkflowHumanDecisionContractTest extends TestCase
{
    public function test_human_decision_accepts_ready_review_packet_without_execution(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-human-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-human-decision.md', implode("\n", [
                '---',
                'title: AP-970 Human Decision',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContract.php',
                '---',
                '# AP-970 Human Decision',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowHumanDecisionContract::class)->decide(
                workTitle: 'Accept human decision contract',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContract.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_human_decision_contract.v1', $payload['schema_version']);
            $this->assertSame('accepted_by_human', $payload['status']);
            $this->assertSame('read_only_human_decision_contract', $payload['mode']);
            $this->assertSame('ap_agent_workflow_human_decision_only_no_execution', $payload['authority']);
            $this->assertSame('accept', data_get($payload, 'decision.value'));
            $this->assertFalse(data_get($payload, 'decision.reason_required'));
            $this->assertSame('ready_for_human_acceptance', data_get($payload, 'review_packet_summary.status'));
            $this->assertSame('accepted', data_get($payload, 'review_packet_summary.execution_receipt_status'));
            $this->assertSame('handoff_human_acceptance_to_integrator_without_auto_merge', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.auto_merges_work'));
            $this->assertFalse(data_get($payload, 'guardrails.persists_decision'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_human_decision_blocks_accept_when_review_packet_requires_repair(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-human-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-human-decision.md', "# AP-980 Human Decision\n");

            $payload = app(AtlasApAgentWorkflowHumanDecisionContract::class)->decide(
                workTitle: 'Blocked accept human decision',
                intendedPaths: ['app/New/BlockedAcceptHumanDecision.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('blocked_accept_requires_ready_review_packet', $payload['status']);
            $this->assertSame('requires_human_repair_review', data_get($payload, 'review_packet_summary.status'));
            $this->assertSame('blocked_by_trace_audit', data_get($payload, 'review_packet_summary.execution_receipt_status'));
            $this->assertSame('repair_review_packet_before_human_acceptance', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_human_decision_requires_reason_for_changes_or_rejection(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-human-decision-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-human-decision.md', "# AP-990 Human Decision\n");

            $missingReason = app(AtlasApAgentWorkflowHumanDecisionContract::class)->decide(
                workTitle: 'Missing reason human decision',
                intendedPaths: ['app/New/MissingReasonHumanDecision.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'request_changes',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $withReason = app(AtlasApAgentWorkflowHumanDecisionContract::class)->decide(
                workTitle: 'Request changes human decision',
                intendedPaths: ['app/New/RequestChangesHumanDecision.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'request_changes',
                reason: 'Docs evidence needs one more command output.',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('blocked_missing_human_reason', $missingReason['status']);
            $this->assertSame('provide_human_reason_before_recording_decision', $missingReason['next_action']);
            $this->assertSame('changes_requested_by_human', $withReason['status']);
            $this->assertSame('Docs evidence needs one more command output.', data_get($withReason, 'decision.reason'));
            $this->assertSame('return_review_notes_to_agent_for_repair', $withReason['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_human_decision_blocks_unknown_decision_value(): void
    {
        $payload = app(AtlasApAgentWorkflowHumanDecisionContract::class)->decide(
            workTitle: 'Unknown human decision',
            intendedPaths: ['app/New/UnknownHumanDecision.php'],
            validationEvidence: $this->passingEvidence(),
            traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
            decision: 'auto_merge',
            requestedApNumber: 210,
        );

        $this->assertSame('blocked_invalid_human_decision', $payload['status']);
        $this->assertSame(['accept', 'request_changes', 'reject'], data_get($payload, 'decision.allowed_values'));
        $this->assertSame('choose_allowed_human_decision_value', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowHumanDecisionContractTest.php'],
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
