<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRefactorObjectiveSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FRAMEWORK REFACTOR (heavy, behavior-preserving) — frozen proof of the STRUCTURAL framework
 * refactor synthesizer + the refiller's framework-branch seam.
 *
 * Covers: the synthesizer builds a well-formed `refactor_reduce_complexity` framework payload
 * (real PHPUnit sibling as the frozen behavior test, metric_kind=minimize, complexity_proof=true,
 * revert_recheck=false, materializer=framework, NO intent_verifier_factory) ONLY for a
 * HIGH-COMPLEXITY, WIRED, test-backed framework file; it FAILS CLOSED (null) below the complexity
 * floor, when unwired, or with no sibling test; and with the flag OFF the refiller emits the
 * edge-gap framework objective byte-identical to today (no refactor task).
 */
final class AtlasLoopFrameworkRefactorSynthesizerTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['atlas.loop.proxy_refactor_supply_enabled' => true]);
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

    /** A repo whose target is a high-complexity FRAMEWORK service with a real PHPUnit sibling. */
    private function repoWithComplexFrameworkTargetAndSibling(): string
    {
        $d = sys_get_temp_dir().'/atlas-fw-refactor-syn-'.bin2hex(random_bytes(4));
        $this->dirs[] = $d;

        // Framework-reach target (uses Illuminate) with a deliberately high-cyclomatic method.
        $target = <<<'PHP'
<?php
namespace App\Services;
use Illuminate\Support\Facades\Log;
final class Router
{
    public function route(int $n): string
    {
        if ($n === 0) { return 'a'; }
        elseif ($n === 1) { return 'b'; }
        elseif ($n === 2) { return 'c'; }
        elseif ($n === 3) { return 'd'; }
        elseif ($n === 4) { return 'e'; }
        elseif ($n === 5) { return 'f'; }
        elseif ($n === 6) { return 'g'; }
        elseif ($n === 7) { return 'h'; }
        elseif ($n === 8) { return 'i'; }
        else { return 'z'; }
    }
}
PHP;
        File::ensureDirectoryExists($d.'/app/Services');
        File::put($d.'/app/Services/Router.php', $target);

        // A real PHPUnit-style sibling test (the behavior anchor, *Test.php convention).
        $sibling = <<<'PHP'
<?php
namespace Tests\Unit\Services;
use PHPUnit\Framework\TestCase;
use App\Services\Router;
final class RouterTest extends TestCase
{
    public function test_routes(): void
    {
        $r = new Router();
        $this->assertSame('a', $r->route(0));
        $this->assertSame('f', $r->route(5));
        $this->assertSame('z', $r->route(42));
    }
}
PHP;
        File::ensureDirectoryExists($d.'/tests/Unit/Services');
        File::put($d.'/tests/Unit/Services/RouterTest.php', $sibling);

        return $d;
    }

    public function test_synthesizes_a_well_formed_framework_refactor_payload(): void
    {
        config([
            'atlas.loop.extract_sequence_enabled' => false,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

        $out = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor(
            $repo,
            'app/Services/Router.php',
            ['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 2],
            '',
            'target-1',
        );

        $this->assertIsArray($out, 'a complex, wired, test-backed framework file yields a refactor payload');
        $payload = $out['payload'];
        $this->assertSame('framework', $payload['materializer']);
        $this->assertArrayNotHasKey('intent_verifier_factory', $payload, 'refactors use the real sibling, not a compiled RED verifier');
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind']);
        $this->assertStringContainsString("final class Router", (string) ($payload['target_content'] ?? ''), 'framework payload snapshots the target content for dirty-worktree materialization');
        $this->assertSame(['app/Services/Router.php'], $payload['allowed_files']);
        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_MINIMIZE, $payload['acceptance']['metric_kind']);
        $this->assertTrue($payload['acceptance']['complexity_proof']);
        $this->assertTrue($payload['acceptance']['quality_bar_gate']);
        $this->assertSame(9.0, (float) $payload['acceptance']['quality_bar']);
        $this->assertFalse($payload['acceptance']['revert_recheck'], 'refactors are behavior-preserving, not RED-earned');
        $this->assertSame(['tests/Unit/Services/RouterTest.php'], array_column($payload['frozen_tests'], 'path'));
        // Smarter objective: NAMES the worst method (Router::route() is the only/worst method in the
        // fixture) so the provider targets the right method, demands the AST drop, and scopes to one file.
        $this->assertStringContainsString('route()', $out['objective'], 'objective names the worst method (route) for surgical targeting');
        $this->assertStringContainsString('REDUCE', $out['objective']);
        $this->assertStringContainsString('Edit ONLY', $out['objective'], 'objective scopes the provider to the single file (cuts out_of_scope_change rejections)');
    }

    public function test_extract_sequence_tag_emitted_when_armed_and_absent_when_off(): void
    {
        // ACDE lever #6: armed, the in-place worst-method reduction is tagged as one bounded step of an
        // extract sequence; OFF (default) the payload is untouched (byte-identical).
        config([
            'atlas.loop.extract_sequence_enabled' => false,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();
        $signals = ['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 2];

        $off = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor($repo, 'app/Services/Router.php', $signals, '', 'target-1');
        $this->assertArrayNotHasKey('extract_sequence_id', $off['payload'], 'OFF => no sequence metadata (byte-identical)');

        config(['atlas.loop.extract_sequence_enabled' => true, 'atlas.loop.extract_sequence_tractable_cyclomatic' => 3]);
        $on = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor($repo, 'app/Services/Router.php', $signals, '', 'target-1');
        $this->assertStringStartsWith('xseq-', (string) $on['payload']['extract_sequence_id']);
        $this->assertNotEmpty($on['payload']['extract_sequence_plan'], 'the complex worst method projects at least one step');
        $this->assertSame(3, $on['payload']['extract_sequence_tractable_cyclomatic']);
        // R1 slice 2/2: nextStep() is now live — it pins the worst-above-threshold method with the BARE name
        // (route) the objective builder uses, via the identity shim.
        $this->assertSame('route', $on['payload']['extract_sequence_step']['target_method_bare'] ?? null);
        $this->assertStringContainsString('ONE bounded extract-sequence step', $on['objective']);
        $this->assertStringContainsString('lowers Router.php::route() below its current cyclomatic', $on['objective']);
        $this->assertStringContainsString('total cyclomatic/branch count flat or lower', $on['objective']);
        $this->assertStringContainsString('collapse repeated boolean-chain guards', $on['objective']);
        $this->assertStringContainsString('preserve the exact falsey behavior', $on['objective']);
        $this->assertStringContainsString('helper methods when they are branch-free', $on['objective']);
    }

    public function test_returns_null_below_the_complexity_floor(): void
    {
        config(['atlas.loop.framework_refactor_min_cyclomatic' => 50]); // unreachable
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

        $out = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor(
            $repo,
            'app/Services/Router.php',
            ['cyclomatic' => 10, 'impact_real_callers' => 5],
            '',
            'target-1',
        );

        $this->assertNull($out, 'below the complexity floor => not worth a heavy refactor');
    }

    public function test_returns_null_when_not_wired(): void
    {
        config([
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

        $out = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor(
            $repo,
            'app/Services/Router.php',
            ['cyclomatic' => 10, 'impact_real_callers' => 0], // confirmed orphan
            '',
            'target-1',
        );

        $this->assertNull($out, 'an orphan (0 real callers) is not worth a refactor');
    }

    public function test_returns_null_without_a_sibling_test(): void
    {
        config(['atlas.loop.framework_refactor_min_cyclomatic' => 8]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();
        File::delete($repo.'/tests/Unit/Services/RouterTest.php');

        $out = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor(
            $repo,
            'app/Services/Router.php',
            ['cyclomatic' => 10, 'impact_real_callers' => 3],
            '',
            'target-1',
        );

        $this->assertNull($out, 'no behavior anchor => no refactor task (fail-closed)');
    }

    public function test_default_off_refiller_emits_edge_gap_not_refactor_when_flag_off(): void
    {
        config([
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.framework_edge_gap_fallback_enabled' => true,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

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
            'app/Services/Router.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $this->invokeGenerateAndEnqueue($this->refiller(), $campaign, $target);

        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'the framework edge-gap task is still enqueued');
        foreach ($tasks as $t) {
            $payload = (array) json_decode((string) $t->payload, true);
            $this->assertNotSame('refactor_reduce_complexity', $payload['objective_kind'] ?? null, 'flag OFF => no refactor objective (default-inert)');
            $this->assertTrue((bool) ($payload['intent_verifier_factory'] ?? false), 'flag OFF => the byte-identical edge-gap path runs');
        }
    }

    public function test_refiller_can_disable_unverifiable_framework_edge_gap_fallback(): void
    {
        config([
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.framework_edge_gap_fallback_enabled' => false,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

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
            'app/Services/Router.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $outcome = $this->invokeGenerateAndEnqueue($this->refiller(), $campaign, $target);

        $this->assertSame('quarantined', $outcome, 'with the legacy fallback off, unverifiable edge-gap work falls through to RED generation');
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count(), 'no task without executable verification atom is queued');
    }

    public function test_flag_on_refiller_emits_a_framework_refactor_task(): void
    {
        config([
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

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
            'app/Services/Router.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $this->invokeGenerateAndEnqueue($this->refiller(), $campaign, $target);

        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks);
        $payload = (array) json_decode((string) $tasks->first()->payload, true);
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind'] ?? null, 'flag ON + eligible => a framework refactor objective');
        $this->assertSame('framework', $payload['materializer'] ?? null);
        $this->assertTrue((bool) ($payload['acceptance']['complexity_proof'] ?? false));
    }

    public function test_real_work_supply_profile_suppresses_framework_refactor_proxy_tasks(): void
    {
        config([
            'atlas.loop.proxy_refactor_supply_enabled' => false,
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.framework_edge_gap_fallback_enabled' => false,
            'atlas.loop.generic_provider_fallback_enabled' => false,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

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
            'app/Services/Router.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $outcome = $this->invokeGenerateAndEnqueue($this->refiller(), $campaign, $target);

        $this->assertSame('quarantined', $outcome);
        $this->assertSame(0, DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->count());
    }

    public function test_self_contained_phpunit_backed_target_routes_to_framework_refactor(): void
    {
        // THE FIX: a pure-logic SELF-CONTAINED file (framework_reach=0) whose only behavior anchor
        // is a PHPUnit sibling. The Phase-1 plain-`php` synthesizer CANNOT run a PHPUnit sibling and
        // returns null; the target must then fall through to the framework materializer (worktree +
        // real ./vendor/bin/phpunit). Before the fix this produced a small provider edge-gap, never a
        // refactor — the heavy-refactor lane was structurally dead for the whole real (PHPUnit) codebase.
        config([
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.refactor_objectives_enabled' => true,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'test',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => [],
        ]);
        // framework_reach = 0 => routes to the SELF-CONTAINED branch (not the framework branch).
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id,
            'app/Services/Router.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 0, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        // Real Phase-1 self-contained synthesizer present (the true production wiring): it must
        // return null on the PHPUnit sibling, and the new fallback must then fire.
        $this->invokeGenerateAndEnqueue($this->refillerWithRealSelfContainedSynth(), $campaign, $target);

        $tasks = DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->get();
        $this->assertCount(1, $tasks, 'a self-contained PHPUnit-backed complex file yields exactly one task');
        $payload = (array) json_decode((string) $tasks->first()->payload, true);
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind'] ?? null, 'self-contained + PHPUnit sibling => framework refactor (the fix)');
        $this->assertSame('framework', $payload['materializer'] ?? null, 'routed through the framework materializer that can run PHPUnit');
        $this->assertTrue((bool) ($payload['acceptance']['complexity_proof'] ?? false));
    }

    public function test_self_contained_phpunit_backed_target_flag_off_produces_no_refactor(): void
    {
        // Default-inert: with the framework-refactor flag OFF, a self-contained PHPUnit-backed file
        // must NOT become a refactor task — it falls to the normal generator, byte-identical to today.
        config([
            'atlas.loop.framework_refactor_enabled' => false,
            'atlas.loop.refactor_objectives_enabled' => true,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

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
            'app/Services/Router.php',
            hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 0, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $this->invokeGenerateAndEnqueue($this->refillerWithRealSelfContainedSynth(), $campaign, $target);

        // Unconditional assertion (no task may be enqueued at all — the noop generator quarantines —
        // so count refactor tasks directly rather than looping, which would be a no-assertion test).
        $refactorTasks = DB::table('atlas_loop_tasks')
            ->where('campaign_id', $campaign->id)
            ->get()
            ->filter(fn ($t) => (((array) json_decode((string) $t->payload, true))['objective_kind'] ?? null) === 'refactor_reduce_complexity')
            ->count();
        $this->assertSame(0, $refactorTasks, 'flag OFF => no refactor objective even for a PHPUnit-backed self-contained file');
    }

    public function test_extract_class_mode_emits_a_two_file_objective_for_the_normal_lane(): void
    {
        // PATH B: extractClass=true reuses the SAME gates (complex+wired+sibling) but emits a 2-file
        // extract-class objective (target + a NEW <Target>Support.php) carrying structural_proof, so
        // the grinder routes it to the normal grind + cross-file structural cert (not the Obra bridge).
        config([
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();

        $out = (new AtlasLoopFrameworkRefactorSynthesizer())->synthesizeFrameworkRefactor(
            $repo,
            'app/Services/Router.php',
            ['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 2],
            '',
            'target-1',
            true, // extractClass
        );

        $this->assertIsArray($out);
        $payload = $out['payload'];
        $this->assertSame('refactor_extract_class', $payload['objective_kind']);
        $this->assertSame('framework', $payload['materializer']);
        $this->assertStringContainsString("final class Router", (string) ($payload['target_content'] ?? ''), 'extract-class payload snapshots the target content');
        $this->assertSame(['app/Services/Router.php', 'app/Services/RouterSupport.php'], $payload['allowed_files'], 'multi-file: target + the new support class');
        $this->assertSame(['app/Services/Router.php', 'app/Services/RouterSupport.php'], $payload['acceptance']['allowed_globs']);
        $this->assertTrue($payload['acceptance']['structural_proof'], 'routes the verdict to the cross-file anti-relocation gate');
        $this->assertTrue($payload['acceptance']['complexity_proof']);
        $this->assertFalse($payload['acceptance']['revert_recheck']);
        $this->assertSame(['tests/Unit/Services/RouterTest.php'], array_column($payload['frozen_tests'], 'path'), 'same frozen sibling anchor as the single-file lane');
        $this->assertStringContainsString('EXTRACTING', $out['objective']);
        $this->assertStringContainsString('RouterSupport', $out['objective']);
    }

    public function test_refiller_escalates_to_extract_class_when_lane_on_and_complex_enough(): void
    {
        config([
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
            'atlas.loop.multi_file_refactor_via_normal_lane' => true,
            'atlas.loop.extract_class_min_cyclomatic' => 8, // cyclomatic 10 >= 8 => escalate
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'test',
            'base_workspace' => $repo, 'provider' => '', 'config' => [],
        ]);
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Router.php', hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $this->invokeGenerateAndEnqueue($this->refiller(), $campaign, $target);

        $payload = (array) json_decode((string) DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->first()->payload, true);
        $this->assertSame('refactor_extract_class', $payload['objective_kind'] ?? null, 'lane ON + complex enough => multi-file extract-class objective');
        $this->assertCount(2, $payload['allowed_files'] ?? [], 'target + new support class');
        $this->assertTrue((bool) ($payload['acceptance']['quality_bar_gate'] ?? false));
        $this->assertSame(9.0, (float) ($payload['acceptance']['quality_bar'] ?? 0));
    }

    public function test_refiller_stays_single_file_below_the_extract_class_threshold(): void
    {
        config([
            'atlas.loop.framework_refactor_enabled' => true,
            'atlas.loop.framework_refactor_min_cyclomatic' => 8,
            'atlas.loop.framework_refactor_min_callers' => 1,
            'atlas.loop.multi_file_refactor_via_normal_lane' => true,
            'atlas.loop.extract_class_min_cyclomatic' => 50, // cyclomatic 10 < 50 => stays single-file
        ]);
        $repo = $this->repoWithComplexFrameworkTargetAndSibling();
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => 'running', 'goal' => 'test',
            'base_workspace' => $repo, 'provider' => '', 'config' => [],
        ]);
        $target = app(AtlasLoopTargetRepository::class)->upsert(
            $campaign->id, 'app/Services/Router.php', hash('sha256', 'x'),
            $this->scored(['cyclomatic' => 10, 'framework_reach' => 1, 'impact_real_callers' => 3]),
            ['origin' => 'discovery'],
        );

        $this->invokeGenerateAndEnqueue($this->refiller(), $campaign, $target);

        $payload = (array) json_decode((string) DB::table('atlas_loop_tasks')->where('campaign_id', $campaign->id)->first()->payload, true);
        $this->assertSame('refactor_reduce_complexity', $payload['objective_kind'] ?? null, 'below the threshold stays a single-file in-place reduction');
        $this->assertCount(1, $payload['allowed_files'] ?? []);
        $this->assertTrue((bool) ($payload['acceptance']['quality_bar_gate'] ?? false));
        $this->assertSame(9.0, (float) ($payload['acceptance']['quality_bar'] ?? 0));
    }

    /** @param array<string,mixed> $signals */
    private function scored(array $signals): array
    {
        return [
            'score' => 0.9,
            'self_contained' => 0.45,
            'improvement' => 0.5,
            'novelty' => 1.0,
            'signals' => $signals,
        ];
    }

    /**
     * Bind a provider-free generator so any fall-through to the self-contained path never spawns a
     * real provider (framework-reach targets never reach it, but keep the test hermetic).
     */
    private function refiller(): AtlasLoopQueueRefiller
    {
        $this->app->bind(AtlasEvolutionTaskGenerator::class, function () {
            $fake = new class implements LoopExecutionDriver
            {
                public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
                {
                    return ['status' => 'noop'];
                }
            };

            return new AtlasEvolutionTaskGenerator($fake);
        });

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            null,
            new AtlasLoopHarnessGuard(),
            new AtlasLoopFrameworkRefactorSynthesizer(),
        );
    }

    /**
     * The TRUE production wiring for the self-contained branch: the real Phase-1 plain-`php`
     * synthesizer is present (arg 6) AND the framework refactor synthesizer (arg 8). Proves the
     * PHPUnit-sibling fallback fires AFTER the Phase-1 synthesizer correctly returns null.
     */
    private function refillerWithRealSelfContainedSynth(): AtlasLoopQueueRefiller
    {
        $this->app->bind(AtlasEvolutionTaskGenerator::class, function () {
            $fake = new class implements LoopExecutionDriver
            {
                public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
                {
                    return ['status' => 'noop'];
                }
            };

            return new AtlasEvolutionTaskGenerator($fake);
        });

        return new AtlasLoopQueueRefiller(
            app(AtlasLoopTargetDiscoveryService::class),
            app(AtlasLoopTargetRepository::class),
            app(AtlasEvolutionTaskGenerator::class),
            app(AtlasLoopBackService::class),
            app(AtlasLoopStore::class),
            new AtlasLoopRefactorObjectiveSynthesizer(),
            new AtlasLoopHarnessGuard(),
            new AtlasLoopFrameworkRefactorSynthesizer(),
        );
    }

    private function invokeGenerateAndEnqueue(AtlasLoopQueueRefiller $refiller, $campaign, $target): string
    {
        $ref = new \ReflectionMethod($refiller, 'generateAndEnqueue');
        $ref->setAccessible(true);

        return (string) $ref->invoke($refiller, $campaign, $target, '');
    }
}
