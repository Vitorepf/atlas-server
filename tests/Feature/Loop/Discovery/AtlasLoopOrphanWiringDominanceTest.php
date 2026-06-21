<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComplexTargetDecomposer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRefactorObjectiveSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * §5.6 · ORPHAN-WIRING DOMINANCE NORTH-STAR — comprehension-driven origination DRIVING the live queue for the
 * SECOND work type. A tested, built-but-unwired capability has NO high-cyclomatic / missing-coverage signal —
 * the proxy scan never surfaces it as work. Armed ⇒ the queue holds a source='orphan_wiring' directive the
 * proxy lanes never produce; OFF ⇒ none (byte-identical). The operator's criterion #1 for orphan-wiring.
 */
final class AtlasLoopOrphanWiringDominanceTest extends TestCase
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

    /** A temp repo with a tested ORPHAN (a public capability nothing in production calls). */
    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-orphan-dom-'.bin2hex(random_bytes(6));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/X');
        File::ensureDirectoryExists($d.'/tests/Unit');
        File::put($d.'/app/X/Widget.php', "<?php\n\nnamespace App\\X;\n\nfinal class Widget\n{\n    public function build(int \$n): int { return \$n * 3; }\n}\n");
        File::put($d.'/tests/Unit/WidgetTest.php', "<?php\n\nclass WidgetTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_build(): void\n    {\n        \$this->assertSame(9, (new \\App\\X\\Widget)->build(3));\n    }\n}\n");

        return $d;
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'orphan-dom',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function armScope(bool $enabled): void
    {
        config([
            'atlas.loop.orphan_wiring_supply_enabled' => $enabled,
            'atlas.loop.campaign.discovery_roots' => ['app/X'],
            // every proxy lane OFF, so a source='orphan_wiring' task can ONLY come from the comprehension brain.
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.decompose_supply_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.dedup_supply_enabled' => false,
        ]);
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
            new AtlasLoopRefactorObjectiveSynthesizer,
            new AtlasLoopHarnessGuard,
            new AtlasLoopFrameworkRefactorSynthesizer,
            null,
            new AtlasLoopWorkShapeRouter,
            null, null, null, null, null, null, null, null, null, null,
            new AtlasLoopComplexTargetDecomposer,
        );
    }

    private function orphanTasks(string $campaignId)
    {
        return AtlasLoopTask::query()->where('campaign_id', $campaignId)->where('source', 'orphan_wiring')->get();
    }

    public function test_armed_brain_mints_an_orphan_wiring_directive_the_proxy_cannot(): void
    {
        $repo = $this->repo();
        $this->armScope(enabled: true);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $tasks = $this->orphanTasks($campaign->id);
        $this->assertCount(1, $tasks, 'the comprehension brain mints exactly one orphan-wiring directive');

        $task = $tasks->first();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('orphan_wiring', $payload['objective_kind'] ?? null);
        $this->assertSame('app/X/Widget.php', $payload['orphan_path'] ?? null);
        $this->assertSame('App\\X\\Widget', $payload['orphan_fqcn'] ?? null);
        $this->assertTrue($payload['wired_proof'] ?? false, 'the executor + Guard 4e contract rides in the directive');
        $this->assertContains($task->status, [AtlasLoopTask::STATUS_PENDING, AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING]);
    }

    public function test_flag_off_mints_no_orphan_wiring_task_byte_identical(): void
    {
        $repo = $this->repo();
        $this->armScope(enabled: false);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $this->assertCount(0, $this->orphanTasks($campaign->id), 'flag OFF => the brain mints nothing (refill byte-identical)');
    }
}
