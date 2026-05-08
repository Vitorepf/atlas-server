<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApAgentContextPack;
use Tests\TestCase;

final class AtlasApAgentContextPackTest extends TestCase
{
    public function test_current_context_pack_loads_target_ap_by_slug(): void
    {
        $payload = app(AtlasApAgentContextPack::class)->pack('ap-documentation-manifest');

        $this->assertSame('atlas.ap_agent_context_pack.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('read_only_agent_context_pack', $payload['mode']);
        $this->assertSame('ap_agent_context_pack_only_no_file_writes', $payload['authority']);
        $this->assertSame('AP-193', data_get($payload, 'target_ap.ap'));
        $this->assertSame('ap-documentation-manifest', data_get($payload, 'target_ap.slug'));
        $this->assertContains('app/Services/Ai/Kernel/Architecture/AtlasApDocumentationManifest.php', data_get($payload, 'target_ap.related_paths'));
        $this->assertSame(0, data_get($payload, 'dependency_context.target_missing_reference_count'));
        $this->assertContains('AP-193', $payload['recommended_review_order']);
        $this->assertSame('ok', data_get($payload, 'governance.status'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.loads_full_doc_body'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_agent_to_review_target_doc_before_editing'));
    }

    public function test_context_pack_loads_target_ap_by_number_and_surfaces_dependencies(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-context-pack-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-020-base.md', "# AP-020 - Base\n");
            file_put_contents($dir.'/AP-021-child.md', implode("\n", [
                '---',
                'title: Child',
                'status: implemented',
                'depends_on:',
                '  - AP-020',
                '---',
                '# AP-021 - Child',
                '',
            ]));
            file_put_contents($dir.'/AP-022-reader.md', implode("\n", [
                '# AP-022 - Reader',
                '',
                'This AP consumes AP-021.',
                '',
            ]));

            $payload = app(AtlasApAgentContextPack::class)->pack(21, $dir);

            $this->assertSame('ready', $payload['status']);
            $this->assertSame('AP-21', data_get($payload, 'target_ap.ap'));
            $this->assertSame(['AP-20'], data_get($payload, 'dependency_context.dependencies'));
            $this->assertSame(['AP-22'], data_get($payload, 'dependency_context.dependents'));
            $this->assertSame(['AP-20', 'AP-21', 'AP-22'], $payload['recommended_review_order']);
            $this->assertSame('review_target_ap_and_dependents_before_editing', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_context_pack_blocks_unknown_ap(): void
    {
        $payload = app(AtlasApAgentContextPack::class)->pack('missing-ap');

        $this->assertSame('blocked', $payload['status']);
        $this->assertNull($payload['target_ap']);
        $this->assertSame('requested_ap_not_found', $payload['reason']);
        $this->assertSame('use_ap_manifest_or_creation_decision_before_editing', $payload['next_action']);
    }

    public function test_context_pack_attention_when_dependency_map_has_missing_reference(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-context-pack-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-030-lonely.md', implode("\n", [
                '# AP-030 - Lonely',
                '',
                'This AP references AP-999.',
                '',
            ]));

            $payload = app(AtlasApAgentContextPack::class)->pack('AP-30', $dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame('AP-30', data_get($payload, 'target_ap.ap'));
            $this->assertSame(1, data_get($payload, 'dependency_context.missing_reference_count'));
            $this->assertSame('repair_ap_governance_or_dependency_map_before_editing_target_ap', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
