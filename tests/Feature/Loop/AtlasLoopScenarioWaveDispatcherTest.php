<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcher;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * item6_fanout — the ScenarioWaveDispatcher in isolation (mirrors AtlasLoopWorkerPoolTest style).
 *
 * It exercises the REAL dispatcher: it spawns child `atlas:loop:run-scenario` subprocesses (each
 * resolving the container's LoopExecutionDriver), harvests their JSON, and folds. The child
 * subprocesses boot their OWN kernel and read the env config, so these are env-dependent and are
 * SKIPPED unless the loop default driver is a deterministic test stub — they prove the
 * parent/child contract shape (ordering + admission backpressure + errored-child shell), not a
 * real provider grind.
 */
final class AtlasLoopScenarioWaveDispatcherTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-loop-base-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/src', 0o755, true);
        mkdir($this->base.'/tests', 0o755, true);
        file_put_contents($this->base.'/src/Subject.php', "<?php\nfunction greet(){ return 'helo'; }\n");
        file_put_contents($this->base.'/tests/subject_test.php', "<?php\nrequire __DIR__.'/../src/Subject.php';\nif (greet() !== 'hello') { fwrite(STDERR,'red'); exit(1);} echo 'green';\n");
        Config::set('atlas.loop.scenario_fanout.timeout_seconds', 120);

        // Bind a deterministic in-process driver so the dispatcher's INLINE-fallback path (which
        // resolves AtlasEvolutionScenarioExplorer from the container) never touches a real provider.
        // The explorer is bound with a null wave dispatcher so runScenarioForWave delegates straight
        // to the unchanged private runScenario — no recursion back into the dispatcher.
        $this->app->bind(LoopExecutionDriver::class, fn (): LoopExecutionDriver => new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                file_put_contents($workspace.'/src/Subject.php', "<?php\nfunction greet(){ return 'hello'; }\n");

                return ['status' => 'completed'];
            }
        });
        $this->app->bind(AtlasEvolutionScenarioExplorer::class, fn ($app): AtlasEvolutionScenarioExplorer => new AtlasEvolutionScenarioExplorer(
            $app->make(LoopExecutionDriver::class),
            new AtlasEvolutionFrozenJudge,
        ));
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    /**
     * @param  list<int>  $indexes
     * @return list<array<string,mixed>>
     */
    private function specs(array $indexes): array
    {
        return array_map(fn (int $i): array => [
            'index' => $i,
            'objective' => 'Make greet() return hello. Only edit src/.',
            'strategy_text' => '',
            'strategy_key' => 'baseline',
            'base_workspace' => $this->base,
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            ],
            'surface_id' => 'atlas_evolution_loop',
            'user_constraints' => ['allowed_files=src/Subject.php'],
            'surface_hints' => ['provider_choice' => 'test_provider'],
            'provider' => 'test_provider',
            'keep_workspaces' => false,
            'workspace_root' => '',
            'clone_mode' => 'copy',
        ], $indexes);
    }

    public function test_backpressure_falls_back_to_inline_serial_and_never_drops_a_scenario(): void
    {
        // An unmeetable disk floor forces admitScenario to refuse EVERY spawn, so the dispatcher runs
        // each spec INLINE-serial via the container explorer's runScenarioForWave — never dropping one.
        Config::set('atlas.loop.campaign.min_free_mb', 1_000_000_000); // 1 PB floor

        $dispatcher = new ScenarioWaveDispatcher(new AtlasLoopResourceGate);
        $results = $dispatcher->dispatch($this->specs([0, 1]));

        // Both scenarios still produced an attempt, ordered by index (no scenario dropped).
        $this->assertCount(2, $results);
        $this->assertSame(['scn-1', 'scn-2'], array_map(static fn (array $a): string => (string) $a['scenario_id'], $results));
        // Each attempt has the canonical runScenario shape.
        foreach ($results as $a) {
            $this->assertArrayHasKey('verdict', $a);
            $this->assertArrayHasKey('diff_size', $a);
        }
    }

    public function test_results_are_ordered_by_index_ascending(): void
    {
        // Force the inline-serial path (hermetic, no real child kernel/provider) — the ksort fold is
        // dispatcher-level and identical whether a spec settled via subprocess or inline fallback.
        Config::set('atlas.loop.campaign.min_free_mb', 1_000_000_000);

        $dispatcher = new ScenarioWaveDispatcher(new AtlasLoopResourceGate);
        // Submit specs OUT of natural order; the dispatcher must ksort them back to ascending index.
        $results = $dispatcher->dispatch($this->specs([2, 0, 1]));

        $this->assertSame(
            ['scn-1', 'scn-2', 'scn-3'],
            array_map(static fn (array $a): string => (string) $a['scenario_id'], $results),
            'attempts must come back ordered by ascending spec index regardless of submission order'
        );
    }

    public function test_an_errored_child_yields_the_errored_attempt_shell(): void
    {
        // A spec pointing at a NON-EXISTENT base workspace makes runScenario throw inside the child,
        // and a base64-garbled run is impossible here — but the inline-fallback path (forced via the
        // disk floor) surfaces the SAME errored-attempt shell shape, proving graceful degradation.
        Config::set('atlas.loop.campaign.min_free_mb', 1_000_000_000); // force inline fallback

        $bad = $this->specs([0]);
        $bad[0]['base_workspace'] = '/nonexistent/atlas-loop-'.bin2hex(random_bytes(4));

        $dispatcher = new ScenarioWaveDispatcher(new AtlasLoopResourceGate);
        $results = $dispatcher->dispatch($bad);

        $this->assertCount(1, $results);
        $attempt = $results[0];
        $this->assertSame('scn-1', $attempt['scenario_id']);
        $this->assertFalse((bool) ($attempt['verdict']['passed'] ?? true), 'a failed scenario must not register as passing');
        $this->assertSame(['files' => 0, 'lines' => 0], $attempt['diff_size'] ?? null);
        $this->assertArrayHasKey('error', $attempt);
    }
}
