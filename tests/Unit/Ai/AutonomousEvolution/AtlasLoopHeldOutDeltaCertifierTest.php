<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHeldOutDeltaCertifier;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMetricHarness;
use PHPUnit\Framework\TestCase;

/**
 * The HELD-OUT delta certifier is the anti-gaming moat of the optimize lane: the optimizer may only
 * ever touch the DEV split; certification proves the gain on the FROZEN TEST split, candidate-vs-baseline,
 * fail-CLOSED. These frozen tests drive the REAL {@see AtlasLoopMetricHarness} with a fake $runner (so
 * NO real provider, git, or process is needed) and pin: (a) a real test-split gain certifies;
 * (b) no movement / a worse candidate / a non-finite metric / a stash failure all FAIL closed with a
 * precise reason; (c) the certifier runs ONLY the held-out 'test' command, never 'dev'; (d) the worktree
 * is always restored (stash pop) even on failure.
 */
final class AtlasLoopHeldOutDeltaCertifierTest extends TestCase
{
    private string $workspace = '/tmp/atlas-heldout-fake';

    /**
     * Build the REAL harness with a fake runner that returns $candidateStdout on the FIRST test-command
     * run (candidate, diff live) and $baselineStdout on the SECOND (baseline, after stash). The dev
     * command, if ever run, emits a POISON number — so any read of the dev split would corrupt the
     * delta and fail the test by construction.
     *
     * @param  list<string>  $commandLog  captures every command the runner sees, for split-read assertions
     */
    private function harness(string $candidateStdout, string $baselineStdout, array &$commandLog): AtlasLoopMetricHarness
    {
        $testRuns = 0;

        return new AtlasLoopMetricHarness(function (string $command, string $workspace, int $timeout) use (&$testRuns, &$commandLog, $candidateStdout, $baselineStdout): array {
            $commandLog[] = $command;
            if (str_contains($command, 'DEV-POISON')) {
                return ['stdout' => 'score: 9.99', 'exit' => 0];
            }
            $testRuns++;

            return ['stdout' => $testRuns === 1 ? $candidateStdout : $baselineStdout, 'exit' => 0];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function acceptance(string $kind = 'maximize', bool $armed = true): array
    {
        return [
            'commands' => ['echo train'],
            'held_out' => [
                'dev_command' => $armed ? 'echo DEV-POISON' : '',
                'dev_pattern' => '/score:\s*([0-9.]+)/',
                'test_command' => $armed ? 'echo TEST' : '',
                'test_pattern' => '/score:\s*([0-9.]+)/',
                'metric_kind' => $kind,
                'baseline_metric' => 0.73,
            ],
        ];
    }

    /** A git closure that succeeds: stash push captures a diff, list reports it, pop restores. */
    private function workingGit(array &$log): \Closure
    {
        $stashed = false;

        return function (array $args, string $workspace) use (&$log, &$stashed): array {
            $log[] = implode(' ', $args);
            $sub = $args[2] ?? ''; // ['git','stash','<sub>',...]
            if ($sub === 'push') {
                $stashed = true;

                return ['stdout' => '', 'exit' => 0];
            }
            if ($sub === 'list') {
                return ['stdout' => $stashed ? "stash@{0}: WIP\n" : '', 'exit' => 0];
            }
            if ($sub === 'pop') {
                $stashed = false;

                return ['stdout' => '', 'exit' => 0];
            }

            return ['stdout' => '', 'exit' => 0];
        };
    }

    public function test_armed_candidate_beats_baseline_on_held_out_split_certifies(): void
    {
        $cmdLog = [];
        $gitLog = [];
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('score: 0.84', 'score: 0.73', $cmdLog), $this->workingGit($gitLog));

        $r = $cert->certifyHeldOut($this->workspace, $this->acceptance());

        $this->assertTrue($r['armed']);
        $this->assertTrue($r['improved'], $r['reason'] ?? 'no reason');
        $this->assertEqualsWithDelta(0.73, $r['baseline'], 1e-9);
        $this->assertEqualsWithDelta(0.84, $r['candidate'], 1e-9);
        $this->assertEqualsWithDelta(0.11, $r['improvement'], 1e-9);
        $this->assertNull($r['reason']);
    }

    public function test_no_movement_fails_closed_with_no_delta_reason(): void
    {
        $cmdLog = [];
        $gitLog = [];
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('score: 0.73', 'score: 0.73', $cmdLog), $this->workingGit($gitLog));

        $r = $cert->certifyHeldOut($this->workspace, $this->acceptance());

        $this->assertTrue($r['armed']);
        $this->assertFalse($r['improved']);
        $this->assertSame('held_out:no_delta', $r['reason']);
    }

    public function test_worse_candidate_fails_closed(): void
    {
        // baseline 0.84, candidate 0.70 (maximize) -> improvement negative -> not improved.
        $cmdLog = [];
        $gitLog = [];
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('score: 0.70', 'score: 0.84', $cmdLog), $this->workingGit($gitLog));

        $r = $cert->certifyHeldOut($this->workspace, $this->acceptance());

        $this->assertTrue($r['armed']);
        $this->assertFalse($r['improved']);
        $this->assertEqualsWithDelta(-0.14, $r['improvement'], 1e-9);
    }

