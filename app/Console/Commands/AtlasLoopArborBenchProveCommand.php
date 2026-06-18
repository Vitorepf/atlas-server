<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIterateToMetricOptimizer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMetricHarness;
use App\Services\Ai\AutonomousEvolution\WorkspaceProviderLoopExecutionDriver;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * PROVE (B) — the loop's iterate-to-metric engine beats Arbor AUTONOMOUSLY, on Arbor's own bench.
 *
 * The loop optimizes rebase.sh against the DEV split only (the optimizer never sees the test split),
 * starting from a WEAK baseline (default e63bd25 = strict-apply ~0.31). After convergence the HELD-OUT
 * (test) split is measured candidate-vs-baseline. (B) is won when the loop, with NO babysitting and NO
 * gaming (held-out proves it), reaches >= Arbor's documented held-out delivery (cycle1 test = 0.732).
 *
 * NOTE on measurement: the bench's committed HEAD is already Arbor-optimized (~0.97 test), so the
 * production HeldOutDeltaCertifier's git-stash-to-HEAD baseline does NOT fit here. This command measures
 * the held-out delta EXPLICITLY (weak-baseline ref vs the loop's candidate) via the same proven harness,
 * keeping the anti-gaming property intact (optimizer is fed the dev command only).
 */
final class AtlasLoopArborBenchProveCommand extends Command
{
    protected $signature = 'atlas:loop:arbor-bench-prove
        {--bench=/private/tmp/arbor-loop-bench : bench directory (git repo with eval.sh + rebase.sh)}
        {--provider=hermes_cli : provider key the loop edits with (hermes_cli => codex-5.5)}
        {--max-edits=6 : iterate-to-metric edit budget}
        {--patience=2 : stop after this many non-improving edits}
        {--baseline-ref=e63bd25 : git ref of the WEAK rebase.sh the loop must earn the delta from}
        {--keep-candidate : do not restore the bench rebase.sh after measuring (leave the loop candidate)}';

    protected $description = 'PROVE (B): the loop iterate-to-metric optimizer beats Arbor autonomously on the Arbor bench (optimize dev, certify held-out test). No babysitting.';

    private const ARBOR_HELDOUT_TEST = 0.732; // Arbor cycle1 documented held-out (commit aaefce0)

    private const PATTERN = '/score:\s*([0-9.]+)/';

    public function handle(
        WorkspaceProviderLoopExecutionDriver $driver,
        AtlasLoopIterateToMetricOptimizer $optimizer,
        AtlasLoopMetricHarness $harness,
    ): int {
        $bench = rtrim((string) $this->option('bench'), '/');
        $provider = trim((string) $this->option('provider'));
        $maxEdits = max(1, (int) $this->option('max-edits'));
        $patience = max(1, (int) $this->option('patience'));
        $baselineRef = trim((string) $this->option('baseline-ref'));

        if (! is_dir($bench) || ! is_file($bench.'/eval.sh') || ! is_file($bench.'/rebase.sh')) {
            $this->error("bench not found / incomplete at {$bench}");

            return self::FAILURE;
        }

        $heldOut = [
            'dev_command' => 'bash eval.sh dev',
            'dev_pattern' => self::PATTERN,
            'test_command' => 'bash eval.sh test',
            'test_pattern' => self::PATTERN,
            'metric_kind' => AtlasLoopMetricHarness::METRIC_MAXIMIZE,
            'timeout_seconds' => 1800,
        ];

        // 1. Reset rebase.sh to the WEAK baseline so the loop must EARN every point. Stash any current
        //    working-tree rebase.sh first (so we never destroy uncommitted state silently).
        $this->git($bench, ['stash', 'push', '--', 'rebase.sh']);
        $this->git($bench, ['checkout', $baselineRef, '--', 'rebase.sh']);
        $this->line("reset rebase.sh -> {$baselineRef} (weak baseline)");

        // 2. Baseline DEV + TEST scalars (the floor the loop starts from).
        $baselineDev = $harness->evaluate($bench, $heldOut, 'dev');
        $baselineTest = $harness->evaluate($bench, $heldOut, 'test');
        $this->line("baseline  dev={$baselineDev['metric']}  test(held-out)={$baselineTest['metric']}");

        // 3. AUTONOMOUS iterate-to-metric on the DEV split only. The optimizer edits rebase.sh in place
        //    via the loop's REAL provider driver, measuring `bash eval.sh dev` each round.
        $measure = function () use ($harness, $bench, $heldOut): array {
            $r = $harness->evaluate($bench, $heldOut, 'dev');

            return ['passed' => $r['metric_finite'], 'metric' => $r['metric'], 'metric_finite' => $r['metric_finite']];
        };

        $edit = function (int $round, ?array $last) use ($driver, $bench, $provider): array {
            $score = $last['metric'] ?? null;
            $intent = 'Improve ONLY rebase.sh to raise the landing rate measured by `bash eval.sh dev`.'
                .($score !== null ? " Current dev score: {$score}. Push it strictly higher with a genuinely different strategy." : '')
                .' Edit ONLY rebase.sh. NEVER touch eval.php, eval.sh, or corpus_*.json. The result MUST stay a valid bash script (bash -n passes).';
            $res = $driver->attempt(
                'atlas_evolution_loop',
                $bench,
                $intent,
                ['allowed_files=rebase.sh', 'validation_command=bash eval.sh dev'],
                ['provider_choice' => $provider],
            );

            return [
                'diff_text' => (string) ($res['diff'] ?? ''),
                'diff_size' => count((array) ($res['changed_files'] ?? [])),
                'cost_cents' => (int) ($res['cost_cents'] ?? 0),
                'tokens' => (int) ($res['tokens'] ?? 0),
                'provider_called' => (bool) ($res['provider_called'] ?? $res['provider_invoked'] ?? false),
            ];
        };

        $this->line("optimizing autonomously: max {$maxEdits} edits, patience {$patience}, provider {$provider} ...");
        $result = $optimizer->optimize($edit, $measure, AtlasLoopMetricHarness::METRIC_MAXIMIZE, null, $maxEdits, $patience);

        // 4. Candidate DEV + TEST scalars (the loop's autonomously-earned version, still in the tree).
        $candidateDev = $harness->evaluate($bench, $heldOut, 'dev');
        $candidateTest = $harness->evaluate($bench, $heldOut, 'test');

        $devGain = $harness->improvement($baselineDev['metric'], $candidateDev['metric'], AtlasLoopMetricHarness::METRIC_MAXIMIZE);
        $testGain = $harness->improvement($baselineTest['metric'], $candidateTest['metric'], AtlasLoopMetricHarness::METRIC_MAXIMIZE);

        // (B): held-out improved over its own weak baseline AND reached Arbor's documented held-out bar.
        $beatArbor = $testGain > 0 && $candidateTest['metric'] >= self::ARBOR_HELDOUT_TEST;

        // 5. Restore the bench unless asked to keep the loop's candidate.
        if (! (bool) $this->option('keep-candidate')) {
            $this->git($bench, ['checkout', 'HEAD', '--', 'rebase.sh']);
            $this->git($bench, ['stash', 'pop']);
        }

        $report = [
            'schema' => 'atlas.loop.arbor_bench_prove.v1',
            'provider' => $provider,
            'baseline_ref' => $baselineRef,
            'rounds' => $result['rounds'],
            'converged' => $result['converged'],
            'dev' => ['baseline' => $baselineDev['metric'], 'candidate' => $candidateDev['metric'], 'gain' => $devGain],
            'held_out_test' => ['baseline' => $baselineTest['metric'], 'candidate' => $candidateTest['metric'], 'gain' => $testGain],
            'arbor_reference_held_out_test' => self::ARBOR_HELDOUT_TEST,
            'history' => $result['history'],
            'total_cost_cents' => $result['total_cost_cents'],
            'B_beat_arbor_autonomous_ungamed' => $beatArbor,
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $bench, array $args): void
    {
        $p = new Process(array_merge(['git'], $args), $bench);
        $p->setTimeout(120)->run();
    }
}
