<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHypothesisTreeProducer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIdeaTreeAccessor;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopInsightBackpropService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ARBOR-GRAFT #2 — failure-driven SUPPLY. A genuine metric MISS (not an un-grindable quarantine) fans out
 * N orthogonal ALTERNATIVE-direction siblings under the same target, so the tree gains competing work
 * exactly where it got stuck (work-supply is the loop's #1 gargalo). The siblings re-enter as CANDIDATEs
 * (the SAME admissibility + RED + cert gates — never a shortcut to a proposal).
 *
 * Floor-safe: two-flag AND (failure_supply_enabled + idea_tree_enabled), depth-clamped, fail-open.
 * Uses the loop's focused-migration setUp (no RefreshDatabase: the full suite has a Postgres-only extension).
 */
final class AtlasLoopFailureSupplyTest extends TestCase
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
    }

    private function campaign(): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'failure supply proof',
            'status' => 'running',
            'config' => [],
        ]);
    }

    private function target(AtlasLoopCampaign $c, int $depth = 0): AtlasLoopTarget
    {
        return AtlasLoopTarget::query()->create([
            'campaign_id' => $c->id,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => 'app/Payment/Refund.php',
            'target_key' => hash('sha256', $c->id.'|app/Payment/Refund.php|d'.$depth),
            'content_hash' => 'h0',
            'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            'score' => 0.9,
            'novelty_score' => 1.0,
            'signals' => [],
            'attempts' => 0,
            'max_attempts' => 3,
            'depth' => $depth,
        ]);
    }

    private function backService(): AtlasLoopBackService
    {
        return new AtlasLoopBackService(
            new AtlasLoopTargetRepository,
            new AtlasLoopIdeaTreeAccessor,
            new AtlasLoopInsightBackpropService,
            new AtlasLoopHypothesisTreeProducer(new AtlasLoopInsightBackpropService),
        );
    }

    /** Count tree-node children spawned under a parent (the failure-supply siblings). */
    private function children(string $parentId): \Illuminate\Support\Collection
    {
        return AtlasLoopTarget::query()->where('parent_target_id', $parentId)->get();
    }

    public function test_off_is_byte_identical_no_siblings(): void
    {
        config()->set('atlas.loop.failure_supply_enabled', false);
        config()->set('atlas.loop.idea_tree_enabled', true); // even with the substrate on, the feature flag gates
        $c = $this->campaign();
        $t = $this->target($c);

        $out = $this->backService()->reflect($c->id, ['target_id' => $t->id, 'status' => 'no_winner', 'reason' => 'metric_miss_refund_drift']);

        $this->assertSame(1, $out['requeued'], 'the same-direction requeue is unchanged');
        $this->assertCount(0, $this->children($t->id), 'OFF => no alternative-direction siblings');
    }

    public function test_on_fans_out_alternative_direction_siblings_as_candidates(): void
    {
        config()->set('atlas.loop.failure_supply_enabled', true);
        config()->set('atlas.loop.idea_tree_enabled', true);
        config()->set('atlas.loop.failure_supply_frames', 3);
        $c = $this->campaign();
        $t = $this->target($c);

        $out = $this->backService()->reflect($c->id, ['target_id' => $t->id, 'status' => 'no_winner', 'reason' => 'metric_miss_refund_drift']);

        $this->assertSame(1, $out['requeued'], 'the original direction still requeues (supply is ADDITIVE)');
        $kids = $this->children($t->id);
        $this->assertCount(3, $kids, 'a metric miss fans out 3 orthogonal alternative-direction siblings');
        foreach ($kids as $kid) {
            // FLOOR: siblings re-enter the normal gates as CANDIDATEs — never a shortcut to a proposal.
            $this->assertSame(AtlasLoopTarget::STATUS_CANDIDATE, $kid->status);
            $this->assertSame(AtlasLoopIdeaTreeAccessor::STATUS_PENDING, $kid->tree_status);
            $this->assertSame(1, (int) $kid->depth, 'siblings sit one level below the failed target');
            $this->assertSame($t->id, $kid->parent_target_id);
        }
    }

    public function test_idempotent_across_repeated_misses(): void
    {
        config()->set('atlas.loop.failure_supply_enabled', true);
        config()->set('atlas.loop.idea_tree_enabled', true);
        config()->set('atlas.loop.failure_supply_frames', 3);
        $c = $this->campaign();
        $t = $this->target($c);

        // two consecutive misses with the SAME (objective, reason) must not duplicate siblings.
        $this->backService()->reflect($c->id, ['target_id' => $t->id, 'status' => 'no_winner', 'reason' => 'metric_miss_refund_drift']);
        $t->refresh();
        $this->backService()->reflect($c->id, ['target_id' => $t->id, 'status' => 'no_winner', 'reason' => 'metric_miss_refund_drift']);

        $this->assertCount(3, $this->children($t->id), 'content-addressed siblings de-duplicate across misses');
    }

    public function test_depth_clamp_blocks_runaway_fanout(): void
    {
        config()->set('atlas.loop.failure_supply_enabled', true);
        config()->set('atlas.loop.idea_tree_enabled', true);
        config()->set('atlas.loop.failure_supply_max_depth', 1);
        $c = $this->campaign();
        $deep = $this->target($c, depth: 1); // already at the clamp boundary

        $this->backService()->reflect($c->id, ['target_id' => $deep->id, 'status' => 'no_winner', 'reason' => 'metric_miss_refund_drift']);

        $this->assertCount(0, $this->children($deep->id), 'a miss at/below the depth clamp does not fan out');
    }

    public function test_quarantine_reason_does_not_fan_out(): void
    {
        config()->set('atlas.loop.failure_supply_enabled', true);
        config()->set('atlas.loop.idea_tree_enabled', true);
        $c = $this->campaign();
        $t = $this->target($c);

        // 'not_red' is an un-grindable QUARANTINE reason — it never reaches the metric-miss requeue branch.
        $out = $this->backService()->reflect($c->id, ['target_id' => $t->id, 'status' => 'no_winner', 'reason' => 'not_red']);

        $this->assertSame(1, $out['quarantined']);
        $this->assertCount(0, $this->children($t->id), 'a quarantine is a dead end, not a pivot to alternatives');
    }

    public function test_frames_anchor_to_stamped_last_objective_not_the_file_path(): void
    {
        // ARBOR-GRAFT #4 payoff: the QueueRefiller stamps signals['last_objective'] at enqueue; a later
        // miss must frame its alternatives around that REAL objective rather than the bare file path.
        config()->set('atlas.loop.failure_supply_enabled', true);
        config()->set('atlas.loop.idea_tree_enabled', true);
        config()->set('atlas.loop.failure_supply_frames', 2);
        $c = $this->campaign();
        $t = $this->target($c);
        $t->forceFill(['signals' => ['last_objective' => 'reduce refund rounding drift to zero']])->save();

        $this->backService()->reflect($c->id, ['target_id' => $t->id, 'status' => 'no_winner', 'reason' => 'metric_miss']);

        $kids = $this->children($t->id);
        $this->assertGreaterThanOrEqual(1, $kids->count());
        $anchored = $kids->first(fn ($k) => str_contains((string) ((is_array($k->hypothesis) ? $k->hypothesis : [])['text'] ?? ''), 'reduce refund rounding drift to zero'));
        $this->assertNotNull($anchored, 'failure-supply frames anchor to the stamped last_objective, not the file path');
    }

    public function test_container_binding_wires_the_tree_producer(): void
    {
        $svc = app(AtlasLoopBackService::class);
        $p = new \ReflectionProperty($svc, 'treeProducer');
        $p->setAccessible(true);
        $this->assertInstanceOf(AtlasLoopHypothesisTreeProducer::class, $p->getValue($svc), 'the AppServiceProvider bind must wire the failure-supply tree-producer');
    }
}
