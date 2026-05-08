<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentHandoffPacket;
use Tests\TestCase;

final class AtlasApAgentHandoffPacketTest extends TestCase
{
    public function test_handoff_packet_resolves_existing_ap_from_intended_paths(): void
    {
        $payload = app(AtlasApAgentHandoffPacket::class)->packet(
            workTitle: 'Improve AP manifest',
            intendedPaths: [
                'app/Services/Ai/Kernel/Architecture/AtlasApDocumentationManifest.php',
            ],
        );

        $this->assertSame('atlas.ap_agent_handoff_packet.v1', $payload['schema_version']);
        $this->assertSame('existing_ap_ready', $payload['status']);
        $this->assertSame('read_only_agent_handoff_packet', $payload['mode']);
        $this->assertSame('ap_agent_handoff_only_no_file_writes', $payload['authority']);
        $this->assertSame('AP-193', $payload['resolved_target_ap']);
        $this->assertSame('existing_ap_review_required', data_get($payload, 'intake.status'));
        $this->assertSame('ready', data_get($payload, 'target_context.status'));
        $this->assertSame('AP-193', data_get($payload, 'target_context.target_ap.ap'));
        $this->assertSame('incomplete', data_get($payload, 'completion_starting_point.status'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'completion_starting_point.failed_count'));
        $this->assertSame('review_target_context_then_execute_scoped_change_and_completion_checklist', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_agent_to_execute_and_report_completion_checklist'));
    }

    public function test_handoff_packet_allows_new_ap_when_scope_is_uncovered(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-handoff-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-060-existing.md', "# AP-060 - Existing\n");

            $payload = app(AtlasApAgentHandoffPacket::class)->packet(
                workTitle: 'New Handoff Work',
                intendedPaths: ['app/New/HandoffWork.php'],
                docsApPath: $dir,
                requestedApNumber: 61,
            );

            $this->assertSame('new_ap_ready', $payload['status']);
            $this->assertNull($payload['resolved_target_ap']);
            $this->assertSame('new_ap_allowed', data_get($payload, 'intake.status'));
            $this->assertSame('docs/ap/AP-061-new-handoff-work.md', data_get($payload, 'intake.recommendation.recommended_doc_path'));
            $this->assertNull($payload['target_context']);
            $this->assertSame('create_or_update_ap_doc_via_ap192_then_execute_completion_checklist', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_handoff_packet_blocks_when_intake_is_invalid(): void
    {
        $payload = app(AtlasApAgentHandoffPacket::class)->packet(
            workTitle: '',
            proposedSlug: 'Bad Slug',
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'intake.status'));
        $this->assertNull($payload['target_context']);
        $this->assertSame('repair_work_title_or_slug_before_ap_intake', $payload['next_action']);
    }

    public function test_handoff_packet_uses_explicit_target_ap_when_provided(): void
    {
        $payload = app(AtlasApAgentHandoffPacket::class)->packet(
            workTitle: 'Review completion checklist',
            targetAp: 'ap-completion-checklist-contract',
        );

        $this->assertSame('existing_ap_ready', $payload['status']);
        $this->assertSame('ap-completion-checklist-contract', $payload['resolved_target_ap']);
        $this->assertSame('AP-196', data_get($payload, 'target_context.target_ap.ap'));
        $this->assertSame('ready', data_get($payload, 'target_context.status'));
    }
}
