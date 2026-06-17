<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopConstraintsBlockAssembler;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHypothesisTreeProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIdeaTreeAccessor;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopInsightBackpropService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ARBOR-GRAFT TIER 0.1 — END-TO-END (flag-ON) proof that the idea-tree COMPOUNDS: materialize competing
 * sibling hypotheses, prune a failed one through the loop-back, backprop its lesson up the path-to-root,
 * and confirm the constraints-block (CB1 — what the next IDEATE reads) now carries that pruned lesson.
 * Uses the loop's own focused-migration setUp pattern (no RefreshDatabase: the full suite has a Postgres-
 * only CREATE EXTENSION that sqlite :memory: rejects).
 */
final class AtlasLoopHypothesisTreeCompoundingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        if (! Schema::hasColumn('atlas_loop_targets', 'parent_target_id')) {
            (require base_path('database/migrations/2026_06_17_000400_add_idea_tree_columns_to_atlas_loop_targets.php'))->up();
        }
        config()->set('atlas.loop.idea_tree_enabled', true);
        config()->set('atlas.loop.insight_backprop_enabled', true);
        config()->set('atlas.loop.constraints_block_enabled', true);
    }

    private function makeCampaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'tree compounding proof',
            'status' => 'running',
            'config' => [],
        ]);
    }

    private function makeRootTarget(AtlasLoopCampaign $c): AtlasLoopTarget
    {
        return AtlasLoopTarget::query()->create([
            'campaign_id' => $c->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'app/Payment/Refund.php',
            'target_key' => hash('sha256', $c->id.'|app/Payment/Refund.php'),
            'content_hash' => 'h0',
            'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            'score' => 0.9,
            'signals' => [],
            'attempts' => 0,
            'max_attempts' => 3,
            'depth' => 0,
        ]);
    }

    public function test_materialize_then_prune_then_backprop_feeds_the_constraints_block(): void
    {
        $campaign = $this->makeCampaign();
        $root = $this->makeRootTarget($campaign);

        // (b) producer materializes K competing sibling hypotheses under the root.
        $producer = new AtlasLoopHypothesisTreeProducer(new AtlasLoopInsightBackpropService);
        $childIds = $producer->materialize($root, ['verifier-guided beam over candidates', 'iterative conditioned retrieval']);
        $this->assertCount(2, $childIds);

        foreach (AtlasLoopTarget::query()->whereIn('id', $childIds)->get() as $child) {
            $this->assertSame($root->id, $child->parent_target_id);
            $this->assertSame(1, (int) $child->depth);
            $this->assertSame(AtlasLoopIdeaTreeAccessor::STATUS_PENDING, $child->tree_status);
        }

        // (c) one sibling fails un-grindably -> loop-back prunes it + backprops the lesson up to the root.
        $loopBack = new AtlasLoopBackService(
            new AtlasLoopTargetRepository,
            new AtlasLoopIdeaTreeAccessor,
            new AtlasLoopInsightBackpropService,
        );
        $loserId = $childIds[0];
        $loopBack->reflect($campaign->id, ['target_id' => $loserId, 'status' => 'no_winner', 'reason' => 'not_red']);

        $loser = AtlasLoopTarget::query()->find($loserId);
        $this->assertSame(AtlasLoopIdeaTreeAccessor::STATUS_PRUNED, $loser->tree_status, 'failed sibling is pruned');

        // the pruned lesson propagated up to the root's node_insight (compounding).
        $root->refresh();
        $rootDistilled = (array) (($root->node_insight ?? [])['distilled'] ?? []);
        $this->assertNotEmpty($rootDistilled, 'root received a distilled lesson via backprop');

        // CB1: the constraints-block the NEXT IDEATE reads now carries the pruned lesson + tree shape.
        $block = (new AtlasLoopConstraintsBlockAssembler)->build($campaign->id);
        $this->assertStringContainsString('PRUNED LESSONS', $block);
        $this->assertStringContainsString('TREE SHAPE', $block);
        $this->assertStringContainsString('pruned', $block);
    }

    public function test_flag_off_is_byte_identical_no_tree(): void
    {
        config()->set('atlas.loop.idea_tree_enabled', false);
        $campaign = $this->makeCampaign();
        $root = $this->makeRootTarget($campaign);

        $producer = new AtlasLoopHypothesisTreeProducer(new AtlasLoopInsightBackpropService);
        $this->assertSame([], $producer->materialize($root, ['a', 'b']), 'flag OFF => no children (byte-identical)');
    }
}
