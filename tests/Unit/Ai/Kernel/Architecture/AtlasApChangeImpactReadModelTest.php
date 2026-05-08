<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApChangeImpactReadModel;
use Tests\TestCase;

final class AtlasApChangeImpactReadModelTest extends TestCase
{
    public function test_current_change_impact_maps_related_path_to_documenting_ap(): void
    {
        $payload = app(AtlasApChangeImpactReadModel::class)->report([
            'app/Services/Ai/Kernel/Architecture/AtlasApDocumentationManifest.php',
        ]);

        $this->assertSame('atlas.ap_change_impact_read_model.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_change_impact', $payload['mode']);
        $this->assertSame('ap_change_impact_only_no_file_writes', $payload['authority']);
        $this->assertSame(1, $payload['changed_path_count']);
        $this->assertSame(1, $payload['covered_changed_path_count']);
        $this->assertSame(0, $payload['uncovered_changed_path_count']);
        $this->assertGreaterThanOrEqual(1, $payload['impacted_ap_count']);
        $this->assertContains('AP-193', array_column($payload['impacted_aps'], 'ap'));
        $this->assertSame('review_impacted_ap_docs_before_finishing_change', $payload['next_action']);
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.reads_git_status'));
        $this->assertTrue(data_get($payload, 'guardrails.requires_human_or_agent_to_update_docs_for_uncovered_paths'));
    }

    public function test_change_impact_reports_docs_changed_and_uncovered_paths(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-impact-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-020-alpha.md', implode("\n", [
                '---',
                'title: Alpha',
                'status: implemented',
                'owner: Team A',
                'line_limit: 80',
                'related_paths:',
                '  - app/Services/Ai/Kernel/Architecture/AtlasApChangeImpactReadModel.php',
                '---',
                '# AP-020 - Alpha',
                '',
            ]));

            $payload = app(AtlasApChangeImpactReadModel::class)->report([
                base_path('app/Services/Ai/Kernel/Architecture/AtlasApChangeImpactReadModel.php'),
                $dir.'/AP-020-alpha.md',
                'app/Foo/Uncovered.php',
            ], $dir);

            $this->assertSame('ok', $payload['status']);
            $this->assertSame(3, $payload['changed_path_count']);
            $this->assertSame(2, $payload['covered_changed_path_count']);
            $this->assertSame(1, $payload['uncovered_changed_path_count']);
            $this->assertSame(['app/Foo/Uncovered.php'], $payload['uncovered_changed_paths']);
            $this->assertSame(1, $payload['impacted_ap_count']);
            $this->assertSame('AP-20', data_get($payload, 'impacted_aps.0.ap'));
            $this->assertSame('Alpha', data_get($payload, 'impacted_aps.0.title'));
            $this->assertSame(2, count(data_get($payload, 'impacted_aps.0.matched_changed_paths')));
            $this->assertSame(1, $payload['docs_changed_count']);
            $this->assertSame('ap_doc_changed', data_get($payload, 'docs_changed.0.match_type'));
            $this->assertSame('review_uncovered_changed_paths_then_update_existing_ap_or_create_new_ap_via_ap191_ap192', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_change_impact_blocks_next_action_when_ap_governance_is_attention(): void
    {
        $dir = sys_get_temp_dir().'/atlas-ap-impact-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir.'/AP-030-first.md', "# AP-030 - First\n");
            file_put_contents($dir.'/AP-030-second.md', "# AP-030 - Second\n");

            $payload = app(AtlasApChangeImpactReadModel::class)->report([
                'app/Unknown.php',
            ], $dir);

            $this->assertSame('attention', $payload['status']);
            $this->assertSame('attention', data_get($payload, 'governance.status'));
            $this->assertSame('repair_ap_governance_before_reviewing_change_impact', $payload['next_action']);
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
