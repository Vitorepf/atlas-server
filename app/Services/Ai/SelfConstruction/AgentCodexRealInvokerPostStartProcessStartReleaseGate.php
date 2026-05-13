<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartProcessStartReleaseGate
{
    public function __construct(
        private readonly AgentCodexProcessStartReleaseGate $processStartRelease,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizePostStartProcessStartRelease(array $input): array
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
            $existingReleaseId = (string) data_get($metadata, 'codex_real_invoker_post_start_process_start_release.process_start_release_id', '');

            if ($existingReleaseId !== '') {
                if ($existingReleaseId !== $normalized['process_start_release_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_process_start_release_already_recorded');
                }

                return $this->result($observedRun, idempotent: true, releaseResult: null);
            }

            $this->assertProviderExecutionContractReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $releaseResult = $this->processStartRelease->authorizeCodexProcessStart([
                'run_key' => $providerStartRunKey,
                'codex_execution_id' => $normalized['codex_execution_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
                'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ]);

            if ((string) data_get($releaseResult, 'status') !== 'codex_process_start_release_authorized') {
                throw new InvalidArgumentException('codex_process_start_release_not_authorized');
            }

            foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($releaseResult, $field, false)) {
                    throw new InvalidArgumentException('codex_process_start_release_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_process_start_release'] = [
                'post_start_process_start_release_gate_id' => $normalized['post_start_process_start_release_gate_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'provider_execution_contract_gate_id' => $normalized['provider_execution_contract_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'adapter_execution_guard_gate_id' => $normalized['adapter_execution_guard_gate_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider_start_run_key' => $providerStartRunKey,
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
                'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_process_start_release_authorized_pending_supervised_start_executor',
                'post_start_process_start_release_authorized' => true,
                'post_start_provider_execution_contract_prepared' => true,
                'process_start_release_authorized' => true,
                'supervised_start_executor_required' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
                'codex_process_start_release_result' => $releaseResult,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start process start release authorized; supervised executor still controls actual start.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, releaseResult: $releaseResult);
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
            'post_start_process_start_release_gate_id',
            'process_start_release_id',
            'provider_execution_contract_gate_id',
            'codex_execution_id',
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'signed_dispatch_receipt_hash',
            'operator_release_receipt_hash',
            'codex_execution_contract_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [
            'signed_dispatch_receipt_hash' => strtolower(trim((string) $input['signed_dispatch_receipt_hash'])),
            'operator_release_receipt_hash' => strtolower(trim((string) $input['operator_release_receipt_hash'])),
            'codex_execution_contract_hash' => strtolower(trim((string) $input['codex_execution_contract_hash'])),
        ];

        foreach ($hashes as $field => $value) {
            if (! preg_match('/^[a-f0-9]{64}$/', $value)) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'post_start_process_start_release_gate_id' => (string) $input['post_start_process_start_release_gate_id'],
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'provider_execution_contract_gate_id' => (string) $input['provider_execution_contract_gate_id'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'adapter_execution_guard_gate_id' => (string) $input['adapter_execution_guard_gate_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'signed_dispatch_receipt_hash' => $hashes['signed_dispatch_receipt_hash'],
            'operator_release_receipt_hash' => $hashes['operator_release_receipt_hash'],
            'codex_execution_contract_hash' => $hashes['codex_execution_contract_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertProviderExecutionContractReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_provider_execution_contract.';

        if ((string) data_get($metadata, $prefix.'provider_execution_contract_gate_id') !== $normalized['provider_execution_contract_gate_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_provider_execution_contract_missing_or_mismatch');
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_codex_provider_execution_contract_prepared_pending_process_start_release') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_provider_execution_contract_not_ready_for_process_start_release');
        }

        foreach ([
            'codex_execution_id',
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'signed_dispatch_receipt_hash',
        ] as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $prefix.'codex_provider_execution_result.status') !== 'codex_provider_execution_prepared') {
            throw new InvalidArgumentException('codex_provider_execution_result_not_prepared');
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'adapter_execution_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['post_start_provider_execution_contract_prepared', 'post_start_adapter_execution_guard_recorded', 'provider_specific_execution_contract_ready', 'observed_external_process_started', 'observed_provider_started'] as $field) {
            if (! (bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $releaseResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $releaseResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_process_start_release_authorized',
            'idempotent' => $idempotent,
            'post_start_process_start_release_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_process_start_release.post_start_process_start_release_gate_id'),
            'process_start_release_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_process_start_release.process_start_release_id'),
            'provider_execution_contract_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_process_start_release.provider_execution_contract_gate_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_process_start_release.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'process_start_release_authorized' => true,
            'supervised_start_executor_required' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'dispatch_allowed' => false,
            'codex_process_start_release_result' => $releaseResult,
        ];
    }
}
