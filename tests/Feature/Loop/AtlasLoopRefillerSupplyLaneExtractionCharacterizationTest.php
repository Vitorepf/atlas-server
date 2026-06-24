<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

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
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopRefillerSupplyLaneExtractionCharacterizationTest extends TestCase
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

        AtlasLoopTask::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_refill_preserves_supply_lane_mint_order_and_line_reduction_contract(): void
    {
        config([
            'atlas.loop.campaign.discovery_roots' => ['app/Services', 'app/X'],
            'atlas.loop.decompose_supply_enabled' => true,
            'atlas.loop.dedup_supply_enabled' => true,
            'atlas.loop.orphan_wiring_supply_enabled' => true,
            'atlas.loop.doc_gap_supply_enabled' => true,
            'atlas.loop.doc_gap_supply_docs_roots' => ['docs'],
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.material_refactor_min_cyclomatic' => 12,
            'atlas.loop.framework_refactor_min_cyclomatic' => 10,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);

        $repo = $this->repo();
        $campaign = AtlasLoopCampaign::query()->create([
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'supply-lane-characterization',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
            'max_seconds' => 60,
        ]);

        $result = $this->refiller()->refill($campaign, 4);

        $this->assertSame(4, $result['enqueued']);
        $tuples = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->get()
            ->map(fn (AtlasLoopTask $task): array => [
                'lane' => (string) $task->source,
                'mintTaskKind' => (string) (($task->payload['objective_kind'] ?? '') ?: ''),
                'targetRelPath' => (string) $task->target_path,
            ])
            ->all();
        $laneOrder = ['decompose' => 0, 'dedup' => 1, 'orphan_wiring' => 2, 'doc_gap' => 3];
        usort($tuples, static fn (array $a, array $b): int => ($laneOrder[$a['lane']] ?? 99) <=> ($laneOrder[$b['lane']] ?? 99));

        $this->assertSame(
            [
                ['lane' => 'decompose', 'mintTaskKind' => AtlasLoopFrameworkRefactorSynthesizer::OBJECTIVE_KIND, 'targetRelPath' => 'app/Services/Grader.php'],
                ['lane' => 'dedup', 'mintTaskKind' => 'refactor_dedup', 'targetRelPath' => 'app/X/Alpha.php'],
                ['lane' => 'orphan_wiring', 'mintTaskKind' => 'orphan_wiring', 'targetRelPath' => 'app/X/Widget.php'],
                ['lane' => 'doc_gap', 'mintTaskKind' => 'feature', 'targetRelPath' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopEgressFirewall.php'],
            ],
            $tuples,
        );

        $refillerPath = app_path('Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php');
        $contents = (string) file_get_contents($refillerPath);
        $this->assertLessThanOrEqual(1834, count(file($refillerPath)), '2234 pre-extraction lines minus >=400');
        $this->assertStringNotContainsString('new AtlasLoopDedupSupplyLane', $contents);
        $this->assertStringNotContainsString('new AtlasLoopOrphanWiringSupplyLane', $contents);
        $this->assertStringNotContainsString('new AtlasLoopDocGapSupplyLane', $contents);
        $this->assertStringContainsString('AtlasLoopRefillerSupplyLaneCoordinator', $contents);
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

    private function repo(): string
    {
        $dir = sys_get_temp_dir().'/atlas-refiller-supply-characterization-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;

        File::ensureDirectoryExists($dir.'/app/Services');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/tests/Unit/Services');
        File::ensureDirectoryExists($dir.'/tests/Unit');
        File::ensureDirectoryExists($dir.'/docs');

        File::put($dir.'/app/Services/Grader.php', "<?php\n\nnamespace App\\Services;\n\nfinal class Grader\n{\n".$this->complexMethod('alpha', 18).$this->complexMethod('beta', 15)."    public function simple(): int\n    {\n        return 1;\n    }\n}\n");
        File::put($dir.'/tests/Unit/Services/GraderTest.php', "<?php\n\nrequire __DIR__ . '/../../../app/Services/Grader.php';\n\n\$g = new \\App\\Services\\Grader();\nif (\$g->simple() !== 1) { exit(1); }\n");
        File::put($dir.'/app/Services/GraderCaller.php', "<?php\n\nnamespace App\\Services;\n\nfinal class GraderCaller\n{\n    public function run(): int\n    {\n        return (new \\App\\Services\\Grader())->alpha(3);\n    }\n}\n");

        $clone = "    public function calc(array \$xs): int\n    {\n        \$sum = 0;\n        foreach (\$xs as \$x) {\n            if (\$x > 0) {\n                \$sum += \$x * 2;\n            }\n        }\n\n        return \$sum;\n    }\n";
        File::put($dir.'/app/X/Alpha.php', "<?php\n\nnamespace App\\X;\n\nfinal class Alpha\n{\n{$clone}}\n");
        File::put($dir.'/app/X/Beta.php', "<?php\n\nnamespace App\\X;\n\nfinal class Beta\n{\n{$clone}}\n");
        File::put($dir.'/app/X/CloneCaller.php', "<?php\n\nnamespace App\\X;\n\nfinal class CloneCaller\n{\n    public function run(): int\n    {\n        return (new Alpha())->calc([1]) + (new Beta())->calc([1]);\n    }\n}\n");
        File::put($dir.'/tests/Unit/AlphaTest.php', $this->classTest('Alpha'));
        File::put($dir.'/tests/Unit/BetaTest.php', $this->classTest('Beta'));

        File::put($dir.'/app/X/Widget.php', "<?php\n\nnamespace App\\X;\n\nfinal class Widget\n{\n    public function build(int \$n): int { return \$n * 3; }\n}\n");
        File::put($dir.'/tests/Unit/WidgetTest.php', "<?php\n\nclass WidgetTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_build(): void\n    {\n        \$this->assertSame(9, (new \\App\\X\\Widget)->build(3));\n    }\n}\n");

        File::put($dir.'/docs/spec.md', "# Spec\n\nThe loop MUST provide `AtlasLoopEgressFirewall` to protect sovereignty.\n");

        return $dir;
    }

    private function complexMethod(string $name, int $branches): string
    {
        $body = "        \$r = 0;\n";
        for ($i = 1; $i <= $branches; $i++) {
            $body .= "        if (\$x > {$i}) { \$r += {$i}; }\n";
        }
        $body .= "        return \$r;\n";

        return "    public function {$name}(int \$x): int\n    {\n{$body}    }\n";
    }

    private function classTest(string $class): string
    {
        return "<?php\n\nclass {$class}Test extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_calc(): void\n    {\n        \$this->assertSame(2, (new \\App\\X\\{$class})->calc([1]));\n    }\n}\n";
    }
}
