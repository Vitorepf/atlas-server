<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * The smallest REAL performance harness for optimize-kind tasks: run a command
 * N times, report min/median wall time in a stable JSON envelope. The brain
 * runs it at ORIGINATION time (baseline goes into the design spec's
 * expected_delta / packet metadata); the worker or the operator runs it again
 * after the delivery — an "optimization" with no measured delta is the
 * no_measurable_improvement flag with a number attached.
 *
 * ponytail: wall-clock medians on the local machine, not a lab bench; enough
 * to catch "did not optimize anything", not micro-benchmark science.
 */
class AtlasRefactorBenchCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:refactor:bench
        {cmd : the shell command to measure (quoted)}
        {--runs=5 : how many timed runs (first warm-up run is discarded)}
        {--timeout=300 : per-run timeout in seconds}
        {--label= : optional label echoed in the envelope (e.g. before|after)}
        {--json : machine-readable output}';

    protected $description = 'Measure a command N times (median wall time) — the before/after evidence an optimize task owes.';

    public function handle(): int
    {
        $cmd = trim((string) $this->argument('cmd'));
        if ($cmd === '') {
            $this->line(json_encode(['schema' => 'atlas.refactor.bench.v1', 'status' => 'usage_error', 'reason' => 'cmd_required']));

            return self::FAILURE;
        }
        $runs = max(1, min(25, (int) $this->option('runs')));
        $timeout = max(1.0, (float) $this->option('timeout'));

        $samplesMs = [];
        $failed = null;
        // +1 discarded warm-up run: cold caches poison the first sample.
        for ($i = 0; $i <= $runs; $i++) {
            $p = Process::fromShellCommandline($cmd, base_path(), null, null, $timeout);
            $start = hrtime(true);
            $p->run();
            $elapsedMs = (hrtime(true) - $start) / 1e6;
            if (! $p->isSuccessful()) {
                $failed = ['exit_code' => (int) $p->getExitCode(), 'stderr_excerpt' => mb_substr($p->getErrorOutput(), 0, 300)];
                break;
            }
            if ($i > 0) {
                $samplesMs[] = round($elapsedMs, 2);
            }
        }

        $envelope = [
            'schema' => 'atlas.refactor.bench.v1',
            'status' => $failed !== null ? 'command_failed' : 'ok',
            'label' => trim((string) $this->option('label')),
            'cmd' => $cmd,
            'runs' => count($samplesMs),
            'samples_ms' => $samplesMs,
            'median_ms' => $samplesMs !== [] ? $this->median($samplesMs) : null,
            'min_ms' => $samplesMs !== [] ? min($samplesMs) : null,
            'failed' => $failed,
        ];
        $this->line($this->encode($envelope));

        return $failed === null ? self::SUCCESS : self::FAILURE;
    }

    /** @param non-empty-list<float> $samples */
    private function median(array $samples): float
    {
        sort($samples);
        $n = count($samples);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $samples[$mid] : round(($samples[$mid - 1] + $samples[$mid]) / 2, 2);
    }
}
