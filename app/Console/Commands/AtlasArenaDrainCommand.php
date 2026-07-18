<?php

namespace App\Console\Commands;

use App\Services\Ai\Arena\ArenaMeasurementStore;
use App\Services\Ai\Rivals\Support\EventStream;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * atlas:arena:drain — o worker de medição da Arena (fecha M61/A12).
 *
 * Drena a fila pública (arena/queued_runs.jsonl) executando o pipeline
 * Rivals REAL: plan → rivals-native-runner por unidade → import-results →
 * verify/adjudicate/report. Nunca simula progresso: cada unidade é um
 * proc_open real do motor; falha de estágio marca a entrada como failed
 * com motivo. Mesmo trilho dos drains da casa (routes/console.php —
 * schedule stop-when-empty, sem worker residente).
 */
class AtlasArenaDrainCommand extends Command
{
    protected $signature = 'atlas:arena:drain
        {--groups=1 : quantos grupos suite×motor drenar nesta passada}
        {--approve-provider-spend : autoriza spend real de provider (obrigatório)}';

    protected $description = 'Drena a fila de medições da Arena executando o pipeline Rivals real';

    public function handle(ArenaMeasurementStore $store): int
    {
        if (! (bool) config('atlas_arena.worker_enabled', false)) {
            $this->info('arena worker desabilitado (ATLAS_ARENA_WORKER_ENABLED)');

            return self::SUCCESS;
        }
        if (! $this->option('approve-provider-spend')) {
            $this->error('execução real exige --approve-provider-spend');

            return self::FAILURE;
        }

        $groups = $this->queuedGroups($store);
        if ($groups === []) {
            $this->info('fila seca');

            return self::SUCCESS;
        }

        foreach (array_slice($groups, 0, max(1, (int) $this->option('groups'))) as $group) {
            $this->drainGroup($store, $group);
        }

        return self::SUCCESS;
    }

    /**
     * Agrupa entradas queued por suite×motor: os braços enfileirados juntos
     * viram UM plan com múltiplos arms (pareamento na mesma janela → N×M).
     *
     * @return list<array{suite:string, engine:string, arms:list<string>, ids:list<string>, ids_by_arm:array<string, list<string>>}>
     */
    private function queuedGroups(ArenaMeasurementStore $store): array
    {
        $groups = [];
        foreach ($store->queuedRequests() as $entry) {
            if (($entry['status'] ?? null) !== 'queued') {
                continue;
            }
            $suite = (string) ($entry['suite'] ?? '');
            $engine = (string) ($entry['engine'] ?? '');
            $arm = (string) ($entry['arm'] ?? '');
            $id = (string) ($entry['run_id_public'] ?? '');
            if ($suite === '' || $engine === '' || $id === '' || ! in_array($arm, ['baseline', 'with_atlas'], true)) {
                continue;
            }
            $key = $suite.'|'.$engine;
            $groups[$key] ??= ['suite' => $suite, 'engine' => $engine, 'arms' => [], 'ids' => [], 'ids_by_arm' => []];
            if (! in_array($arm, $groups[$key]['arms'], true)) {
                $groups[$key]['arms'][] = $arm;
            }
            $groups[$key]['ids'][] = $id;
            $groups[$key]['ids_by_arm'][$arm][] = $id;
        }

        return array_values($groups);
    }

    /** @param array{suite:string, engine:string, arms:list<string>, ids:list<string>} $group */
    private function drainGroup(ArenaMeasurementStore $store, array $group): void
    {
        $this->info(sprintf('drenando %s × %s (%s)', $group['suite'], $group['engine'], implode(',', $group['arms'])));
        $store->updateQueuedRequests($group['ids'], [
            'status' => 'running',
            'drain_started_at' => now()->toIso8601String(),
        ]);

        try {
            try {
                $runId = $this->plan($group);
            } catch (RuntimeException $e) {
                // Suíte sem braço com-Atlas implementado: degradar DITO, nunca
                // matar o grupo — o braço vira failed com motivo humano e o
                // baseline mede. Sem isso, 1 braço faltante zerava a suíte
                // inteira (era a "Rivals não funciona" do operador).
                if (! str_contains($e->getMessage(), '_runtime_unsupported')
                    || ! in_array('with_atlas', $group['arms'], true)
                    || $group['arms'] === ['with_atlas']) {
                    throw $e;
                }
                $atlasIds = $group['ids_by_arm']['with_atlas'] ?? [];
                $store->updateQueuedRequests($atlasIds, [
                    'status' => 'failed',
                    'failure_reason' => 'braço com-Atlas ainda não implementado nesta suíte — baseline segue medindo',
                    'drained_at' => now()->toIso8601String(),
                ]);
                $this->warn(sprintf('%s: braço com-Atlas não implementado — degradando para baseline', $group['suite']));
                $group['arms'] = ['baseline'];
                $group['ids'] = $group['ids_by_arm']['baseline'] ?? [];
                $runId = $this->plan($group);
            }
            $store->updateQueuedRequests($group['ids'], ['native_run_id_public' => $store->publicRunId($runId)]);
            $this->executeUnits($group['suite'], $runId);
            $this->finishPipeline($runId);
            $store->updateQueuedRequests($group['ids'], [
                'status' => 'done',
                'drained_at' => now()->toIso8601String(),
            ]);
            $this->info("done: {$runId}");
        } catch (\Throwable $e) {
            $store->updateQueuedRequests($group['ids'], [
                'status' => 'failed',
                'failure_reason' => mb_substr($e->getMessage(), 0, 300),
                'drained_at' => now()->toIso8601String(),
            ]);
            $this->error('falhou: '.$e->getMessage());
        }
    }

