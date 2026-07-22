<?php

namespace App\Services\Ai\Arena;

use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Executes the real Rivals stages while the drain command owns lifecycle.
 *
 * Keeping one unit per call gives the command a truthful stop boundary between
 * cases without teaching the generic Rivals runner about Arena controls.
 */
class ArenaRivalsExecutionService
{
    /** @param array{suite:string, engine:string, arms:list<string>} $group */
    public function plan(array $group): string
    {
        $armIds = array_map(
            static fn (string $arm): string => $group['engine'].'@'.($arm === 'with_atlas' ? 'atlas_dev' : 'bare'),
            $group['arms']
        );
        $out = $this->artisanJson([
            'atlas:rivals', 'plan',
            '--suite='.$group['suite'],
            '--arms='.implode(',', $armIds),
            '--repetitions='.max(1, (int) config('atlas_arena.worker_repetitions', 3)),
            '--seed=1',
            '--budget='.max(1, (int) config('atlas_arena.worker_budget_per_run', 5)),
            '--max-cases='.max(1, (int) config('atlas_arena.worker_max_cases_per_run', 10)),
            '--allow-synthetic-frozen',
            '--approve-provider-spend', '--json',
        ], 600);
        $runId = (string) ($out['run_id'] ?? '');
        if (($out['status'] ?? '') !== 'ok' || $runId === '') {
            throw new RuntimeException('arena_drain_plan_failed: '.json_encode($out['error'] ?? ($out['status'] ?? 'unknown')));
        }

        return $runId;
    }

    /** @return list<array<string,mixed>> */
    public function manifestEntries(string $runId): array
    {
        $manifestPath = RunPaths::nativeManifestPath($runId);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entries = is_array($manifest) ? array_values((array) ($manifest['entries'] ?? [])) : [];
        if ($entries === []) {
            throw new RuntimeException('arena_drain_manifest_empty');
        }

        return $entries;
    }

    /** @param array<string,mixed> $entry */
    public function runUnit(string $suite, string $runId, array $entry): int
    {
        $manifestPath = RunPaths::nativeManifestPath($runId);
        $cwd = rtrim((string) config('atlas_rivals.benchmarks.root'), '/').'/'.$suite;
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-native-runner.php'),
            '--manifest='.$manifestPath,
            '--cwd='.$cwd,
            '--execution-id='.(string) $entry['execution_id'],
            '--approve-provider-spend',
        ], base_path());
        $process->setTimeout(null);
        $process->run();

        return (int) ($process->getExitCode() ?? 1);
    }

    /** @return list<string> */
    public function finish(string $runId): array
    {
        $import = $this->artisanJson([
            'atlas:rivals', 'import-results', '--run='.$runId, '--file='.RunPaths::runDir($runId), '--json',
        ], 600);
        if (($import['status'] ?? 'ok') === 'error') {
            throw new RuntimeException('arena_drain_import_failed: '.json_encode($import['error'] ?? 'unknown'));
        }

        $warnings = [];
        foreach (['verify', 'adjudicate', 'report'] as $stage) {
            try {
                $this->artisanJson(['atlas:rivals', $stage, '--run='.$runId, '--json'], 600);
            } catch (\Throwable $e) {
                $warnings[] = "estágio {$stage} falhou: ".$e->getMessage();
            }
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function artisanJson(array $args, int $timeout): array
    {
        $process = new Process(array_merge([PHP_BINARY, base_path('artisan')], $args), base_path());
        $process->setTimeout($timeout);
        $process->run();
        $decoded = json_decode(trim((string) $process->getOutput()), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(
                'arena_drain_stage_failed: '.implode(' ', $args)
                .' :: '.mb_substr(trim((string) $process->getOutput()."\n".(string) $process->getErrorOutput()), 0, 300)
            );
        }

        return $decoded;
    }
}
