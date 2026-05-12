<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexProcessSpawnExecutor
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareProcessSpawn(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', self::LEDGER_TABLE] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        return DB::transaction(function () use ($normalized): array {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $run->metadata;
            $existingExecutorId = (string) data_get($metadata, 'codex_process_spawn_executor.spawn_executor_id', '');

            if ($existingExecutorId !== '') {
                if ($existingExecutorId !== $normalized['spawn_executor_id']) {
                    throw new InvalidArgumentException('codex_process_spawn_executor_already_prepared');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertSpawnEnablementRecorded($run, $metadata, $normalized);

            $metadata['codex_process_spawn_executor'] = [
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
                'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'prepared_pending_external_process_runtime',
                'process_spawn_executor_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex process spawn executor prepared; external process runtime remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_process_spawn.executor_prepared',
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
                'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'process_spawn_executor_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_process_spawn_executor',
                'receipt_id' => $normalized['spawn_executor_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-process-spawn-executor.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'run_key',
            'codex_execution_id',
            'process_start_release_id',
            'supervised_start_id',
            'spawn_enablement_id',
            'spawn_executor_id',
            'operator_final_spawn_receipt_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [];
        foreach (['operator_final_spawn_receipt_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash'] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'supervised_start_id' => (string) $input['supervised_start_id'],
            'spawn_enablement_id' => (string) $input['spawn_enablement_id'],
            'spawn_executor_id' => (string) $input['spawn_executor_id'],
            'operator_final_spawn_receipt_hash' => $hashes['operator_final_spawn_receipt_hash'],
            'runtime_supervision_plan_hash' => $hashes['runtime_supervision_plan_hash'],
            'stdout_stderr_sink_hash' => $hashes['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => $hashes['liveness_probe_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertSpawnEnablementRecorded(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_process_spawn_enablement.spawn_enablement_id') !== $normalized['spawn_enablement_id']) {
            throw new InvalidArgumentException('codex_process_spawn_enablement_missing_or_mismatch');
        }

        if ((string) data_get($metadata, 'codex_process_spawn_enablement.supervised_start_id') !== $normalized['supervised_start_id']) {
            throw new InvalidArgumentException('codex_supervised_start_id_mismatch');
        }

        if ((string) data_get($metadata, 'codex_process_spawn_enablement.process_start_release_id') !== $normalized['process_start_release_id']) {
            throw new InvalidArgumentException('codex_process_start_release_id_mismatch');
        }

        if ((string) data_get($metadata, 'codex_process_spawn_enablement.codex_execution_id') !== $normalized['codex_execution_id']) {
            throw new InvalidArgumentException('codex_execution_id_mismatch');
        }

        if ((string) data_get($metadata, 'codex_process_spawn_enablement.status') !== 'enabled_pending_final_process_spawn_executor') {
            throw new InvalidArgumentException('codex_process_spawn_enablement_not_ready_for_executor');
        }

        if (! (bool) data_get($metadata, 'codex_process_spawn_enablement.process_spawn_enabled', false)) {
            throw new InvalidArgumentException('codex_process_spawn_not_enabled');
        }

        foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_process_spawn_enablement.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_process_spawn_executor_prepared',
            'idempotent' => $idempotent,
            'spawn_executor_id' => (string) data_get($run->metadata, 'codex_process_spawn_executor.spawn_executor_id'),
            'spawn_enablement_id' => (string) data_get($run->metadata, 'codex_process_spawn_executor.spawn_enablement_id'),
            'supervised_start_id' => (string) data_get($run->metadata, 'codex_process_spawn_executor.supervised_start_id'),
            'process_start_release_id' => (string) data_get($run->metadata, 'codex_process_spawn_executor.process_start_release_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_process_spawn_executor.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_process_spawn_executor.adapter'),
            'process_spawn_executor_prepared' => true,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