    /** @param array{suite:string, engine:string, arms:list<string>, ids:list<string>} $group */
    private function plan(array $group): string
    {
        // baseline→bare · with_atlas→atlas_dev (espelho de ArenaMeasurementStore::publicArm).
        $armIds = array_map(
            static fn (string $arm): string => $group['engine'].'@'.($arm === 'with_atlas' ? 'atlas_dev' : 'bare'),
            $group['arms']
        );
        $budget = max(1, (int) config('atlas_arena.worker_budget_per_run', 5));
        $maxCases = max(1, (int) config('atlas_arena.worker_max_cases_per_run', 10));
        $out = $this->artisanJson([
            'atlas:rivals', 'plan',
            '--suite='.$group['suite'],
            '--arms='.implode(',', $armIds),
            '--repetitions=1', '--seed=1',
            '--budget='.$budget,
            '--max-cases='.$maxCases,
            // Suites da Arena são todas externas (sem snapshot git por case):
            // mesmo trilho da battery (FrozenUnitManifest allowSynthetic).
            '--allow-synthetic-frozen',
            '--approve-provider-spend', '--json',
        ], 600);
        $runId = (string) ($out['run_id'] ?? '');
        if (($out['status'] ?? '') !== 'ok' || $runId === '') {
            throw new RuntimeException('arena_drain_plan_failed: '.json_encode($out['error'] ?? ($out['status'] ?? 'unknown')));
        }

        return $runId;
    }

    private function executeUnits(string $suite, string $runId): void
    {
        $manifestPath = RunPaths::nativeManifestPath($runId);
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $entries = is_array($manifest) ? (array) ($manifest['entries'] ?? []) : [];
        if ($entries === []) {
            throw new RuntimeException('arena_drain_manifest_empty');
        }
        $cwd = rtrim((string) config('atlas_rivals.benchmarks.root'), '/').'/'.$suite;
        foreach ($entries as $entry) {
            $executionId = (string) ($entry['execution_id'] ?? '');
            if ($executionId === '') {
                continue;
            }
            EventStream::append($runId, 'unit_started', ['execution_id' => $executionId, 'source' => 'arena_drain']);
            $process = new Process([
                PHP_BINARY,
                base_path('scripts/rivals-native-runner.php'),
                '--manifest='.$manifestPath,
                '--cwd='.$cwd,
                '--execution-id='.$executionId,
                '--approve-provider-spend',
            ], base_path());
            // Timeout por unidade é do runner (max_seconds do manifest).
            $process->setTimeout(null);
            $process->run();
            EventStream::append($runId, 'unit_finished', [
                'execution_id' => $executionId,
                'source' => 'arena_drain',
                'exit_code' => $process->getExitCode(),
            ]);
        }
    }

    private function finishPipeline(string $runId): void
    {
        $import = $this->artisanJson([
            'atlas:rivals', 'import-results', '--run='.$runId, '--file='.RunPaths::runDir($runId), '--json',
        ], 600);
        if (($import['status'] ?? 'ok') === 'error') {
            throw new RuntimeException('arena_drain_import_failed: '.json_encode($import['error'] ?? 'unknown'));
        }
        // Claim pipeline é desejável mas não bloqueia o scoreboard (receipts já
        // ingeridos); falha aqui vira warning, nunca medição perdida.
        foreach (['verify', 'adjudicate', 'report'] as $stage) {
            try {
                $this->artisanJson(['atlas:rivals', $stage, '--run='.$runId, '--json'], 600);
            } catch (\Throwable $e) {
                $this->warn("estágio {$stage} falhou: ".$e->getMessage());
            }
        }
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
            // Artisan pode renderizar exceção no stdout com exit 0 — sem JSON
            // é falha, e o motivo real (stdout+stderr) vai no erro.
            throw new RuntimeException(
                'arena_drain_stage_failed: '.implode(' ', $args)
                .' :: '.mb_substr(trim((string) $process->getOutput()."\n".(string) $process->getErrorOutput()), 0, 300)
            );
        }

        return $decoded;
    }
}
