<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopMultiFileRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterDetectorService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * OPTION 3 · slice-1b — the refiller's autonomous MULTI-FILE refactor lane, with the CO-GATE the
 * adversary required: it fires only when BOTH multi_file_refactor_objectives_enabled AND
 * refactor_multi_file_via_obra are ON. With the route flag OFF the lane is inert, so a multi-file
 * task can never fall through to the single-file grind (where a >=2-file diff could reach main with
 * only a single-target canary). The lane only ENQUEUES a task; it never merges.
 */
final class AtlasLoopMultiFileRefactorLaneTest extends TestCase
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

    private function clusterRepo(): string
    {
        $d = sys_get_temp_dir().'/atlas-mfl-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::ensureDirectoryExists($d.'/app/Callers');
        File::ensureDirectoryExists($d.'/tests/Unit/Services');
        File::ensureDirectoryExists($d.'/tests/Unit/Callers');
        File::put($d.'/app/Services/Hub.php', "<?php\nnamespace App\\Services;\nfinal class Hub { public function x(int \$n): int { return \$n > 0 ? \$n : 0; } }\n");
        File::put($d.'/tests/Unit/Services/HubTest.php', "<?php\nnamespace Tests\\Unit\\Services;\nfinal class HubTest { public function t(): void {} }\n");
        foreach (['CallerA', 'CallerB'] as $c) {
            File::put($d.'/app/Callers/'.$c.'.php', "<?php\nnamespace App\\Callers;\nuse App\\Services\\Hub;\nfinal class {$c} { public function go(Hub \$h): int { return \$h->x(1); } }\n");
            File::put($d.'/tests/Unit/Callers/'.$c.'Test.php', "<?php\nnamespace Tests\\Unit\\Callers;\nfinal class {$c}Test { public function t(): void {} }\n");
        }

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
            new AtlasLoopObraClusterDetectorService(app(AtlasSelfImprovementProposalBacklogService::class), null, new AtlasLoopHarnessGuard()),
            null,
            new AtlasLoopMultiFileRefactorSynthesizer(),
        );
    }

    private function seedHub(AtlasLoopCampaign $campaign): \App\Models\AtlasLoopTarget
    {
        return app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Hub.php', hash('sha256', 'hub'),
            ['score' => 0.9, 'self_contained' => 0.45, 'improvement' => 0.5, 'novelty' => 1.0, 'signals' => [
                'impact_real_callers' => 3, 'cyclomatic' => 12, 'cyclomatic_total' => 40, 'refactor_leverage' => 0.8, 'framework_reach' => 1,
            ]],
            ['origin' => 'discovery'],
        );
    }

    private function invoke(AtlasLoopQueueRefiller $refiller, $campaign, $target): string
    {
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'mfl',
            'base_workspace' => $repo, 'provider' => '', 'config' => [],
        ]);
    }

    public function test_both_flags_on_enqueues_a_multi_file_refactor_task(): void
    {
        config([
            'atlas.loop.multi_file_refactor_objectives_enabled' => true,
            'atlas.loop.refactor_multi_file_via_obra' => true,
            'atlas.loop.obra_cluster_min_callers' => 3,
            'atlas.loop.obra_cluster_min_cyclomatic' => 10,
            'atlas.loop.obra_cluster_leverage_floor' => 0.5,
        ]);
        $repo = $this->clusterRepo();
        $campaign = $this->campaign($repo);
        $target = $this->seedHub($campaign);

        $outcome = $this->invoke($this->refiller(), $campaign, $target);

        $this->assertSame('enqueued', $outcome);
        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks);
        $payload = (array) json_decode((string) $tasks->first()->payload, true);
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind'] ?? null);
        $this->assertTrue((bool) ($payload['multi_file'] ?? false));
        $this->assertGreaterThanOrEqual(2, count((array) ($payload['allowed_files'] ?? [])), 'a real >=2-file cluster');
    }

    public function test_cogate_producer_on_but_route_off_enqueues_zero_multi_file_tasks(): void
    {
        // THE adversary floor test: producer ON but refactor_multi_file_via_obra OFF must NOT
        // enqueue a multi-file refactor task (it would fall through to the single-file grind).
        config([
            'atlas.loop.multi_file_refactor_objectives_enabled' => true,
            'atlas.loop.refactor_multi_file_via_obra' => false, // route OFF
            'atlas.loop.framework_refactor_enabled' => false,    // keep the single-file refactor lane quiet
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.obra_cluster_min_callers' => 3,
            'atlas.loop.obra_cluster_min_cyclomatic' => 10,
            'atlas.loop.obra_cluster_leverage_floor' => 0.5,
        ]);
        $repo = $this->clusterRepo();
        $campaign = $this->campaign($repo);
        $target = $this->seedHub($campaign);

        $this->invoke($this->refiller(), $campaign, $target);

        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        foreach ($tasks as $t) {
            $payload = (array) json_decode((string) $t->payload, true);
            $this->assertNotTrue($payload['multi_file'] ?? false, 'route OFF => no multi-file task is admissible (no fall-through)');
        }
    }

    public function test_producer_flag_off_is_inert(): void
    {
        config([
            'atlas.loop.multi_file_refactor_objectives_enabled' => false,
            'atlas.loop.refactor_multi_file_via_obra' => true,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
        ]);
        $repo = $this->clusterRepo();
        $campaign = $this->campaign($repo);
        $target = $this->seedHub($campaign);

        $this->invoke($this->refiller(), $campaign, $target);

        foreach (DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get() as $t) {
            $payload = (array) json_decode((string) $t->payload, true);
            $this->assertNotTrue($payload['multi_file'] ?? false, 'producer flag OFF => lane inert');
        }
    }
}