    public function test_not_armed_does_not_gate_and_is_byte_identical_off(): void
    {
        $cmdLog = [];
        $gitLog = [];
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('score: 0.84', 'score: 0.73', $cmdLog), $this->workingGit($gitLog));

        // No held-out commands => harness disarmed => no gating, byte-identical-off.
        $r = $cert->certifyHeldOut($this->workspace, $this->acceptance(armed: false));

        $this->assertFalse($r['armed']);
        $this->assertNull($r['reason']);
        $this->assertFalse($r['improved']);
        // It never measured anything nor touched git when disarmed.
        $this->assertSame([], $cmdLog);
        $this->assertSame([], $gitLog);
    }

    public function test_certifier_runs_only_the_test_split_never_the_dev_split(): void
    {
        $cmdLog = [];
        $gitLog = [];
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('score: 0.84', 'score: 0.73', $cmdLog), $this->workingGit($gitLog));

        $cert->certifyHeldOut($this->workspace, $this->acceptance());

        // The held-out 'test' command was run exactly twice (candidate + baseline); the DEV-POISON
        // command was NEVER run. A read of the dev split would have poisoned the delta to 9.99.
        $this->assertSame(['echo TEST', 'echo TEST'], $cmdLog);
        $this->assertNotContains('echo DEV-POISON', $cmdLog);
    }

    public function test_non_finite_candidate_metric_fails_closed(): void
    {
        // A passing run whose stdout does NOT match the pattern is metric_finite=false -> fail closed.
        $cmdLog = [];
        $gitLog = [];
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('no number here', 'score: 0.73', $cmdLog), $this->workingGit($gitLog));

        $r = $cert->certifyHeldOut($this->workspace, $this->acceptance());

        $this->assertFalse($r['improved']);
        $this->assertSame('held_out:metric_non_finite', $r['reason']);
        // It failed BEFORE stashing, so the worktree was never touched.
        $this->assertSame([], $gitLog);
    }

    public function test_stash_failure_fails_closed_and_still_attempts_restore(): void
    {
        $cmdLog = [];
        $gitLog = [];
        // git stash push "succeeds" but list reports NO entry (nothing captured) -> cannot isolate baseline.
        $git = function (array $args, string $workspace) use (&$gitLog): array {
            $gitLog[] = implode(' ', $args);
            $sub = $args[2] ?? ''; // ['git','stash','<sub>',...]
            if ($sub === 'list') {
                return ['stdout' => '', 'exit' => 0]; // never created -> stash failed
            }

            return ['stdout' => '', 'exit' => 0];
        };
        $cert = new AtlasLoopHeldOutDeltaCertifier($this->harness('score: 0.84', 'score: 0.73', $cmdLog), $git);

        $r = $cert->certifyHeldOut($this->workspace, $this->acceptance());

        $this->assertFalse($r['improved']);
        $this->assertSame('held_out:stash_failed', $r['reason']);
        // Restore (pop) must still be attempted even though isolation failed.
        $this->assertContains('git stash pop --quiet', $gitLog);
    }
}
