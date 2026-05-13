<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate
{
    public function __construct(
        private readonly AgentCodexProcessSpawnExecutor $processSpawnExecutor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preparePostStartFinalProcessSpawn(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_ledger_events'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        return DB::transaction(function () use ($normalized): array {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $observedRun instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $observedRun->metadata;
            $existingExecutorId = (string) data_get($metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_executor_id', '');

            if ($existingExecutorId !== '') {
                if ($existingExecutorId !== $normalized['spawn_executor_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_final_process_spawn_executor_already_recorded');
                }

                return $this->result($observedRun, idempotent: true, processSpawnResult: null);
            }

            $this->assertPostStartSpawnEnablementReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $processSpawnResult = $this->processSpawnExecutor->prepareProcessSpawn([
                'run_key' => $providerStartRunKey,
                'codex_execution_id' => $normalized['codex_execution_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
                'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ]);

            if ((string) data_get($processSpawnResult, 'status') !== 'codex_process_spawn_executor_prepared') {
                throw new InvalidArgumentException('codex_process_spawn_executor_not_prepared');
            }

            if (! (bool) data_get($processSpawnResult, 'process_spawn_executor_prepared', false)) {
                throw new InvalidArgumentException('codex_process_spawn_executor_prepared_flag_missing');
            }

            foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($processSpawnResult, $field, false)) {
                    throw new InvalidArgumentException('codex_process_spawn_executor_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_final_process_spawn_executor'] = [
                'post_start_final_process_spawn_executor_gate_id' => $normalized['post_start_final_process_spawn_executor_gate_id'],
                'post_start_process_spawn_enablement_gate_id' => $normalized['post_start_process_spawn_enablement_gate_id'],
                'post_start_supervised_start_gate_id' => $normalized['post_start_supervised_start_gate_id'],
                'post_start_process_start_release_gate_id' => $normalized['post_start_process_start_release_gate_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'provider_execution_contract_gate_id' => $normalized['provider_execution_contract_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'adapter_execution_guard_gate_id' => $normalized['adapter_execution_guard_gate_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider_start_run_key' => $providerStartRunKey,
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
                'operator_spawn_receipt_hash' => $normalized['operator_spawn_receipt_hash'],
                'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
                'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
                'supervised_start_contract_hash' => $normalized['supervised_start_contract_hash'],
                'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_final_process_spawn_executor_prepared_pending_external_process_runtime',
                'post_start_final_process_spawn_executor_prepared' => true,
                'process_spawn_enabled' => true,
                'process_spawn_executor_prepared' => true,
                'external_process_runtime_required' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
                'codex_process_spawn_executor_result' => $processSpawnResult,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start final process spawn executor prepared; external process runtime still controls actual process execution.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, processSpawnResult: $processSpawnResult);
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
            'post_start_final_process_spawn_executor_gate_id',
            'post_start_process_spawn_enablement_gate_id',
            'post_start_supervised_start_gate_id',
            'post_start_process_start_release_gate_id',
            'process_start_release_id',
            'provider_execution_contract_gate_id',
            'codex_execution_id',
            'supervised_start_id',
            'spawn_enablement_id',
            'spawn_executor_id',
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'operator_release_receipt_hash',
            'operator_spawn_receipt_hash',
            'operator_final_spawn_receipt_hash',
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
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
        foreach (['signed_dispatch_receipt_hash', 'operator_release_receipt_hash', 'operator_spawn_receipt_hash', 'operator_final_spawn_receipt_hash', 'codex_execution_contract_hash', 'supervised_start_contract_hash', 'runtime_supervision_plan_hash', 'stdout_stderr_sink_hash', 'liveness_probe_hash'] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'post_start_final_process_spawn_executor_gate_id' => (string) $input['post_start_final_process_spawn_executor_gate_id'],
            'post_start_process_spawn_enablement_gate_id' => (string) $input['post_start_process_spawn_enablement_gate_id'],
            'post_start_supervised_start_gate_id' => (string) $input['post_start_supervised_start_gate_id'],
            'post_start_process_start_release_gate_id' => (string) $input['post_start_process_start_release_gate_id'],
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'provider_execution_contract_gate_id' => (string) $input['provider_execution_contract_gate_id'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'supervised_start_id' => (string) $input['supervised_start_id'],
            'spawn_enablement_id' => (string) $input['spawn_enablement_id'],
            'spawn_executor_id' => (string) $input['spawn_executor_id'],
            'adapter_execution_guard_gate_id' => (string) $input['adapter_execution_guard_gate_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'signed_dispatch_receipt_hash' => $hashes['signed_dispatch_receipt_hash'],
            'operator_release_receipt_hash' => $hashes['operator_release_receipt_hash'],
            'operator_spawn_receipt_hash' => $hashes['operator_spawn_receipt_hash'],
            'operator_final_spawn_receipt_hash' => $hashes['operator_final_spawn_receipt_hash'],
            'codex_execution_contract_hash' => $hashes['codex_execution_contract_hash'],
            'supervised_start_contract_hash' => $hashes['supervised_start_contract_hash'],
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
    private function assertPostStartSpawnEnablementReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_process_spawn_enablement.';

        foreach ([
            'post_start_process_spawn_enablement_gate_id',
            'post_start_supervised_start_gate_id',
            'post_start_process_start_release_gate_id',
            'process_start_release_id',
            'provider_execution_contract_gate_id',
            'codex_execution_id',
            'supervised_start_id',
            'spawn_enablement_id',
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'operator_release_receipt_hash',
            'operator_spawn_receipt_hash',
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
        ] as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_process_spawn_enabled_pending_final_process_spawn_executor') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_process_spawn_enablement_not_ready_for_final_executor');
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'adapter_execution_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['post_start_process_spawn_enablement_recorded', 'post_start_supervised_start_prepared', 'process_spawn_enabled', 'final_process_spawn_executor_required', 'observed_external_process_started', 'observed_provider_started'] as $field) {
            if (! (bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $processSpawnResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $processSpawnResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_final_process_spawn_executor_prepared',
            'idempotent' => $idempotent,
            'post_start_final_process_spawn_executor_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_final_process_spawn_executor_gate_id'),
            'post_start_process_spawn_enablement_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_process_spawn_enablement_gate_id'),
            'process_start_release_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.process_start_release_id'),
            'supervised_start_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.supervised_start_id'),
            'spawn_enablement_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_enablement_id'),
            'spawn_executor_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.spawn_executor_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.codex_execution_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_final_process_spawn_executor.post_start_evidence_acceptance_bridge_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'post_start_final_process_spawn_executor_prepared' => true,
            'process_spawn_enabled' => true,
            'process_spawn_executor_prepared' => true,
            'external_process_runtime_required' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'dispatch_allowed' => false,
            'codex_process_spawn_executor_result' => $processSpawnResult,
        ];
    }
}
