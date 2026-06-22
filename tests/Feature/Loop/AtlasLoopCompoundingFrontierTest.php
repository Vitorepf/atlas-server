<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopStateOfAtlasReader;
use App\Services\Ai\AutonomousEvolution\Discovery\StateOfAtlas;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L2/L5 — MERGE CHANGES THE FRONTIER, not just the prompt. The picker reads the StateOfAtlas each cycle; a
 * capability MERGED in cycle n must appear in cycle n+1's SELECTABLE frontier so the loop builds HIGHER on it
 * instead of re-discovering an exhausted scope (the ARBOR feedback was advisory-prompt-only). Proven: a merged
 * gain expands deliveredCapabilities on the next read; flag-OFF keeps the frontier empty (byte-identical).
 */
final class AtlasLoopCompoundingFrontierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_proposals')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'compounding frontier',
            'config' => [],
            'max_seconds' => 60,
        ]);
    }

    private function read(): StateOfAtlas
    {
        return app(AtlasLoopStateOfAtlasReader::class)->read(base_path());
    }

    private function mergeGain(string $campaignId, string $targetPath): void
    {
        // Only the governed auto-merge may stamp merged_to_main=true (the model's hard invariant); emulate it.
        AtlasLoopProposal::$governedMergeInProgress = true;
        AtlasLoopProposal::create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'delivered gain',
            'target_path' => $targetPath,
            'diff_text' => '',
            'proposal_hash' => substr(hash('sha256', $targetPath), 0, 40),
            'merged_to_main' => true,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;
    }

    public function test_a_merged_gain_expands_the_selectable_frontier_next_cycle(): void
    {
        config(['atlas.loop.compounding_frontier_enabled' => true]);
        $campaign = $this->campaign();
        $gain = 'app/Services/Ai/AutonomousEvolution/SomeDeliveredCapability.php';

        // cycle n: nothing merged yet => the frontier is empty.
        $before = $this->read();
        $this->assertSame([], $before->deliveredCapabilities(), 'no gains yet => empty frontier');
        $this->assertFalse($before->isDeliveredFoundation($gain));

        // cycle n DELIVERS the gain (a governed merge to main).
        $this->mergeGain((string) $campaign->id, $gain);

        // cycle n+1: the frontier EXPANDED to contain the gain — the loop now perceives it as selectable.
        $after = $this->read();
        $this->assertContains($gain, $after->deliveredCapabilities(), 'cycle n+1 PERCEIVES cycle n gain in the selectable frontier');
        $this->assertTrue($after->isDeliveredFoundation($gain), 'the loop can now build HIGHER on the delivered capability');
        $this->assertGreaterThan(count($before->deliveredCapabilities()), count($after->deliveredCapabilities()), 'the frontier STRICTLY expanded with the gain');
    }

    public function test_flag_off_frontier_stays_empty_byte_identical(): void
    {
        config(['atlas.loop.compounding_frontier_enabled' => false]);
        $campaign = $this->campaign();
        $this->mergeGain((string) $campaign->id, 'app/Services/Ai/AutonomousEvolution/SomeDeliveredCapability.php');

        $this->assertSame([], $this->read()->deliveredCapabilities(), 'flag OFF => frontier empty even after a merge => byte-identical');
    }
}
