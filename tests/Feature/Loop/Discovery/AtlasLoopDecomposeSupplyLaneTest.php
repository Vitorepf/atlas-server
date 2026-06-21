<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
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

/**
 * THE COVERAGE-DOMINATION FIX, proven at the wiring seam. The loop surfaces ONE refactor per complex file
 * (its worst method); after the first wave of files single-objective substantive supply runs dry and it fills
 * every refill with coverage — yet the SAME multi-method complex files still hold many untapped material
 * methods (cyclomatic >= the material bar). The GATED material-supply lane in
 * {@see AtlasLoopQueueRefiller::tryDecomposeMaterialSupply()} drains that supply.
 *
 * Invariants under test:
 *   1. FLAG ON + a multi-method complex file in scope with NO in-flight task => a REAL governed refactor task
 *      is minted for it (source='decompose', payload decompose_supply=true, objective_kind=refactor — the SAME
 *      framework-refactor synthesizer + complexity-proof cert as a normal refactor, never proxy, never coverage).
 *   2. FLAG OFF => the lane mints NOTHING (refill() is byte-identical to today — the early-return guard).
 *   3. CONFLICT-FREE => a file that already has an in-flight task is skipped (at most one in-flight task per
 *      file, so two workers never grind the same worktree).
 *
 * Uses the loop's focused-migration setUp (no RefreshDatabase: the full suite carries a Postgres-only extension).
 */
