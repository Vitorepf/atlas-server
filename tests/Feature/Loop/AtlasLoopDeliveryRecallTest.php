<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryRecallService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE B3 — provider-safe delivery recall: the recent CERTIFIED merged deliveries in the edited file's module
 * (paths only), so the weak engine matches what just landed nearby. Reads atlas_loop_proposals (no new write).
 */
final class AtlasLoopDeliveryRecallTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        AtlasLoopProposal::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    private function mergedDelivery(string $targetPath): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'recall',
            'config' => [],
            'max_seconds' => 60,
        ]);
        $p = AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'o',
            'target_path' => $targetPath,
            'diff_text' => '',
            'proposal_hash' => substr(hash('sha256', $targetPath), 0, 40),
            'quality' => [],
        ]);
        // merged_to_main is governed on the model; set it directly for the fixture.
        DB::table('atlas_loop_proposals')->where('id', $p->id)->update(['merged_to_main' => true, 'updated_at' => now()]);
    }

    public function test_recalls_module_neighbours_excluding_self_other_modules_and_deeper_subtrees(): void
    {
        config(['atlas.loop.brain_delivery_recall_enabled' => true]);
        $this->mergedDelivery('app/Foo/A.php');
        $this->mergedDelivery('app/Foo/B.php');
        $this->mergedDelivery('app/Other/C.php');     // different module
        $this->mergedDelivery('app/Foo/Deep/D.php');  // deeper subtree

        $recall = (new AtlasLoopDeliveryRecallService)->recall('app/Foo', 'app/Foo/A.php');

        $this->assertContains('app/Foo/B.php', $recall, 'a certified module neighbour is recalled');
        $this->assertNotContains('app/Foo/A.php', $recall, 'the edited file itself is excluded');
        $this->assertNotContains('app/Other/C.php', $recall, 'a different module is not recalled');
        $this->assertNotContains('app/Foo/Deep/D.php', $recall, 'a deeper subtree is not a direct neighbour');
    }

    public function test_off_is_byte_identical_empty_recall(): void
    {
        config(['atlas.loop.brain_delivery_recall_enabled' => false]);
        $this->mergedDelivery('app/Foo/A.php');
        $this->assertSame([], (new AtlasLoopDeliveryRecallService)->recall('app/Foo'), 'flag OFF => empty => byte-identical');
    }
}
