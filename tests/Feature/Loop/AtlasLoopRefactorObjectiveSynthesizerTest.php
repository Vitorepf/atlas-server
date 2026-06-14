<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRefactorObjectiveSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GOVERNED REFACTOR (Phase 1) — frozen proof of the STRUCTURAL synthesizer + the refiller seam.
 *
 * Covers: the synthesizer builds a well-formed `refactor_reduce_complexity` payload (frozen
 * sibling test as acceptance.commands, metric_kind=minimize, complexity_proof=true,
 * single-file allowed scope) ONLY for a complex, sibling-backed file; it FAILS CLOSED (null)
 * for files without a sibling or below the complexity floor; the petreo HarnessGuard rejects a
 * forbidden self-target BEFORE enqueue; and with the flag OFF the refiller never synthesizes a
 * refactor task (default-inert).
 */
final class AtlasLoopRefactorObjectiveSynthesizerTest extends TestCase
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

    /** A repo with a high-complexity target + its plain-`php` sibling test. */
    private function repoWithComplexTargetAndSibling(): string
    {
        $d = sys_get_temp_dir().'/atlas-refactor-syn-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;

        $target = <<<'PHP'
<?php
namespace App\Services;
final class Classifier
{
    public function classify(int $n): string
    {
        if ($n === 0) { return 'zero'; }
        elseif ($n === 1) { return 'one'; }
        elseif ($n === 2) { return 'two'; }
        elseif ($n === 3) { return 'three'; }
        elseif ($n === 4) { return 'four'; }
        elseif ($n === 5) { return 'five'; }
        elseif ($n === 6) { return 'six'; }
        elseif ($n === 7) { return 'seven'; }
        else { return 'many'; }
    }
}
PHP;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/Classifier.php', $target);

        // Plain-`php` sibling test that requires the production file directly (the seam the
        // synthesizer retargets to the materialized workspace).
        $sibling = <<<'PHP'
<?php
require __DIR__ . '/../../app/Services/Classifier.php';
use App\Services\Classifier;
$c = new Classifier();
if ($c->classify(0) !== 'zero') { exit(1); }
if ($c->classify(5) !== 'five') { exit(1); }
if ($c->classify(99) !== 'many') { exit(1); }
echo 'green';
PHP;
        File::ensureDirectoryExists($d.'/tests/Unit/Services');
        File::put($d.'/tests/Unit/Services/ClassifierTest.php', $sibling);

        return $d;
    }

    public function test_synthesizes_a_well_formed_refactor_payload_for_a_complex_sibling_backed_file(): void
    {
        $repo = $this->repoWithComplexTargetAndSibling();

        $out = (new AtlasLoopRefactorObjectiveSynthesizer())->synthesize(
            $repo,
            'app/Services/Classifier.php',
            ['cyclomatic' => 9],
            '',
            'target-1',
        );

        $this->assertIsArray($out, 'a complex, sibling-backed file yields a refactor payload');
        $payload = $out['payload'];
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind']);
        $this->assertSame(['src/Classifier.php'], $payload['allowed_files'], 'Phase 1 is single-file');
        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_MINIMIZE, $payload['acceptance']['metric_kind']);
        $this->assertTrue($payload['acceptance']['complexity_proof']);
        $this->assertFalse($payload['acceptance']['revert_recheck'], 'refactors are behaviour-preserving, not RED-earned');
        $this->assertSame(['tests/ClassifierTest.php'], array_column($payload['frozen_tests'], 'path'));
        // The frozen harness was retargeted to the materialized src/ file and is runnable.
        $harness = $payload['frozen_tests'][0]['content'];
        $this->assertStringContainsString("__DIR__ . '/../src/Classifier.php'", $harness);
        $this->assertStringNotContainsString('app/Services/Classifier.php', $harness, 'production path was rewritten away');
        // The acceptance command points at the frozen sibling.
        $this->assertSame(['php tests/ClassifierTest.php'], $payload['acceptance']['commands']);
    }

    public function test_returns_null_for_a_file_without_a_sibling_test(): void
    {
        $repo = $this->repoWithComplexTargetAndSibling();
        // remove the sibling so behaviour cannot be frozen
        File::delete($repo.'/tests/Unit/Services/ClassifierTest.php');

        $out = (new AtlasLoopRefactorObjectiveSynthesizer())->synthesize(
            $repo,
            'app/Services/Classifier.php',
            ['cyclomatic' => 9],
            '',
            'target-1',
        );

        $this->assertNull($out, 'no sibling test => no behaviour anchor => no refactor task (fail-closed)');
    }

    public function test_returns_null_for_a_file_below_the_complexity_floor(): void
    {
        $d = sys_get_temp_dir().'/atlas-refactor-syn-simple-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/Trivial.php', "<?php\nnamespace App\\Services;\nfinal class Trivial { public function v(): int { return 1; } }\n");
        File::ensureDirectoryExists($d.'/tests/Unit/Services');
        File::put($d.'/tests/Unit/Services/TrivialTest.php', "<?php\nrequire __DIR__ . '/../../app/Services/Trivial.php';\necho 'green';\n");

        $out = (new AtlasLoopRefactorObjectiveSynthesizer())->synthesize(
            $d,
            'app/Services/Trivial.php',
            [], // no cyclomatic signal => re-measured from source => below floor
            '',
            'target-1',
        );

        $this->assertNull($out, 'a trivial file is not worth refactoring');
    }

    public function test_petreo_forbidden_self_target_refactor_rejected_before_enqueue(): void
    {
        $repo = $this->repoWithComplexTargetAndSibling();
        $forbidden = 'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php';
        // even if a forbidden file existed and had a sibling, the refiller's guard blocks it.
        config(['atlas.loop.refactor_objectives_enabled' => true]);

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'test',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => [],
        ]);
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id,
            $forbidden,
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 20]),
            ['origin' => 'discovery'],
        );

        $this->bindFakeDriver(); // a forbidden target falls through to the generator; keep it provider-free
        $refiller = $this->refiller();
        $this->invokeGenerateAndEnqueue($refiller, $campaign, $target);

        // No refactor task for the forbidden path (the petreo guard rejected it before enqueue).
        $this->assertSame(0, $this->refactorTaskCount($campaign->id), 'a forbidden self-target must never be enqueued as a refactor task');
    }

    public function test_default_inert_refiller_does_not_synthesize_a_refactor_when_flag_off(): void
    {
        $repo = $this->repoWithComplexTargetAndSibling();
        config(['atlas.loop.refactor_objectives_enabled' => false]);

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'test',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => [],
        ]);
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id,
            'app/Services/Classifier.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 9]),
            ['origin' => 'discovery'],
        );

        $this->bindFakeDriver(); // flag OFF => the refiller falls through to the generator; keep it provider-free
        $refiller = $this->refiller();
        $this->invokeGenerateAndEnqueue($refiller, $campaign, $target);

        // Always-counted assertion (guards against a zero-task "risky" result), plus the
        // per-task check that proves NO enqueued task is a synthesized refactor.
        $this->assertSame(
            0,
            $this->refactorTaskCount($campaign->id),
            'flag OFF => the synthesizer is never invoked (default-inert)',
        );
        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        foreach ($tasks as $t) {
            $payload = (array) json_decode((string) $t->payload, true);
            $this->assertNotSame(
                'refactor_reduce_complexity',
                $payload['objective_kind'] ?? null,
                'flag OFF => the synthesizer is never invoked (default-inert)',
            );
        }
    }

    /** Count the enqueued tasks for a campaign whose objective is a complexity refactor. */
    private function refactorTaskCount(string $campaignId): int
    {
        $count = 0;
        foreach (DB::table('atlas_loop_tasks')->where('campaign_id', $campaignId)->get() as $t) {
            $payload = (array) json_decode((string) $t->payload, true);
            if (($payload['objective_kind'] ?? null) === 'refactor_reduce_complexity') {
                $count++;
            }
        }

        return $count;
    }

    /** A full discovery `scored` array (upsert requires score/self_contained/improvement/novelty/signals). */
    private function scored(array $signals): array
    {
        return [
            'score' => 0.9,
            'self_contained' => 1.0,
            'improvement' => 0.5,
            'novelty' => 1.0,
            'signals' => $signals,
        ];
    }

    /**
     * Bind a provider-free LoopExecutionDriver so the generator fall-through (when the
     * synthesizer does NOT run) never spawns a real provider. It writes nothing, so the
     * generator returns generated=false and no refactor task is enqueued — exactly the state
     * these assertions check.
     */
    private function bindFakeDriver(): void
    {
        $this->app->bind(\App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator::class, function ($app) {
            $fake = new class implements LoopExecutionDriver
            {
                public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
                {
                    return ['status' => 'noop'];
                }
            };

            return new AtlasEvolutionTaskGenerator($fake);
        });
    }

    private function refiller(): AtlasLoopQueueRefiller
    {
        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            new AtlasLoopRefactorObjectiveSynthesizer(),
            new AtlasLoopHarnessGuard(),
        );
    }

    private function invokeGenerateAndEnqueue(AtlasLoopQueueRefiller $refiller, $campaign, $target): string
    {
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }
}
