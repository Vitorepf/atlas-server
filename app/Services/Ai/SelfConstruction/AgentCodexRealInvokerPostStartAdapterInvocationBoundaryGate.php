<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate
{
    public function __construct(
        private readonly AgentDispatchExecutorAdapterInvocationBoundary $adapterInvocationBoundary,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preparePostStartAdapterInvocationBoundary(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_self_construction_agent_heartbeats', 'atlas_ledger_events'] as $table) {
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
            $existingBoundaryId = (string) data_get($metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary.adapter_invocation_id', '');

            if ($existingBoundaryId !== '') {
                if ($existingBoundaryId !== $normalized['adapter_invocation_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_adapter_invocation_boundary_already_prepared');
                }

                return $this->result($observedRun, idempotent: true, boundaryResult: null);
            }

            $this->assertProviderStartDriverReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $boundaryResult = $this->adapterInvocationBoundary->prepareInvocation([
                'run_key' => $providerStartRunKey,
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'command' => $normalized['command'],
                'cwd' => $normalized['cwd'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'max_runtime_minutes' => $normalized['max_runtime_minutes'],
                'max_cost_usd' => $normalized['max_cost_usd'],
                'reason' => $normalized['reason'],
            ]);

            foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($boundaryResult, $field, false)) {
                    throw new InvalidArgumentException('adapter_invocation_boundary_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_adapter_invocation_boundary'] = [
                'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider_start_run_key' => $providerStartRunKey,
                'dispatch_executor_handoff_id' => $normalized['dispatch_executor_handoff_id'],
                'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'command_hash' => hash('sha256', $normalized['command']),
                'cwd_hash' => hash('sha256', $normalized['cwd']),
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_adapter_invocation_boundary_prepared_pending_adapter_execution_guard',
                'post_start_adapter_invocation_boundary_prepared' => true,
                'post_start_provider_start_driver_prepared' => true,
                'dispatch_receipt_used' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'boundary_external_process_started' => false,
                'boundary_provider_started' => false,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'dispatch_allowed' => false,
                'adapter_invocation_boundary_result' => $boundaryResult,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start adapter invocation boundary prepared; adapter execution remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, boundaryResult: $boundaryResult);
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
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'signed_dispatch_receipt_hash',
            'command',
            'cwd',
            'context_pack_hash',
            'continuation_summary_hash',
            'actor',
            'session',
            'max_runtime_minutes',
            'max_cost_usd',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [];
        foreach (['signed_dispatch_receipt_hash', 'context_pack_hash', 'continuation_summary_hash'] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));
            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $runtimeMinutes = (int) $input['max_runtime_minutes'];
        $maxCost = (float) $input['max_cost_usd'];

        if ($runtimeMinutes < 1) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ($maxCost < 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return array_merge([
            'run_key' => (string) $input['run_key'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'command' => (string) $input['command'],
            'cwd' => (string) $input['cwd'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => $runtimeMinutes,
            'max_cost_usd' => $maxCost,
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertProviderStartDriverReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_provider_start_driver.';

        if ((string) data_get($metadata, $prefix.'provider_start_driver_gate_id') !== $normalized['provider_start_driver_gate_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_provider_start_driver_missing_or_mismatch');
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_provider_start_driver_prepared_pending_adapter_invocation_boundary') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_provider_start_driver_not_ready_for_adapter_invocation_boundary');
        }

        foreach ([
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'signed_dispatch_receipt_hash',
        ] as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'dispatch_allowed', 'driver_provider_started'] as $field) {
            if ((bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['post_start_provider_start_driver_prepared', 'dispatch_receipt_used', 'observed_external_process_started', 'observed_provider_started'] as $field) {
            if (! (bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $boundaryResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $boundaryResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_adapter_invocation_boundary_prepared',
            'idempotent' => $idempotent,
            'adapter_invocation_boundary_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary.adapter_invocation_boundary_gate_id'),
            'adapter_invocation_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary.adapter_invocation_id'),
            'provider_start_driver_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary.provider_start_driver_gate_id'),
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_invocation_boundary.provider_start_attempt_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'boundary_external_process_started' => false,
            'boundary_provider_started' => false,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_invocation_boundary_result' => $boundaryResult,
        ];
    }
}
