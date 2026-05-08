<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentSessionGate;
use Tests\TestCase;

final class AtlasApAgentSessionGateTest extends TestCase
{
    public function test_session_gate_allows_existing_ap_work_when_governance_is_clean(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-session-gate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-830-session-gate.md', implode("\n", [
                '---',
                'title: AP-830 Session Gate',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApAgentSessionGate.php',
                '---',
                '# AP-830 Session Gate',
                '',
            ]));

            $payload = app(AtlasApAgentSessionGate::class)->gate(
                workTitle: 'Improve session gate',
                intendedPaths: [
                    'app/Services/Ai/Kernel/Architecture/AtlasApAgentSessionGate.php',
                ],
                docsApPath: $dir,
            );

            $this->assertSame('atlas.ap_agent_session_gate.v1', $payload['schema_version']);
            $this->assertSame('read_only_session_gate', $payload['mode']);
            $this->assertSame('ap_agent_session_gate_only_no_file_writes', $payload['authority']);
            $this->assertSame('ready_for_existing_ap_work', $payload['status']);
            $this->assertSame('AP-830', $payload['resolved_target_ap']);
            $this->assertSame('ok', data_get($payload, 'repair_gate.status'));
            $this->assertSame(0, data_get($payload, 'repair_gate.proposal_count'));
            $this->assertSame('existing_ap_ready', data_get($payload, 'handoff.status'));
            $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
            $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
            $this->assertFalse(data_get($payload, 'guardrails.applies_repairs'));
            $this->assertTrue(data_get($payload, 'guardrails.requires_completion_checklist_after_work'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_session_gate_blocks_when_repair_proposals_exist(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-session-gate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-800-existing.md', implode("\n", [
                '---',
                'title: AP-800 Existing',
                'status: implemented',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/MissingSessionGateTarget.php',
                '---',
                '# AP-800 Existing',
                '',
            ]));

            $payload = app(AtlasApAgentSessionGate::class)->gate(
                workTitle: 'New blocked work',
                intendedPaths: ['app/New/BlockedWork.php'],
                docsApPath: $dir,
                requestedApNumber: 801,
            );

            $this->assertSame('blocked_by_documentation_repair', $payload['status']);
            $this->assertSame('attention', data_get($payload, 'repair_gate.status'));
            $this->assertSame(1, data_get($payload, 'repair_gate.proposal_count'));
            $this->assertSame(['missing_ap_related_paths'], data_get($payload, 'repair_gate.reasons'));
            $this->assertSame('review_repair_proposals_before_creating_or_editing_ap_docs', $payload['next_action']);
            $this->assertTrue(data_get($payload, 'guardrails.requires_human_review_for_repair_proposals'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_session_gate_blocks_invalid_handoff_inputs_after_clean_repair_gate(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-session-gate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-810-clean.md', implode("\n", [
                '---',
                'title: AP-810 Clean',
                'status: implemented',
                '---',
                '# AP-810 Clean',
                '',
            ]));

            $payload = app(AtlasApAgentSessionGate::class)->gate(
                workTitle: '',
                docsApPath: $dir,
                proposedSlug: 'Bad Slug',
            );

            $this->assertSame('blocked_by_handoff', $payload['status']);
            $this->assertSame('ok', data_get($payload, 'repair_gate.status'));
            $this->assertSame('blocked', data_get($payload, 'handoff.status'));
            $this->assertSame('repair_work_title_or_slug_before_ap_intake', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_session_gate_allows_new_ap_work_when_scope_is_clean_and_uncovered(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-session-gate-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-820-clean.md', "# AP-820 Clean\n");

            $payload = app(AtlasApAgentSessionGate::class)->gate(
                workTitle: 'New Session Gate Work',
                intendedPaths: ['app/New/SessionGateWork.php'],
                docsApPath: $dir,
                requestedApNumber: 821,
            );

            $this->assertSame('ready_for_new_ap_work', $payload['status']);
            $this->assertSame('new_ap_ready', data_get($payload, 'handoff.status'));
            $this->assertSame('ok', data_get($payload, 'repair_gate.status'));
            $this->assertNull($payload['resolved_target_ap']);
            $this->assertSame('create_or_update_ap_doc_via_ap192_then_execute_completion_checklist', $payload['next_action']);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
