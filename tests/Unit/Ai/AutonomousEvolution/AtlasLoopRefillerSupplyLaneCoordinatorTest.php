<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopRefillerSupplyLaneCoordinator;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComplexTargetDecomposer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRefactorObjectiveSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasLoopRefillerSupplyLaneCoordinatorTest extends TestCase
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
        AtlasLoopTarget::query()->delete();
        AtlasLoopCampaign::query()->delete();
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }

        parent::tearDown();
    }

    public function test_decompose_lane_supplies_and_starves_honestly(): void
    {
        config([
            'atlas.loop.decompose_supply_enabled' => true,
            'atlas.loop.campaign.discovery_roots' => ['app/Services'],
            'atlas.loop.material_refactor_min_cyclomatic' => 12,
            'atlas.loop.framework_refactor_min_cyclomatic' => 10,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);

        $repo = $this->decomposeRepo();
        $campaign = $this->campaign($repo, 'decompose');
        $minted = $this->coordinator()->trySupply($campaign, '', $repo, 4, 'decompose');

        $this->assertSame(1, $minted);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('source', 'decompose')->sole();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('app/Services/Grader.php', $task->target_path);
        $this->assertTrue($payload['decompose_supply'] ?? false);
        $this->assertSame(AtlasLoopFrameworkRefactorSynthesizer::OBJECTIVE_KIND, $payload['objective_kind'] ?? null);

        AtlasLoopTask::query()->delete();
        $starvedRepo = $this->plainRepo();
        $starvedCampaign = $this->campaign($starvedRepo, 'decompose-starved');
        $this->assertSame(0, $this->coordinator()->trySupply($starvedCampaign, '', $starvedRepo, 4, 'decompose'));
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $starvedCampaign->id)->count());
    }

    public function test_dedup_lane_supplies_and_starves_honestly(): void
    {
        config([
            'atlas.loop.dedup_supply_enabled' => true,
            'atlas.loop.campaign.discovery_roots' => ['app/X'],
        ]);

        $repo = $this->dedupRepo();
        $campaign = $this->campaign($repo, 'dedup');
        $minted = $this->coordinator()->trySupply($campaign, '', $repo, 4, 'dedup');

        $this->assertSame(1, $minted);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('source', 'dedup')->sole();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('app/X/Alpha.php', $task->target_path);
        $this->assertSame('refactor_dedup', $payload['objective_kind'] ?? null);
        $this->assertTrue($payload['dedup_proof'] ?? false);

        AtlasLoopTask::query()->delete();
        $starvedRepo = $this->singleClassRepo();
        $starvedCampaign = $this->campaign($starvedRepo, 'dedup-starved');
        $this->assertSame(0, $this->coordinator()->trySupply($starvedCampaign, '', $starvedRepo, 4, 'dedup'));
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $starvedCampaign->id)->count());
    }

    public function test_orphan_wiring_lane_supplies_and_starves_honestly(): void
    {
        config([
            'atlas.loop.orphan_wiring_supply_enabled' => true,
            'atlas.loop.campaign.discovery_roots' => ['app/X'],
        ]);

        $repo = $this->orphanRepo();
        $campaign = $this->campaign($repo, 'orphan');
        $minted = $this->coordinator()->trySupply($campaign, '', $repo, 4, 'orphan_wiring');

        $this->assertSame(1, $minted);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('source', 'orphan_wiring')->sole();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('app/X/Widget.php', $task->target_path);
        $this->assertSame('orphan_wiring', $payload['objective_kind'] ?? null);
        $this->assertSame('App\\X\\Widget', $payload['orphan_fqcn'] ?? null);

        AtlasLoopTask::query()->delete();
        $starvedRepo = $this->wiredRepo();
        $starvedCampaign = $this->campaign($starvedRepo, 'orphan-starved');
        $this->assertSame(0, $this->coordinator()->trySupply($starvedCampaign, '', $starvedRepo, 4, 'orphan_wiring'));
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $starvedCampaign->id)->count());
    }

    public function test_doc_gap_lane_supplies_and_starves_honestly(): void
    {
        config([
            'atlas.loop.doc_gap_supply_enabled' => true,
            'atlas.loop.doc_gap_supply_docs_roots' => ['docs'],
            'atlas.loop.campaign.discovery_roots' => ['app/X'],
        ]);

        $repo = $this->docGapRepo();
        $campaign = $this->campaign($repo, 'doc-gap');
        $minted = $this->coordinator()->trySupply($campaign, '', $repo, 4, 'doc_gap');

        $this->assertSame(1, $minted);
        $task = AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('source', 'doc_gap')->sole();
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('AtlasLoopEgressFirewall', $payload['capability'] ?? null);
        $this->assertSame('feature', $payload['objective_kind'] ?? null);
        $this->assertStringContainsString('AtlasLoopEgressFirewall', (string) $task->target_path);

        AtlasLoopTask::query()->delete();
        $starvedRepo = $this->docGapFreeRepo();
        $starvedCampaign = $this->campaign($starvedRepo, 'doc-gap-starved');
        $this->assertSame(0, $this->coordinator()->trySupply($starvedCampaign, '', $starvedRepo, 4, 'doc_gap'));
        $this->assertSame(0, AtlasLoopTask::query()->where('campaign_id', $starvedCampaign->id)->count());
    }

    private function coordinator(): AtlasLoopRefillerSupplyLaneCoordinator
    {
        return new AtlasLoopRefillerSupplyLaneCoordinator(
            repository: app(AtlasLoopTargetRepository::class),
            store: app(AtlasLoopStore::class),
            refactorSynthesizer: new AtlasLoopRefactorObjectiveSynthesizer,
            harnessGuard: new AtlasLoopHarnessGuard,
            frameworkRefactorSynthesizer: new AtlasLoopFrameworkRefactorSynthesizer,
            complexTargetDecomposer: new AtlasLoopComplexTargetDecomposer,
            discoveryScopeFiles: fn (string $repoRoot, AtlasLoopCampaign $campaign): array => $this->discoveryScopeFiles($repoRoot, $campaign),
            fileHasInflightTask: fn (string $campaignId, string $relPath): bool => AtlasLoopTask::query()
                ->where('campaign_id', $campaignId)
                ->where('target_path', $relPath)
                ->whereIn('status', [AtlasLoopTask::STATUS_PENDING, AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING])
                ->exists(),
            touchHeartbeat: static function (AtlasLoopCampaign $campaign): void {},
            decidedPriority: static fn (AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $repoRoot, string $shapeHint): array => [
                'priority' => 50,
                'receipt' => [],
            ],
            withSelfImprovementMarker: static fn (array $payload, array $signals): array => $payload,
            stampLastObjective: static function (AtlasLoopTarget $target, string $objective): void {},
            completeTargetEnqueue: static function (AtlasLoopTarget $target, ?AtlasLoopTask $task, string $queuedReason): string {
                if ($task instanceof AtlasLoopTask) {
                    $target->forceFill(['status' => AtlasLoopTarget::STATUS_QUEUED])->save();

                    return 'enqueued';
                }

                return 'deferred';
            },
            effectiveDiscoveryRoots: static fn (AtlasLoopCampaign $campaign): array => array_values((array) config('atlas.loop.campaign.discovery_roots', [])),
            comprehensionQuery: static fn (string $repoRoot, array $opts): AtlasLoopScopeComprehensionQuery => new AtlasLoopScopeComprehensionQuery(
                new AtlasLoopScopeComprehensionModelBuilder,
                $repoRoot,
                $opts,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function discoveryScopeFiles(string $repoRoot, AtlasLoopCampaign $campaign): array
    {
        $out = [];
        foreach ((array) config('atlas.loop.campaign.discovery_roots', []) as $root) {
            $root = trim((string) $root, '/');
            if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                continue;
            }
            foreach (File::allFiles($repoRoot.'/'.$root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $out[] = ltrim(str_replace($repoRoot.'/', '', $file->getPathname()), '/');
            }
        }

        sort($out);

        return array_values(array_unique($out));
    }

    private function campaign(string $repo, string $goal): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::query()->create([
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => $goal,
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
            'max_seconds' => 60,
        ]);
    }

    private function decomposeRepo(): string
    {
        $dir = $this->repoDir('decompose');
        File::ensureDirectoryExists($dir.'/app/Services');
        File::ensureDirectoryExists($dir.'/tests/Unit/Services');
        File::put($dir.'/app/Services/Grader.php', "<?php\n\nnamespace App\\Services;\n\nfinal class Grader\n{\n".$this->complexMethod('alpha', 18).$this->complexMethod('beta', 15)."    public function simple(): int\n    {\n        return 1;\n    }\n}\n");
        File::put($dir.'/tests/Unit/Services/GraderTest.php', "<?php\n\nrequire __DIR__ . '/../../../app/Services/Grader.php';\n\n\$g = new \\App\\Services\\Grader();\nif (\$g->simple() !== 1) { exit(1); }\n");
        File::put($dir.'/app/Services/GraderCaller.php', "<?php\n\nnamespace App\\Services;\n\nfinal class GraderCaller\n{\n    public function run(): int\n    {\n        return (new \\App\\Services\\Grader())->alpha(3);\n    }\n}\n");

        return $dir;
    }

    private function plainRepo(): string
    {
        $dir = $this->repoDir('plain');
        File::ensureDirectoryExists($dir.'/app/Services');
        File::put($dir.'/app/Services/Plain.php', "<?php\n\nnamespace App\\Services;\n\nfinal class Plain\n{\n    public function ok(): int\n    {\n        return 1;\n    }\n}\n");

        return $dir;
    }

    private function dedupRepo(): string
    {
        $dir = $this->repoDir('dedup');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/tests/Unit');
        $clone = "    public function calc(array \$xs): int\n    {\n        \$sum = 0;\n        foreach (\$xs as \$x) {\n            if (\$x > 0) {\n                \$sum += \$x * 2;\n            }\n        }\n\n        return \$sum;\n    }\n";
        File::put($dir.'/app/X/Alpha.php', "<?php\n\nnamespace App\\X;\n\nfinal class Alpha\n{\n{$clone}}\n");
        File::put($dir.'/app/X/Beta.php', "<?php\n\nnamespace App\\X;\n\nfinal class Beta\n{\n{$clone}}\n");
        File::put($dir.'/tests/Unit/AlphaTest.php', $this->classTest('Alpha'));
        File::put($dir.'/tests/Unit/BetaTest.php', $this->classTest('Beta'));

        return $dir;
    }

    private function singleClassRepo(): string
    {
        $dir = $this->repoDir('single');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/tests/Unit');
        File::put($dir.'/app/X/Solo.php', "<?php\n\nnamespace App\\X;\n\nfinal class Solo\n{\n    public function calc(array \$xs): int\n    {\n        return count(\$xs);\n    }\n}\n");
        File::put($dir.'/tests/Unit/SoloTest.php', $this->classTest('Solo'));

        return $dir;
    }

    private function orphanRepo(): string
    {
        $dir = $this->repoDir('orphan');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/tests/Unit');
        File::put($dir.'/app/X/Widget.php', "<?php\n\nnamespace App\\X;\n\nfinal class Widget\n{\n    public function build(int \$n): int { return \$n * 3; }\n}\n");
        File::put($dir.'/tests/Unit/WidgetTest.php', "<?php\n\nclass WidgetTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_build(): void\n    {\n        \$this->assertSame(9, (new \\App\\X\\Widget)->build(3));\n    }\n}\n");

        return $dir;
    }

    private function wiredRepo(): string
    {
        $dir = $this->repoDir('wired');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/tests/Unit');
        File::put($dir.'/app/X/WiredWidget.php', "<?php\n\nnamespace App\\X;\n\nfinal class WiredWidget\n{\n    public function build(int \$n): int { return \$n * 3; }\n}\n");
        File::put($dir.'/app/X/WiredWidgetCaller.php', "<?php\n\nnamespace App\\X;\n\nfinal class WiredWidgetCaller\n{\n    public function run(): int\n    {\n        return (new \\App\\X\\WiredWidget())->build(3);\n    }\n}\n");
        File::put($dir.'/tests/Unit/WiredWidgetTest.php', "<?php\n\nclass WiredWidgetTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_build(): void\n    {\n        \$this->assertSame(9, (new \\App\\X\\WiredWidget)->build(3));\n    }\n}\n");

        return $dir;
    }

    private function docGapRepo(): string
    {
        $dir = $this->repoDir('doc-gap');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/docs');
        File::put($dir.'/app/X/Existing.php', "<?php\n\nnamespace App\\X;\n\nfinal class Existing { public function go(): int { return 1; } }\n");
        File::put($dir.'/docs/spec.md', "# Spec\n\nThe loop MUST provide `AtlasLoopEgressFirewall` to protect sovereignty.\n");

        return $dir;
    }

    private function docGapFreeRepo(): string
    {
        $dir = $this->repoDir('doc-gap-free');
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/docs');
        File::put($dir.'/app/X/Existing.php', "<?php\n\nnamespace App\\X;\n\nfinal class Existing { public function go(): int { return 1; } }\n");
        File::put($dir.'/docs/spec.md', "# Spec\n\nEverything described here already exists.\n");

        return $dir;
    }

    private function repoDir(string $name): string
    {
        $dir = sys_get_temp_dir().'/atlas-supply-coordinator-'.$name.'-'.bin2hex(random_bytes(5));
        $this->dirs[] = $dir;

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