final class AtlasLoopDecomposeSupplyLaneTest extends TestCase
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

    /**
     * A temp repo holding ONE multi-method complex production file (two methods well above the 12 material
     * bar), a real convention sibling test (the behavior anchor the framework-refactor synthesizer requires),
     * and a real caller (so the wired-caller gate sees >=1 production caller). This is the realistic shape of
     * an untapped material file the single-objective lane abandons.
     */
    private function repo(): string
    {
        $d = sys_get_temp_dir().'/atlas-decsupply-'.bin2hex(random_bytes(6));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::ensureDirectoryExists($d.'/tests/Unit/Services');
        File::put($d.'/app/Services/Grader.php', $this->graderSource());
        File::put($d.'/tests/Unit/Services/GraderTest.php', $this->siblingTest());
        File::put($d.'/app/Services/GraderCaller.php', $this->callerSource());

        return $d;
    }

    /** Emit a method whose cyclomatic complexity is ~1+$branches (one decision per `if`). */
    private function complexMethod(string $name, int $branches): string
    {
        $body = "        \$r = 0;\n";
        for ($i = 1; $i <= $branches; $i++) {
            $body .= "        if (\$x > {$i}) { \$r += {$i}; }\n";
        }
        $body .= "        return \$r;\n";

        return "    public function {$name}(int \$x): int\n    {\n{$body}    }\n";
    }

    private function graderSource(): string
    {
        return "<?php\n\nnamespace App\\Services;\n\nfinal class Grader\n{\n"
            .$this->complexMethod('alpha', 18)   // cyclomatic ~19 — well above the 12 material bar
            .$this->complexMethod('beta', 15)    // cyclomatic ~16 — also above the bar (untapped)
            ."    public function simple(): int\n    {\n        return 1;\n    }\n"
            ."}\n";
    }

    /** A plain-`php` sibling (require-style) so even the Phase-1 synthesizer would accept it; the framework one accepts the convention sibling. */
    private function siblingTest(): string
    {
        return "<?php\n\n// convention sibling for App\\Services\\Grader — frozen behaviour anchor.\nrequire __DIR__ . '/../../../app/Services/Grader.php';\n\n\$g = new \\App\\Services\\Grader();\nif (\$g->simple() !== 1) { fwrite(STDERR, 'behaviour drift'); exit(1); }\necho \"ok\\n\";\n";
    }

    private function callerSource(): string
    {
        // References the Grader FQCN as a fixed string so the wired-caller grep finds >=1 real caller.
        return "<?php\n\nnamespace App\\Services;\n\nfinal class GraderCaller\n{\n    public function run(): int\n    {\n        return (new \\App\\Services\\Grader())->alpha(3);\n    }\n}\n";
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'decsupply',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    /**
     * Arm ONLY the material-supply lane + its scope. Discovery roots scoped to the temp repo's app/Services so
     * the lane mines exactly that file. The generic provider fallback is OFF (real-work soak default) so a
     * `decompose`-sourced task can ONLY come from the wired lane, never the provider path.
     */
    private function armScope(bool $supplyEnabled): void
    {
        config([
            'atlas.loop.decompose_supply_enabled' => $supplyEnabled,
            'atlas.loop.campaign.discovery_roots' => ['app/Services'],
            'atlas.loop.generic_provider_fallback_enabled' => false,
            // keep the per-target refactor lanes inert so the ONLY decompose-sourced task is the new lane's.
            'atlas.loop.refactor_objectives_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.material_refactor_min_cyclomatic' => 12,
            'atlas.loop.framework_refactor_min_cyclomatic' => 10,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        // No-op generator: if anything falls through to the generator it produces NO real work, so a green
        // decompose-task assertion can ONLY come from the wired material-supply lane.
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
            null, // multiFileRefactorSynthesizer
            null, // nextWorkDecider
            null, // workClassPrior
            null, // selectAdjuster
            null, // constraintsBlockAssembler
            null, // treeProducer
            null, // objectiveProducer
            null, // bugReproductionLane
            null, // pipeline
            null, // failureHandleHarvester (self-resolves)
            new AtlasLoopComplexTargetDecomposer,
        );
    }

    /** The pending/claimed/running tasks the new lane sourced as 'decompose'. */
    private function decomposeTasks(string $campaignId)
    {
        return AtlasLoopTask::query()
            ->where('campaign_id', $campaignId)
            ->where('source', 'decompose')
            ->get();
    }

    public function test_flag_on_mints_a_material_refactor_for_an_untapped_complex_file(): void
    {
        $repo = $this->repo();
        $this->armScope(supplyEnabled: true);
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $tasks = $this->decomposeTasks($campaign->id);
        $this->assertCount(1, $tasks, 'the untapped multi-method complex file is minted as exactly one decompose task');

        $task = $tasks->first();
        $this->assertSame('app/Services/Grader.php', (string) $task->target_path, 'the task targets the complex file');
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertTrue($payload['decompose_supply'] ?? false, 'the task is tagged as material-supply-lane provenance');
        $this->assertSame(
            AtlasLoopFrameworkRefactorSynthesizer::OBJECTIVE_KIND,
            $payload['objective_kind'] ?? null,
            'it is a governed reduce-complexity refactor (the SAME cert as a normal refactor)',
        );
        // MATERIAL-BY-CONSTRUCTION: the acceptance carries the complexity proof (never a proxy / coverage task).
        $this->assertTrue($payload['acceptance']['complexity_proof'] ?? false, 'the acceptance demands a real AST complexity drop');
        $this->assertContains($task->status, [AtlasLoopTask::STATUS_PENDING, AtlasLoopTask::STATUS_CLAIMED, AtlasLoopTask::STATUS_RUNNING]);

        // The file's target row exists and is QUEUED (claimed by the lane, then transitioned on enqueue).
        $target = AtlasLoopTarget::query()
            ->where('campaign_id', $campaign->id)
            ->where('target_path', 'app/Services/Grader.php')
            ->first();
        $this->assertNotNull($target);
        $this->assertSame(AtlasLoopTarget::STATUS_QUEUED, (string) $target->status, 'the minted file is queued for the grinder');
    }

    public function test_flag_off_mints_nothing_byte_identical(): void
    {
        $repo = $this->repo();
        $this->armScope(supplyEnabled: false); // the early-return guard => lane is inert
        $campaign = $this->campaign($repo);

        $this->refiller()->refill($campaign, 4);

        $this->assertCount(0, $this->decomposeTasks($campaign->id), 'flag OFF => the material-supply lane mints nothing');
        $this->assertSame(
            0,
            AtlasLoopTask::query()->where('campaign_id', $campaign->id)->count(),
            'with every other lane inert + flag OFF, no task is minted at all (refill byte-identical to today)',
        );
    }

    public function test_conflict_free_skips_a_file_with_an_inflight_task(): void
    {
        $repo = $this->repo();
        $this->armScope(supplyEnabled: true);
        $campaign = $this->campaign($repo);

        // Pre-seed an in-flight (pending) task for the SAME file: the lane must NOT mint a second one
        // (at most one in-flight task per file => two workers never grind the same worktree).
        AtlasLoopTask::query()->create([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.task.v1',
            'status' => AtlasLoopTask::STATUS_PENDING,
            'source' => 'discovery',
            'self_contained' => true,
            'target_path' => 'app/Services/Grader.php',
            'objective' => 'pre-existing in-flight refactor for Grader',
            'payload' => json_encode(['_target_id' => (string) Str::uuid()]),
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 2,
            'dedupe_key' => hash('sha256', 'preexisting|'.$campaign->id),
        ]);

        $this->refiller()->refill($campaign, 4);

        $this->assertCount(0, $this->decomposeTasks($campaign->id), 'a file with an in-flight task is skipped (serialized same-file work)');
        $this->assertSame(
            1,
            AtlasLoopTask::query()->where('campaign_id', $campaign->id)->where('target_path', 'app/Services/Grader.php')->count(),
            'still exactly the one pre-seeded task — the lane minted no conflicting second task',
        );
    }
}
