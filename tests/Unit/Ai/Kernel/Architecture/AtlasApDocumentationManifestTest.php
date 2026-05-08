<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApDocumentationManifest;
use Tests\TestCase;

final class AtlasApDocumentationManifestTest extends TestCase
{
    public function test_current_ap_manifest_is_read_only_and_governed(): void
    {
        $payload = app(AtlasApDocumentationManifest::class)->manifest();

        $this->assertSame('atlas.ap_documentation_manifest.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_manifest', $payload['mode']);
        $this->assertSame('ap_documentation_manifest_only_no_file_writes', $payload['authority']);
        $this->assertGreaterThanOrEqual(90, $payload['ap_count']);
        $this->assertGreaterThanOrEqual(1, $payload['docs_with_frontmatter_count']);
        $this->assertGreaterThanOrEqual(1, $payload['docs_with_related_paths_count']);
        $this->assertSame('ok', data_get($payload, 'governance.status'));
        $this->assertGreaterThanOrEqual(193, data_get($payload, 'governance.next_suggested_number'));
        $this->assertSame('use_manifest_to_pick_existing_ap_or_create_next_ap_via_ap191_ap192', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.normalizes_docs'));
        $this->assertTrue(data_get($payload, 'guardrails.safe_for_agent_context_loading'));
    }

    public function test_manifest_summarizes_temp_ap_docs_and_surfaces_governance_blockers(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-manifest-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-010-alpha.md', implode("\n", [
                '---',
                'title: Alpha',
                'status: implemented',
                'owner: Team A',
                'line_limit: 30',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApDocumentationManifest.php',
                '---',
                '# AP-010 - Alpha',
                '',
            ]));
            file_put_contents($dir.'/AP-011-beta.md', implode("\n", [
                '# AP-011 - Beta',
                '',
            ]));
            file_put_contents($dir.'/AP-011-duplicate.md', "# AP-011 - Duplicate\n");

            $payload = app(AtlasApDocumentationManifest::class)->manifest($dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame(3, $payload['ap_count']);
            $this->assertSame(1, $payload['docs_with_frontmatter_count']);
            $this->assertSame(1, $payload['docs_with_related_paths_count']);
            $this->assertSame(1, data_get($payload, 'status_counts.implemented'));
            $this->assertSame(2, data_get($payload, 'status_counts.undocumented_status'));
            $this->assertSame(1, data_get($payload, 'owner_counts.Team A'));
            $this->assertSame(2, data_get($payload, 'owner_counts.undocumented_owner'));
            $this->assertSame(10, data_get($payload, 'entries.0.number'));
            $this->assertSame(11, data_get($payload, 'entries.1.number'));
            $this->assertSame('Alpha', data_get($payload, 'entries.0.title'));
            $this->assertSame('AP-011 - Beta', data_get($payload, 'entries.1.title'));
            $this->assertSame('attention', data_get($payload, 'governance.status'));
            $this->assertSame('duplicate_ap_numbers', data_get($payload, 'governance.blockers.0.reason'));
            $this->assertSame('repair_ap_governance_before_using_manifest_for_new_work', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
