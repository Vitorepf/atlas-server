<?php

namespace App\Console\Commands;

use App\Services\Ai\Arena\ArenaMeasurementStore;
use App\Services\Ai\Arena\ArenaRivalsExecutionService;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Support\EventStream;
use Illuminate\Console\Command;
use RuntimeException;

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
        {--groups=1 : quantos grupos medição×suite×motor drenar nesta passada}
        {--approve-provider-spend : autoriza spend real de provider (obrigatório)}';

    protected $description = 'Drena a fila de medições da Arena executando o pipeline Rivals real';

    public function handle(
        ArenaMeasurementStore $store,
        ArenaRivalsExecutionService $pipeline
    ): int {
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
            $this->drainGroup($store, $pipeline, $group);
        }

        return self::SUCCESS;
    }

    /**
     * Agrupa entradas queued por medição×suite×motor: os braços enfileirados juntos
     * viram UM plan com múltiplos arms (pareamento na mesma janela → N×M).
     *
     * @return list<array{measurement_id:string, suite:string, engine:string, arms:list<string>, ids:list<string>, ids_by_arm:array<string, list<string>>}>
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
            $measurementId = (string) ($entry['measurement_id_public'] ?? 'legacy');
            if ($suite === '' || $engine === '' || $id === '' || ! in_array($arm, ['baseline', 'with_atlas'], true)) {
                continue;
            }
            $key = $measurementId.'|'.$suite.'|'.$engine;
            $groups[$key] ??= [
                'measurement_id' => $measurementId,
                'suite' => $suite,
                'engine' => $engine,
                'arms' => [],
                'ids' => [],
                'ids_by_arm' => [],
            ];
            if (! in_array($arm, $groups[$key]['arms'], true)) {
                $groups[$key]['arms'][] = $arm;
            }
            $groups[$key]['ids'][] = $id;
            $groups[$key]['ids_by_arm'][$arm][] = $id;
        }

        return array_values($groups);
    }

    /**
     * @param array{
     *   measurement_id:string,suite:string,engine:string,arms:list<string>,
     *   ids:list<string>,ids_by_arm:array<string,list<string>>
     * } $group
     */
    private function drainGroup(
        ArenaMeasurementStore $store,
        ArenaRivalsExecutionService $pipeline,
        array $group
    ): void {
        $this->info(sprintf('drenando %s × %s (%s)', $group['suite'], $group['engine'], implode(',', $group['arms'])));
        $claimed = $store->transitionQueuedRequests($group['ids'], ['queued'], [
            'status' => 'running',
            'drain_started_at' => now()->toIso8601String(),
        ]);
        if ($claimed === []) {
            return;
        }
        $group = $this->restrictGroupToClaimedIds($group, $claimed);
        $runId = null;

        try {
            if ($store->measurementHasStatus($group['measurement_id'], 'stopping')) {
                $this->acknowledgeStop($store, $group['measurement_id'], null);

                return;
            }

            try {
                $runId = $pipeline->plan($group);
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), '_runtime_unsupported')
                    || ! in_array('with_atlas', $group['arms'], true)
                    || $group['arms'] === ['with_atlas']) {
                    throw $e;
                }
                $atlasIds = $group['ids_by_arm']['with_atlas'] ?? [];
                $failedAt = now()->toIso8601String();
                $store->transitionQueuedRequests($atlasIds, ['running'], [
                    'status' => 'failed',
                    'failure_code' => 'with_atlas_runtime_unsupported',
                    'failure_reason' => 'braço com-Atlas ainda não implementado nesta suíte — baseline segue medindo',
                    'drained_at' => $failedAt,
                    'terminal_receipt_hash' => $this->terminalReceiptHash(
                        $atlasIds,
                        'failed',
                        $failedAt,
                        'with_atlas_runtime_unsupported'
                    ),
                ]);
                $this->warn(sprintf('%s: braço com-Atlas não implementado — degradando para baseline', $group['suite']));
                $group['arms'] = ['baseline'];
                $group['ids'] = $group['ids_by_arm']['baseline'] ?? [];
                $runId = $pipeline->plan($group);
            }

            $store->updateQueuedRequests($group['ids'], [
                'native_run_id' => $runId,
                'native_run_id_public' => $store->publicRunId($runId),
            ]);
            if ($this->executeUnits($store, $pipeline, $group, $runId)) {
                $this->acknowledgeStop($store, $group['measurement_id'], $runId);

                return;
            }

            foreach ($pipeline->finish($runId) as $warning) {
                $this->warn($warning);
            }
            $completedAt = now()->toIso8601String();
            $completed = $store->transitionQueuedRequests($group['ids'], ['running'], [
                'status' => 'done',
                'drained_at' => $completedAt,
                'terminal_receipt_hash' => $this->terminalReceiptHash(
                    $group['ids'],
                    'completed',
                    $completedAt
                ),
            ]);
            if (count($completed) !== count($group['ids'])
                && $store->measurementHasStatus($group['measurement_id'], 'stopping')) {
                $this->acknowledgeStop($store, $group['measurement_id'], $runId);

                return;
            }
            $this->info("done: {$runId}");
        } catch (\Throwable $e) {
            if ($store->measurementHasStatus($group['measurement_id'], 'stopping')) {
                $this->acknowledgeStop($store, $group['measurement_id'], $runId);

                return;
            }
            $failedAt = now()->toIso8601String();
            $failureCode = $this->failureCode($e);
            $store->transitionQueuedRequests($group['ids'], ['running'], [
                'status' => 'failed',
                'failure_code' => $failureCode,
                'failure_reason' => mb_substr($e->getMessage(), 0, 300),
                'drained_at' => $failedAt,
                'terminal_receipt_hash' => $this->terminalReceiptHash(
                    $group['ids'],
                    'failed',
                    $failedAt,
                    $failureCode
                ),
            ]);
            $this->error('falhou: '.$e->getMessage());
        }
    }

    /**
     * @param array{
     *   measurement_id:string,suite:string,engine:string,arms:list<string>,
     *   ids:list<string>,ids_by_arm:array<string,list<string>>
     * } $group
     */
    private function executeUnits(
        ArenaMeasurementStore $store,
        ArenaRivalsExecutionService $pipeline,
        array $group,
        string $runId
    ): bool {
        foreach ($pipeline->manifestEntries($runId) as $entry) {
            if ($store->measurementHasStatus($group['measurement_id'], 'stopping')) {
                return true;
            }
            $executionId = (string) ($entry['execution_id'] ?? '');
            if ($executionId === '') {
                continue;
            }
            EventStream::append($runId, 'unit_started', ['execution_id' => $executionId, 'source' => 'arena_drain']);
            $exitCode = $pipeline->runUnit($group['suite'], $runId, $entry);
            EventStream::append($runId, 'unit_finished', [
                'execution_id' => $executionId,
                'source' => 'arena_drain',
                'exit_code' => $exitCode,
            ]);
            if ($store->measurementHasStatus($group['measurement_id'], 'stopping')) {
                return true;
            }
        }

        return false;
    }

    private function acknowledgeStop(
        ArenaMeasurementStore $store,
        string $measurementId,
        ?string $runId
    ): void {
        if ($runId !== null) {
            $states = new RunStateMachine;
            $current = (string) (($states->current($runId)['state'] ?? ''));
            if (in_array($current, [
                RunStateMachine::PLANNED,
                RunStateMachine::PREFLIGHTED,
                RunStateMachine::NATIVE_RUNNING,
                RunStateMachine::RESULTS_IMPORTED,
                RunStateMachine::EVIDENCE_BUILT,
                RunStateMachine::VERIFIED,
            ], true)) {
                $states->cancel($runId, 'arena_operator_stop');
            }
            EventStream::append($runId, 'arena_measurement_stopped', [
                'measurement_id_public' => $measurementId,
                'source' => 'arena_drain',
            ]);
        }
        $store->acknowledgeMeasurementStop($measurementId, now()->toIso8601String());
    }

    /**
     * @param array{
     *   measurement_id:string,suite:string,engine:string,arms:list<string>,
     *   ids:list<string>,ids_by_arm:array<string,list<string>>
     * } $group
     * @param  list<string>  $claimed
     * @return array{
     *   measurement_id:string,suite:string,engine:string,arms:list<string>,
     *   ids:list<string>,ids_by_arm:array<string,list<string>>
     * }
     */
    private function restrictGroupToClaimedIds(array $group, array $claimed): array
    {
        $group['ids'] = array_values(array_intersect($group['ids'], $claimed));
        foreach ($group['ids_by_arm'] as $arm => $ids) {
            $group['ids_by_arm'][$arm] = array_values(array_intersect($ids, $claimed));
            if ($group['ids_by_arm'][$arm] === []) {
                unset($group['ids_by_arm'][$arm]);
            }
        }
        $group['arms'] = array_values(array_filter(
            $group['arms'],
            fn (string $arm): bool => isset($group['ids_by_arm'][$arm])
        ));

        return $group;
    }

    /** @param list<string> $ids */
    private function terminalReceiptHash(
        array $ids,
        string $status,
        string $at,
        ?string $failureCode = null
    ): string {
        sort($ids);

        return hash('sha256', json_encode(array_filter([
            'run_ids_public' => $ids,
            'status' => $status,
            'finished_at' => $at,
            'failure_code' => $failureCode,
        ], static fn ($value): bool => $value !== null), JSON_UNESCAPED_SLASHES));
    }

    private function failureCode(\Throwable $error): string
    {
        $message = $error->getMessage();

        return match (true) {
            str_contains($message, '_runtime_unsupported') => 'with_atlas_runtime_unsupported',
            str_contains($message, 'arena_drain_plan_') => 'plan_failed',
            str_contains($message, 'arena_drain_manifest_'),
            str_contains($message, 'native_runner') => 'native_execution_failed',
            str_contains($message, 'arena_drain_import_'),
            str_contains($message, 'arena_drain_stage_') => 'pipeline_failed',
            default => 'internal_error',
        };
    }
}
