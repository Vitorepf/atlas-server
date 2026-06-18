<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMetricHarness;
use Tests\TestCase;

/**
 * UNIT 2.2 — the dev/test held-out metric harness (mirrors Arbor's eval.php scalar harness).
 *
 * Frozen behaviour: the optimizer may only ever optimize the DEV command; the TEST command
 * is held out for the certifier. evaluate() reads the split-specific command/pattern, runs it
 * via an injected runner, and extracts the scalar exactly like AtlasEvolutionFrozenJudge::computeMetric.
 */
class AtlasLoopMetricHarnessTest extends TestCase
{
    /**
     * Build a harness whose runner returns canned {stdout, exit} per command — no real process,
     * no real git. The map is keyed by the exact command string.
     *
     * @param  array<string, array{stdout: string, exit: int}>  $byCommand
     */
    private function harness(array $byCommand): AtlasLoopMetricHarness
    {
        return new AtlasLoopMetricHarness(
            function (string $command, string $workspace, int $timeout) use ($byCommand): array {
                return $byCommand[$command] ?? ['stdout' => '', 'exit' => 127];
            }
        );
    }

    private function heldOut(array $overrides = []): array
    {
        return array_merge([
            'dev_command' => 'php eval.php --split dev',
            'dev_pattern' => '/score:\s*([0-9.]+)/',
            'test_command' => 'php eval.php --split test',
            'test_pattern' => '/score:\s*([0-9.]+)/',
            'metric_kind' => 'maximize',
        ], $overrides);
    }

    public function test_maximize_dev_extracts_scalar_finite(): void
    {
        $h = $this->harness([
            'php eval.php --split dev' => ['stdout' => 'score: 0.84', 'exit' => 0],
        ]);

        $r = $h->evaluate('/ws', $this->heldOut(), 'dev');

        $this->assertSame(0.84, $r['metric']);
        $this->assertTrue($r['metric_finite']);
        $this->assertSame('php eval.php --split dev', $r['command']);
        $this->assertSame('score: 0.84', $r['raw']);
    }

    public function test_dev_and_test_read_from_different_keys(): void
    {
        $h = $this->harness([
            'php eval.php --split dev' => ['stdout' => 'score: 0.84', 'exit' => 0],
            'php eval.php --split test' => ['stdout' => 'score: 0.71', 'exit' => 0],
        ]);

        $dev = $h->evaluate('/ws', $this->heldOut(), 'dev');
        $test = $h->evaluate('/ws', $this->heldOut(), 'test');

        // Proves the split routes to the right command/pattern keys — dev never leaks test.
        $this->assertSame(0.84, $dev['metric']);
        $this->assertSame('php eval.php --split dev', $dev['command']);
        $this->assertSame(0.71, $test['metric']);
        $this->assertSame('php eval.php --split test', $test['command']);
    }

    public function test_pattern_not_found_clamps_worst_and_not_finite_maximize(): void
    {
        $h = $this->harness([
            'php eval.php --split dev' => ['stdout' => 'no number here', 'exit' => 0],
        ]);

        $r = $h->evaluate('/ws', $this->heldOut(['metric_kind' => 'maximize']), 'dev');

        $this->assertFalse($r['metric_finite']);
        // maximize worst-case clamp mirrors computeMetric, materialized as -1e308 (finite, not -INF).
        $this->assertSame(-1.0e308, $r['metric']);
    }

    public function test_pattern_not_found_clamps_worst_and_not_finite_minimize(): void
    {
        $h = $this->harness([
            'php eval.php --split test' => ['stdout' => 'garbage', 'exit' => 0],
        ]);

        $r = $h->evaluate('/ws', $this->heldOut(['metric_kind' => 'minimize']), 'test');

        $this->assertFalse($r['metric_finite']);
        $this->assertSame(1.0e308, $r['metric']);
    }

    public function test_gate_exit_zero_is_one_exit_one_is_zero(): void
    {
        $hPass = $this->harness([
            'php eval.php --split dev' => ['stdout' => 'whatever', 'exit' => 0],
        ]);
        $hFail = $this->harness([
            'php eval.php --split dev' => ['stdout' => 'whatever', 'exit' => 1],
        ]);

        $pass = $hPass->evaluate('/ws', $this->heldOut(['metric_kind' => 'gate']), 'dev');
        $fail = $hFail->evaluate('/ws', $this->heldOut(['metric_kind' => 'gate']), 'dev');

        $this->assertSame(1.0, $pass['metric']);
        $this->assertTrue($pass['metric_finite']);
        $this->assertSame(0.0, $fail['metric']);
        $this->assertTrue($fail['metric_finite']);
    }

    public function test_improvement_maximize_is_candidate_minus_baseline(): void
    {
        $h = $this->harness([]);

        $delta = $h->improvement(0.73, 0.84, 'maximize');

        $this->assertEqualsWithDelta(0.11, $delta, 1e-9);
        $this->assertGreaterThan(0.0, $delta, 'positive must mean better for maximize');
    }

    public function test_improvement_minimize_is_baseline_minus_candidate(): void
    {
        $h = $this->harness([]);

        $delta = $h->improvement(10.0, 8.0, 'minimize');

        $this->assertSame(2.0, $delta);
        $this->assertGreaterThan(0.0, $delta, 'positive must mean better for minimize');
    }

    public function test_improvement_gate_is_candidate_minus_baseline(): void
    {
        $h = $this->harness([]);

        // gate: 0.0 -> 1.0 is a +1.0 improvement.
        $this->assertSame(1.0, $h->improvement(0.0, 1.0, 'gate'));
        // regression gate: 1.0 -> 0.0 is -1.0.
        $this->assertSame(-1.0, $h->improvement(1.0, 0.0, 'gate'));
    }

    public function test_is_armed_true_only_when_both_commands_present(): void
    {
        $this->assertTrue(AtlasLoopMetricHarness::isArmed([
            'held_out' => [
                'dev_command' => 'php eval.php --split dev',
                'test_command' => 'php eval.php --split test',
            ],
        ]));

        $this->assertFalse(AtlasLoopMetricHarness::isArmed([
            'held_out' => [
                'dev_command' => 'php eval.php --split dev',
                'test_command' => '',
            ],
        ]));

        $this->assertFalse(AtlasLoopMetricHarness::isArmed([
            'held_out' => [
                'test_command' => 'php eval.php --split test',
            ],
        ]));

        $this->assertFalse(AtlasLoopMetricHarness::isArmed([]));
    }
}
