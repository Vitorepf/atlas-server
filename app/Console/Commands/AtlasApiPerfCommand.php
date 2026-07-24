<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * O6 · reproducible latency harness for the terminal product surface: measures wall-clock
 * p50/p95 of every runnable catalog entry (fresh `php artisan` process per run — what the
 * operator actually feels), against the shared 3s p50 budget. Read-only; side-effectful
 * catalog entries are excluded by the same `run` gate the conformance check uses.
 */
class AtlasApiPerfCommand extends Command
{
    use EmitsCanonicalJson;

    public const P50_BUDGET_MS = 3000;

    protected $signature = 'atlas:api:perf
        {--runs=5 : Execuções por comando (p50/p95 sobre elas)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Mede p50/p95 de latência dos comandos-núcleo do catálogo (processo fresco por run; orçamento p50 3s).';

    public function handle(): int
    {
        $runs = max(2, (int) $this->option('runs'));
        $results = [];

        foreach (AtlasApiDescribeCommand::CATALOG as $entry) {
            if ($entry['run'] === null) {
                continue;
            }
            [$command, $params] = $entry['run'];
            $argv = $this->argv($command, $params);

            $times = [];
            $failed = false;
            for ($i = 0; $i < $runs; $i++) {
                $ms = $this->timeOne($argv);
                if ($ms === null) {
                    $failed = true;
                    break;
                }
                $times[] = $ms;
            }

            sort($times);
            $p50 = $failed ? null : $this->percentile($times, 50);
            $results[] = [
                'area' => $entry['area'],
                'command' => implode(' ', array_slice($argv, 1)),
                'runs' => count($times),
                'p50_ms' => $p50,
                'p95_ms' => $failed ? null : $this->percentile($times, 95),
                'within_budget' => $failed ? false : ($p50 <= self::P50_BUDGET_MS),
                'failed' => $failed,
            ];
        }

        $payload = [
            'schema_version' => 'atlas.api.perf.v1',
            'p50_budget_ms' => self::P50_BUDGET_MS,
            'runs_per_command' => $runs,
            'over_budget' => array_values(array_map(
                static fn (array $r): string => $r['area'],
                array_filter($results, static fn (array $r): bool => ! $r['within_budget']),
            )),
            'results' => $results,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->table(
                ['area', 'p50 ms', 'p95 ms', 'orçamento'],
                array_map(static fn (array $r): array => [
                    $r['area'],
                    $r['p50_ms'] !== null ? (string) $r['p50_ms'] : 'FALHOU',
                    $r['p95_ms'] !== null ? (string) $r['p95_ms'] : '-',
                    $r['within_budget'] ? 'OK' : 'ESTOUROU',
                ], $results),
            );
        }

        return $payload['over_budget'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return list<string>
     */
    private function argv(string $command, array $params): array
    {
        $argv = [PHP_BINARY, 'artisan', $command];
        foreach ($params as $key => $value) {
            if (str_starts_with((string) $key, '--')) {
                $argv[] = $value === true ? (string) $key : $key.'='.$value;
            } else {
                $argv[] = (string) $value;
            }
        }

        return $argv;
    }

    /**
     * @param  list<string>  $argv
     */
    private function timeOne(array $argv): ?int
    {
        try {
            $process = new Process($argv, base_path());
            $process->setTimeout(120);
            $started = hrtime(true);
            $process->run();
            if ($process->getExitCode() !== 0) {
                return null;
            }

            return (int) ((hrtime(true) - $started) / 1_000_000);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, int $p): ?int
    {
        if ($sorted === []) {
            return null;
        }
        $index = (int) ceil(count($sorted) * $p / 100) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }
}
