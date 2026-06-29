<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBenchmarkHarness;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopBenchmarkHarness::summarize()} at the operator surface: reads benchmark
 * timing samples from a JSON file and emits the deterministic summary shape (n, median, IQR, stddev, min, max)
 * as facts.
 *
 * Read-only + pure: it reduces the supplied samples — no measurement, provider, DB, or mutation. An empty
 * sample set is refused (summarize has no defined statistics over zero samples).
 */
final class AtlasLoopBenchmarkSummarizeCommand extends Command
{
    protected $signature = 'atlas:loop:benchmark-summarize {--input=} {--json}';

    protected $description = 'Read-only summary statistics (median/IQR/stddev/min/max) over benchmark samples.';

    public function handle(): int
    {
        $input = trim((string) $this->option('input'));
        if ($input === '' || ! is_file($input) || ! is_readable($input)) {
            return $this->refuse('benchmark-summarize requires --input=<path to a readable samples JSON>');
        }

        $decoded = json_decode((string) file_get_contents($input), true);
        if (! is_array($decoded)) {
            return $this->refuse('input file is not a JSON array/object');
        }
        $raw = isset($decoded['samples']) && is_array($decoded['samples']) ? $decoded['samples'] : $decoded;
        $samples = array_values(array_map(static fn ($v): float => (float) $v, array_filter($raw, 'is_numeric')));
        if ($samples === []) {
            return $this->refuse('no numeric samples found');
        }

        $summary = app(AtlasLoopBenchmarkHarness::class)->summarize($samples);

        $facts = ['schema' => 'atlas.loop.benchmark_summary.v1'] + $summary;

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('n: '.$facts['n'].'  median: '.$facts['median_ns'].'  iqr: '.$facts['iqr_ns']);
            $this->line('min: '.$facts['min_ns'].'  max: '.$facts['max_ns'].'  stddev: '.$facts['stddev_ns']);
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
