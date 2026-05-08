<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentWorkflowIntegratorHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentWorkflowIntegratorHandoffPacketTest extends TestCase
{
    public function test_integrator_handoff_is_ready_only_after_human_acceptance(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-handoff-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-970-integrator-handoff.md', implode("\n", [
                '---',
                'title: AP-970 Integrator Handoff',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacket.php',
                '---',
                '# AP-970 Integrator Handoff',
                '',
            ]));

            $payload = app(AtlasApAgentWorkflowIntegratorHandoffPacket::class)->handoff(
                workTitle: 'Accept integrator handoff packet',
                intendedPaths: ['app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacket.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'accept',
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_workflow_integrator_handoff_packet.v1', $payload['schema_version']);
            $this->assertSame('ready_for_integrator_review', $payload['status']);
            $this->assertSame('read_only_integrator_handoff_packet', $payload['mode']);
            $this->assertSame('ap_agent_workflow_integrator_handoff_only_no_execution', $payload['authority']);
            $this->assertSame('accepted_by_human', data_get($payload, 'human_decision_summary.status'));
            $this->assertSame('accept', data_get($payload, 'human_decision_summary.decision'));
            $this->assertSame(1, data_get($payload, 'integration_scope.path_count'));
            $this->assertSame('integrator_reviews_accepted_work_without_auto_merge', $payload['next_action']);
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.auto_merges_work'));
            $this->assertFalse(data_get($payload, 'guardrails.approves_without_human_acceptance'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_integrator_handoff_routes_human_change_request_back_to_agent(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-handoff-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-980-integrator-handoff.md', "# AP-980 Integrator Handoff\n");

            $payload = app(AtlasApAgentWorkflowIntegratorHandoffPacket::class)->handoff(
                workTitle: 'Repair integrator handoff packet',
                intendedPaths: ['app/New/RepairIntegratorHandoff.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'request_changes',
                reason: 'Evidence must include the final docs-health output.',
                docsApPath: $dir,
                requestedApNumber: 981,
            );

            $this->assertSame('return_to_agent_for_repair', $payload['status']);
            $this->assertSame('changes_requested_by_human', data_get($payload, 'human_decision_summary.status'));
            $this->assertSame('Evidence must include the final docs-health output.', data_get($payload, 'human_decision_summary.reason'));
            $this->assertSame('agent_repairs_work_from_human_reason_before_new_review', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_integrator_handoff_stops_when_human_rejects_scope(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-handoff-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-990-integrator-handoff.md', "# AP-990 Integrator Handoff\n");

            $payload = app(AtlasApAgentWorkflowIntegratorHandoffPacket::class)->handoff(
                workTitle: 'Rejected integrator handoff packet',
                intendedPaths: ['app/New/RejectedIntegratorHandoff.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-205', 'AP-203'],
                decision: 'reject',
                reason: 'Scope belongs to another AP family.',
                docsApPath: $dir,
                requestedApNumber: 991,
            );

            $this->assertSame('stopped_by_human_rejection', $payload['status']);
            $this->assertSame('rejected_by_human', data_get($payload, 'human_decision_summary.status'));
            $this->assertSame('do_not_continue_until_human_reopens_scope', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_integrator_handoff_blocks_when_human_decision_contract_is_blocked(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-integrator-handoff-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-995-integrator-handoff.md', "# AP-995 Integrator Handoff\n");

            $payload = app(AtlasApAgentWorkflowIntegratorHandoffPacket::class)->handoff(
                workTitle: 'Blocked integrator handoff packet',
                intendedPaths: ['app/New/BlockedIntegratorHandoff.php'],
                validationEvidence: $this->passingEvidence(),
                traceSteps: ['AP-200', 'AP-202', 'AP-203'],
                decision: 'accept',
                docsApPath: $dir,
                requestedApNumber: 996,
            );

            $this->assertSame('blocked_by_human_decision_contract', $payload['status']);
            $this->assertSame('blocked_accept_requires_ready_review_packet', data_get($payload, 'human_decision_summary.status'));
            $this->assertSame('repair_human_decision_contract_before_integrator_handoff', $payload['next_action']);
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
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowIntegratorHandoffPacketTest.php'],
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
