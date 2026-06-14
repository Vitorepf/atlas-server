<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DECISION ("o quê a seguir") — frozen proof of the deterministic work-shape router that replaces
 * the static flag cascade with leverage reasoning. The router is a PURE function of the signals
 * discovery already stamps; its load-bearing decision is work_skip (defer a confirmed orphan
 * instead of grinding dead code). Wired into the refiller, a confirmed orphan is deferred without
 * a provider call; every non-orphan still flows through the existing gated lanes.
 */
final class AtlasLoopWorkShapeRouterTest extends TestCase
{
    private function router(): AtlasLoopWorkShapeRouter
    {
        return new AtlasLoopWorkShapeRouter();
    }

    public function test_confirmed_orphan_is_work_skip(): void
    {
        $d = $this->router()->decideShape(['orphan' => true, 'cyclomatic' => 18, 'impact_real_callers' => 0]);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_SKIP, $d['shape']);
        $this->assertSame('confirmed_orphan_zero_leverage', $d['reason']);
    }

    public function test_wired_complex_test_backed_hub_is_single_file_refactor(): void
    {
        $d = $this->router()->decideShape([
            'impact_real_callers' => 5, 'cyclomatic' => 14, 'has_sibling_test' => true, 'framework_reach' => 2,
        ]);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_REFACTOR, $d['shape']);
    }

    public function test_high_leverage_wired_framework_target_is_single_file_refactor(): void
    {
        $d = $this->router()->decideShape([
            'impact_real_callers' => 3, 'refactor_leverage' => 0.8, 'framework_reach' => 1, 'has_sibling_test' => false, 'cyclomatic' => 6,
        ]);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_REFACTOR, $d['shape']);
    }

    public function test_low_complexity_no_sibling_is_edge_fix(): void
    {
        $d = $this->router()->decideShape(['impact_real_callers' => 2, 'cyclomatic' => 3, 'has_sibling_test' => false, 'refactor_leverage' => 0.2]);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, $d['shape']);
    }

    public function test_unmeasured_callers_never_refactor_and_never_skip(): void
    {
        // null callers (unmeasured) must NOT be treated as wired (no false refactor) NOR as orphan
        // (no false skip) — fail-open to edge_fix.
        $d = $this->router()->decideShape(['cyclomatic' => 18, 'has_sibling_test' => true]); // no impact_real_callers
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, $d['shape']);
    }

    public function test_empty_or_garbled_signals_fail_open_to_edge_fix(): void
    {
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, $this->router()->decideShape([])['shape']);
        $this->assertSame(AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX, $this->router()->decideShape(['cyclomatic' => 'x', 'impact_real_callers' => 'y'])['shape']);
    }

    public function test_is_deterministic(): void
    {
        $signals = ['impact_real_callers' => 5, 'cyclomatic' => 14, 'has_sibling_test' => true, 'framework_reach' => 1];
        $first = $this->router()->decideShape($signals)['shape'];
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($first, $this->router()->decideShape($signals)['shape']);
        }
    }

    public function test_refiller_defers_a_confirmed_orphan_without_grinding(): void
    {
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        $repo = sys_get_temp_dir().'/atlas-router-'.bin2hex(random_bytes(4));
        @mkdir($repo.'/app/Services', 0o755, true);
        file_put_contents($repo.'/app/Services/Orphan.php', "<?php\nnamespace App\\Services;\nfinal class Orphan { public function x(): int { return 1; } }\n");

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'router',
            'base_workspace' => $repo, 'provider' => '', 'config' => [],
        ]);
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Orphan.php', hash('sha256', 'x'),
            ['score' => 0.5, 'self_contained' => 1.0, 'improvement' => 0.3, 'novelty' => 1.0, 'signals' => ['orphan' => true, 'impact_real_callers' => 0, 'cyclomatic' => 12]],
            ['origin' => 'discovery'],
        );

        // Provider-free generator so a fall-through could never spawn a real provider.
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        }));
        $refiller = new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            null, new AtlasLoopHarnessGuard(), null, null,
            new AtlasLoopWorkShapeRouter(),
        );

        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);
        $outcome = (string) $ref->invoke($refiller, $campaign, $target, '');

        $this->assertSame('deferred', $outcome, 'a confirmed orphan is deferred by the router, not ground');
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'no provider grind task was enqueued for dead code');

        (new \Symfony\Component\Process\Process(['rm', '-rf', $repo]))->run();
    }
}
