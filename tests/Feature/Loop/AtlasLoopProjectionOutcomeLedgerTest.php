<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionOutcomeLedger;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §5 · LEARNING — the architect phase records its outcomes per campaign and gets FASTER at avoiding what it
 * must never originate: a target already parked as a pétreo cert organ skips the ~8s model rebuild on the
 * next encounter. Blast-radius / non-converged parks are recorded but NEVER treated as a permanent veto
 * (the #4 Goodhart line).
 */
final class AtlasLoopProjectionOutcomeLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach (['2026_06_02_000100_create_atlas_loop_runtime_tables.php', '2026_06_02_000200_complete_atlas_loop_runtime_schema.php'] as $f) {
                (require base_path('database/migrations/'.$f))->up();
            }
        }
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
    }

    public function test_records_outcomes_and_only_forbidden_is_a_permanent_verdict(): void
    {
        $ledger = new AtlasLoopProjectionOutcomeLedger;
        $ledger->record('camp-1', 'app/Services/Ai/AutonomousEvolution/Judge.php', AtlasLoopProjectionOutcomeLedger::STATUS_PARKED, 'forbidden_target_petreo');
        $ledger->record('camp-1', 'app/Services/Ai/AutonomousEvolution/Hub.php', AtlasLoopProjectionOutcomeLedger::STATUS_PARKED, 'oscillation_no_content_fixpoint');
        $ledger->record('camp-1', 'app/Services/Ai/AutonomousEvolution/Leaf.php', AtlasLoopProjectionOutcomeLedger::STATUS_CONVERGED, null);

        $this->assertCount(3, $ledger->read('camp-1'));
        // the cert organ is a permanent, safe-to-suppress verdict…
        $this->assertTrue($ledger->wasForbidden('camp-1', 'app/Services/Ai/AutonomousEvolution/Judge.php'));
        $this->assertTrue($ledger->wasForbidden('camp-1', '/App/Services/Ai/AutonomousEvolution/JUDGE.php'), 'path match is normalized');
        // …a blast-radius park is NOT (it may be valuable later — never auto-silenced).
        $this->assertFalse($ledger->wasForbidden('camp-1', 'app/Services/Ai/AutonomousEvolution/Hub.php'));
        // campaign-scoped: another campaign has not learned this.
        $this->assertFalse($ledger->wasForbidden('camp-2', 'app/Services/Ai/AutonomousEvolution/Judge.php'));
    }

    public function test_worker_skips_the_model_rebuild_for_a_campaign_known_forbidden_target(): void
    {
        config()->set('atlas.loop.grounded_projection_enabled', true);
        config()->set('atlas.loop.projection_outcome_learning_enabled', true);

        $store = app(AtlasLoopStore::class);
        $campaign = $store->openCampaign('learn', sys_get_temp_dir(), ['max_seconds' => 3600], [], '');
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';

        // The campaign already learned this target is a pétreo cert organ.
        (new AtlasLoopProjectionOutcomeLedger)->record($campaign->id, $target, AtlasLoopProjectionOutcomeLedger::STATUS_PARKED, 'forbidden_target_petreo');

        $pipeline = new AtlasLoopDeliveryPipeline;
        // repoRoot is intentionally NON-EXISTENT: a model rebuild would fail. The learned skip must park
        // PURELY from the ledger, before groundedRolesFor is ever consulted.
        $pipeline->dispatchProjection($campaign->id, 'obj-learn', 0.9, [
            'built' => ['objective' => 'x', 'payload' => [], 'acceptance_hash' => 'h', 'target_path' => $target, 'leverage' => 0.9],
            'repoRoot' => '/nonexistent-repo-xyz',
            'priority' => 4100,
            'real_target_id' => 'tid-learn',
        ]);
        $row = $pipeline->claimNextProjection($campaign->id, 'worker-A', 300);
        $this->assertIsArray($row);

        $worker = new AtlasLoopProjectionWorker($store, $pipeline, null, new AtlasLoopSystemAxisService(fn (string $r, ?int $w): array => ['axes' => ['wired' => 0.1]]));
        $outcome = $worker->process($row);

        $this->assertSame('parked', $outcome['outcome']);
        $this->assertSame('forbidden_target_petreo_learned', $outcome['reason'], 'parked from the ledger, no model rebuild');
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'a learned-forbidden target never becomes work');
    }
}
