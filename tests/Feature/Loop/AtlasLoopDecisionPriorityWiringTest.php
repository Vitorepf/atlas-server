<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNextWorkDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DECISION ("o quê a seguir") — frozen proof of the WIRING + per-campaign SCHEME FREEZE:
 *   - default-OFF: the enqueue priority is byte-identical to the legacy score*100,
 *   - the pricing scheme is FROZEN per campaign at creation, so flipping the live flag mid-flight
 *     never mixes the legacy and banded scales in one campaign's priority column,
 *   - a confirmed orphan is DEFERRED before any pricing/enqueue,
 *   - claimNextTask orders by the stored integer band (hot path stays pure-integer, no re-resolution).
 */
final class AtlasLoopDecisionPriorityWiringTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

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
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-dpw-'.bin2hex(random_bytes(5));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::ensureDirectoryExists($d.'/app/Callers');
        File::put($d.'/app/Services/Hub.php', "<?php\nnamespace App\\Services;\nfinal class Hub { public function classify(int \$v): string { if (\$v > 1) { return 'a'; } if (\$v > 2) { return 'b'; } if (\$v > 3) { return 'c'; } if (\$v > 4) { return 'd'; } if (\$v > 5) { return 'e'; } if (\$v > 6) { return 'f'; } if (\$v > 7) { return 'g'; } if (\$v > 8) { return 'h'; } if (\$v > 9) { return 'i'; } if (\$v > 10) { return 'j'; } if (\$v > 11) { return 'k'; } return 'z'; } }\n");
        File::put($d.'/app/Callers/CallerA.php', "<?php\nnamespace App\\Callers;\nuse App\\Services\\Hub;\nfinal class CallerA { public function go(Hub \$h): string { return \$h->classify(1); } }\n");

        return $d;
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        }));

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            null,
            new AtlasLoopHarnessGuard(),
            null,
            null,
            new AtlasLoopWorkShapeRouter(),
            null,
            new AtlasLoopNextWorkDecider(),
        );
    }

    private function decided(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, string $repo, string $shape): array
    {
        $m = new \ReflectionMethod($this->refiller(), 'decidedPriority');
        $m->setAccessible(true);

        return (array) $m->invoke($this->refiller(), $campaign, $target, is_array($target->signals) ? $target->signals : [], $repo, $shape);
    }

    private function campaign(string $repo, array $config): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'dpw',
            'base_workspace' => $repo, 'provider' => '', 'config' => $config,
        ]);
    }

    private function target(AtlasLoopCampaign $campaign): AtlasLoopTarget
    {
        return app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Hub.php', hash('sha256', 'hub'),
            ['score' => 0.5, 'self_contained' => 0.8, 'improvement' => 0.4, 'novelty' => 1.0, 'signals' => ['impact_real_callers' => 1, 'cyclomatic' => 12]],
            ['origin' => 'discovery'],
        );
    }

    public function test_default_off_priority_is_byte_identical_to_legacy(): void
    {
        // Frozen OFF => the decided priority is exactly (int) round(score*100), no receipt.
        $repo = $this->repo();
        $campaign = $this->campaign($repo, ['decision_priority_enabled' => false]);
        $target = $this->target($campaign);

        $dp = $this->decided($campaign, $target, $repo, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);

        $this->assertSame((int) round(((float) $target->score) * 100), $dp['priority'], 'OFF => legacy score*100, byte-identical');
        $this->assertSame([], $dp['receipt'], 'OFF => no decision receipt stamped');
    }

    public function test_openCampaign_freezes_the_live_flag_at_creation(): void
    {
        $repo = $this->repo();
        config(['atlas.loop.decision_priority_enabled' => true]);
        $on = app(AtlasLoopStore::class)->openCampaign('g', $repo);
        $this->assertTrue((bool) ($on->config['decision_priority_enabled'] ?? null), 'live ON is frozen into the campaign at creation');

        config(['atlas.loop.decision_priority_enabled' => false]);
        $off = app(AtlasLoopStore::class)->openCampaign('g', $repo);
        $this->assertFalse((bool) ($off->config['decision_priority_enabled'] ?? true), 'live OFF is frozen into the campaign at creation');
    }

    public function test_flag_flip_does_not_change_an_in_flight_campaign_scheme(): void
    {
        $repo = $this->repo();
        // Campaign A was created while the flag was OFF (frozen false).
        $a = $this->campaign($repo, ['decision_priority_enabled' => false]);
        $ta = $this->target($a);
        // Now the operator flips the LIVE flag ON mid-flight.
        config(['atlas.loop.decision_priority_enabled' => true]);

        $aPrice = $this->decided($a, $ta, $repo, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $this->assertSame((int) round(((float) $ta->score) * 100), $aPrice['priority'], 'in-flight campaign keeps its frozen legacy scheme despite the live flip');

        // A NEW campaign created after the flip picks up the banded scheme uniformly.
        $b = $this->campaign($repo, ['decision_priority_enabled' => true]);
        $tb = $this->target($b);
        $bPrice = $this->decided($b, $tb, $repo, AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
        $this->assertGreaterThanOrEqual(2_000, $bPrice['priority'], 'a fresh campaign prices in the banded scheme');
        $this->assertNotSame([], $bPrice['receipt'], 'banded scheme stamps a decision receipt');
    }

    public function test_confirmed_orphan_is_deferred_before_any_pricing(): void
    {
        $repo = $this->repo();
        // make the Hub an orphan: remove its caller so it has 0 real callers.
        File::delete($repo.'/app/Callers/CallerA.php');
        $campaign = $this->campaign($repo, ['decision_priority_enabled' => true]);
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Hub.php', hash('sha256', 'orphan'),
            ['score' => 0.5, 'self_contained' => 1.0, 'improvement' => 0.3, 'novelty' => 1.0, 'signals' => ['orphan' => true, 'impact_real_callers' => 0, 'cyclomatic' => 12]],
            ['origin' => 'discovery'],
        );

        $ref = new \ReflectionMethod($this->refiller(), 'generateAndEnqueue');
        $ref->setAccessible(true);
        $outcome = (string) $ref->invoke($this->refiller(), $campaign, $target, '');

        $this->assertSame('deferred', $outcome, 'a confirmed orphan is deferred before pricing/enqueue');
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'dead code is never priced nor enqueued');
    }

    public function test_claim_orders_by_band_on_the_pure_integer_column(): void
    {
        $repo = $this->repo();
        $campaign = $this->campaign($repo, ['decision_priority_enabled' => true]);
        $store = app(AtlasLoopStore::class);

        // Enqueue three tasks priced in the banded scheme: edge < refactor < obra.
        $store->enqueueTask($campaign->id, 'edge', ['materializer' => 'framework'], 'discovery', 'a/Edge.php', 2_100, true, 'he');
        $store->enqueueTask($campaign->id, 'refactor', ['materializer' => 'framework'], 'discovery', 'a/Refactor.php', 4_500, true, 'hr');
        $store->enqueueTask($campaign->id, 'obra', ['materializer' => 'framework'], 'discovery', 'a/Obra.php', 6_600, true, 'ho');

        // The hot claim path (pure-integer ORDER BY priority DESC) must hand out the highest band first.
        $first = $store->claimNextTask($campaign->id, 'w', 60);
        $second = $store->claimNextTask($campaign->id, 'w', 60);
        $third = $store->claimNextTask($campaign->id, 'w', 60);

        $this->assertSame('obra', $first?->objective);
        $this->assertSame('refactor', $second?->objective);
        $this->assertSame('edge', $third?->objective);
    }
}
