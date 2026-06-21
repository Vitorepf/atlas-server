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
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §5.6 · THE DOMINANCE NORTH-STAR — comprehension-driven origination DRIVING the live queue. A low-cyclomatic
 * clone is invisible to the proxy scan (cyclomatic/coverage) and would be dropped by the rédea's ambition
 * floor, yet the comprehension brain mints a CERTIFIABLE clone-unification task for it. Armed ⇒ the queue holds
 * a source='dedup' task the proxy lanes never produce; OFF ⇒ none (byte-identical). This is the operator's
 * criterion #1 (the brain DRIVES selection) proven for the clone-unification work type.
 */
final class AtlasLoopDedupDominanceTest extends TestCase
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

    private function cloneMethod(): string
    {
        return "    public function calc(array \$xs): int\n    {\n        \$sum = 0;\n        foreach (\$xs as \$x) {\n            if (\$x > 0) {\n                \$sum += \$x * 2;\n            }\n        }\n\n        return \$sum;\n    }\n";
    }

    private function siblingTest(string $class): string
    {
        return "<?php\n\nclass {$class}Test extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_calc(): void\n    {\n        \$this->assertSame(8, (new \\App\\X\\{$class})->calc([1, 2, 1]));\n    }\n}\n";
    }

    /** A temp repo with a low-cyclomatic clone pair + their asserting siblings (the brain's net-new supply). */
    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-dedup-dom-'.bin2hex(random_bytes(6));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/X');
        File::ensureDirectoryExists($d.'/tests/Unit');
        File::put($d.'/app/X/Alpha.php', "<?php\n\nnamespace App\\X;\n\nfinal class Alpha\n{\n{$this->cloneMethod()}}\n");
        File::put($d.'/app/X/Beta.php', "<?php\n\nnamespace App\\X;\n\nfinal class Beta\n{\n{$this->cloneMethod()}}\n");
        File::put($d.'/tests/Unit/AlphaTest.php', $this->siblingTest('Alpha'));
        File::put($d.'/tests/Unit/BetaTest.php', $this->siblingTest('Beta'));

        return $d;
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'dedup-dom',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function armScope(bool $dedupEnabled): void
    {
        config([
            'atlas.loop.dedup_supply_enabled' => $dedupEnabled,
            'atlas.loop.campaign.discovery_roots' => ['app/X'],
            // every proxy lane OFF, so a source='dedup' task can ONLY come from the comprehension brain.
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.decompose_supply_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
        ]);
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        // No-op generator: any fall-through to the generator yields NO work, so a green dedup-task assertion can
        // ONLY come from the comprehension dedup-supply lane (mirrors the decompose-lane test).
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

    private function dedupTasks(string $campaignId)
    {
        return AtlasLoopTask::query()->where('campaign_id', $campaignId)->where('source', 'dedup')->get();
    }

    public function test_armed_brain_mints_a_certifiable_dedup_task_the_proxy_cannot(): void
    {
        $repo = $this->repo();
        $this->armScope(dedupEnabled: true);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $tasks = $this->dedupTasks($campaign->id);
        $this->assertCount(1, $tasks, 'the comprehension brain mints exactly one clone-unification task');

        $task = $tasks->first();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('refactor_dedup', $payload['objective_kind'] ?? null);
        $this->assertTrue($payload['dedup_proof'] ?? false, 'the material key that survives the proxy gate');
        $this->assertTrue($payload['comprehension_originated'] ?? false, 'provenance: the brain, not the proxy scan');
        $this->assertTrue($payload['acceptance']['dedup_proof'] ?? false);
        $this->assertSame(
            ['app/X/Alpha.php', 'app/X/Beta.php'],
            array_column($payload['acceptance']['clone_target']['members'] ?? [], 'path'),
        );
        $this->assertContains($task->status, [AtlasLoopTask::STATUS_PENDING, AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING]);
    }

    public function test_flag_off_mints_no_dedup_task_byte_identical(): void
    {
        $repo = $this->repo();
        $this->armScope(dedupEnabled: false);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $this->assertCount(0, $this->dedupTasks($campaign->id), 'flag OFF => the brain mints nothing (refill byte-identical)');
    }
}
