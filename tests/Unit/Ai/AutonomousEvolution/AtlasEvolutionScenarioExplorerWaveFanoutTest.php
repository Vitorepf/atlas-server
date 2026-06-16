<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcherContract;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * item6_fanout — the bounded PARALLEL WAVE fold logic, proven WITHOUT subprocesses by injecting a
 * FAKE ScenarioWaveDispatcherContract that returns canned attempts in index order. These tests run
 * the flag-ON path (the serial frozen suite covers the flag-OFF path). The fake stands in for the
 * real Process::start fan-out so the wave bookkeeping (fold order / winner / wave-level guidance /
 * convergence-at-wave-boundary) is unit-testable.
 */
final class AtlasEvolutionScenarioExplorerWaveFanoutTest extends TestCase
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
        Config::set('atlas.loop.scenario_fanout.enabled', true);
        Config::set('atlas.loop.scenario_fanout.width', 2);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->base]))->run();
        parent::tearDown();
    }

    private function task(): array
    {
        return [
            'objective' => 'Make greet() return hello so the test passes. Only edit src/.',
            'base_workspace' => $this->base,
            'provider' => 'test_provider',
            'allowed_files' => ['src/Subject.php'],
            'validation_commands' => ['php tests/subject_test.php'],
            'acceptance' => [
                'commands' => ['php tests/subject_test.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE,
            ],
        ];
    }

    /** A passing attempt with a controllable diff size (more lines = bigger diff). */
    private function passAttempt(int $index, int $lines = 1, string $provider = 'test_provider'): array
    {
        return [
            'scenario_id' => 'scn-'.($index + 1),
            'strategy_key' => 'k'.$index,
            'strategy' => 's'.$index,
            'provider' => $provider,
            'loop_status' => 'completed',
            'cost_estimate_usd' => null,
            'tokens_used' => null,
            'verdict' => ['passed' => true, 'metric' => 1.0, 'details' => ['reason' => 'ok']],
            'diff_size' => ['files' => 1, 'lines' => $lines],
            'diff_text' => 'diff scn-'.($index + 1),
            'workspace' => null,
            'error' => null,
        ];
    }

    /** A failing attempt carrying a failure reason (drives the ledger's do-not-repeat digest). */
    private function failAttempt(int $index, string $reason = 'no_winner'): array
    {
        return [
            'scenario_id' => 'scn-'.($index + 1),
            'strategy_key' => 'k'.$index,
            'strategy' => 's'.$index,
            'provider' => 'test_provider',
            'loop_status' => 'completed',
            'cost_estimate_usd' => null,
            'tokens_used' => null,
            'verdict' => ['passed' => false, 'metric' => 0.0, 'details' => ['reason' => $reason]],
            'diff_size' => ['files' => 1, 'lines' => 5],
            'diff_text' => '',
            'workspace' => null,
            'error' => null,
        ];
    }

    /** The explorer never calls the real driver on the wave path (the dispatcher owns execution). */
    private function unusedDriver(): LoopExecutionDriver
    {
        return new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                throw new \RuntimeException('the wave path must never invoke the in-process driver');
            }
        };
    }

    public function test_wave_assembles_attempts_in_scn_order_and_picks_the_same_winner(): void
    {
        // A canned dispatcher: per wave, return one attempt per spec IN INDEX ORDER. Wave 0 = scn-1
        // (big diff) + scn-2 (small diff, the winner by the smaller-diff tie-break).
        $dispatcher = new class($this) implements ScenarioWaveDispatcherContract
        {
            public function __construct(private readonly AtlasEvolutionScenarioExplorerWaveFanoutTest $t) {}

            /** @var list<list<int>> */
            public array $dispatchedIndexes = [];

            public function dispatch(array $specs): array
            {
                $this->dispatchedIndexes[] = array_map(static fn (array $s): int => (int) $s['index'], $specs);
                $out = [];
                foreach ($specs as $spec) {
                    $idx = (int) $spec['index'];
                    // scn-2 (idx 1) is the minimal-diff winner; the rest are bigger passing diffs.
                    $out[] = $this->t->exposePassAttempt($idx, $idx === 1 ? 1 : 9);
                }

                return $out;
            }
        };

        $explorer = new AtlasEvolutionScenarioExplorer($this->unusedDriver(), new AtlasEvolutionFrozenJudge, null, $dispatcher);
        $result = $explorer->explore($this->task(), 2);

        // One wave of width 2 covers both scenarios.
        $this->assertSame([[0, 1]], $dispatcher->dispatchedIndexes);
        // Attempts assembled in scn order.
        $this->assertSame(['scn-1', 'scn-2'], array_map(static fn (array $a): string => (string) $a['scenario_id'], $result['attempts']));
        $this->assertSame(2, $result['scenarios_explored']);
        $this->assertSame(2, $result['scenarios_accepted']);
        // Winner = the smaller-diff scn-2, exactly what the serial pickWinner would choose.
        $this->assertNotNull($result['winner']);
        $this->assertSame('scn-2', $result['winner']['scenario_id']);
    }

    public function test_second_wave_specs_carry_the_prior_waves_do_not_repeat_guidance(): void
    {
        // Wave 0: both scenarios FAIL with a distinct reason. Wave 1: the dispatcher records the
        // strategy_text it received and proves it now carries the ledger's "do NOT repeat" digest.
        $dispatcher = new class($this) implements ScenarioWaveDispatcherContract
        {
            public function __construct(private readonly AtlasEvolutionScenarioExplorerWaveFanoutTest $t) {}

            public int $wave = 0;

            /** @var list<list<string>> strategy_text seen per wave */
            public array $seenStrategyText = [];

            public function dispatch(array $specs): array
            {
                $this->seenStrategyText[] = array_map(static fn (array $s): string => (string) $s['strategy_text'], $specs);
                $out = [];
                foreach ($specs as $spec) {
                    $idx = (int) $spec['index'];
                    // Always fail so the search runs to the cap and a second wave happens.
                    $out[] = $this->t->exposeFailAttempt($idx, 'reason_'.$idx);
                }
                $this->wave++;

                return $out;
            }
        };

        $task = $this->task();
        $task['min_scenarios'] = 2;
        $task['max_scenarios'] = 4;
        $task['search_patience'] = 3;

        $explorer = new AtlasEvolutionScenarioExplorer($this->unusedDriver(), new AtlasEvolutionFrozenJudge, null, $dispatcher);
        $result = $explorer->explore($task, null);

        // 4 scenarios across two width-2 waves; no winner ever passes.
        $this->assertSame(4, $result['scenarios_explored']);
        $this->assertNull($result['winner']);
        $this->assertGreaterThanOrEqual(2, count($dispatcher->seenStrategyText));

        // Wave 0 specs carry NO guidance (no prior settled attempts yet).
        foreach ($dispatcher->seenStrategyText[0] as $text) {
            $this->assertStringNotContainsString('do NOT repeat', $text);
        }
        // Wave 1 specs carry the prior wave's failures as the do-not-repeat digest.
        foreach ($dispatcher->seenStrategyText[1] as $text) {
            $this->assertStringContainsString('do NOT repeat', $text);
            $this->assertStringContainsString('FAILED', $text);
        }
    }

    public function test_convergence_stops_at_a_wave_boundary(): void
    {
        // Every scenario passes with an identical minimal diff => no improvement after the first =>
        // the search converges and STOPS at a wave boundary once best!=null && noImprove>=patience.
        $dispatcher = new class($this) implements ScenarioWaveDispatcherContract
        {
            public function __construct(private readonly AtlasEvolutionScenarioExplorerWaveFanoutTest $t) {}

            public int $waves = 0;

            public function dispatch(array $specs): array
            {
                $this->waves++;
                $out = [];
                foreach ($specs as $spec) {
                    $out[] = $this->t->exposePassAttempt((int) $spec['index'], 1); // identical diff => ties => no improve
                }

                return $out;
            }
        };

        Config::set('atlas.loop.scenario_fanout.width', 2);
        $task = $this->task();
        $task['min_scenarios'] = 2;
        $task['max_scenarios'] = 12;
        $task['search_patience'] = 3;

        $explorer = new AtlasEvolutionScenarioExplorer($this->unusedDriver(), new AtlasEvolutionFrozenJudge, null, $dispatcher);
        $result = $explorer->explore($task, null);

        // First attempt wins; the next 3 tie (noImprove). With width 2 the boundary check fires after
        // a whole wave, so it converges at 4 scenarios (1 winner + 3 non-improving >= patience 3).
        $this->assertSame(4, $result['scenarios_explored']);
        $this->assertTrue($result['status']['converged']);
        $this->assertNotNull($result['winner']);
        $this->assertLessThan(12, $result['scenarios_explored'], 'must stop early, not run to the cap');
    }

    // Bridge helpers so the anonymous dispatchers can build canned attempts (private builders above).
    public function exposePassAttempt(int $index, int $lines): array
    {
        return $this->passAttempt($index, $lines);
    }

    public function exposeFailAttempt(int $index, string $reason): array
    {
        return $this->failAttempt($index, $reason);
    }
}
