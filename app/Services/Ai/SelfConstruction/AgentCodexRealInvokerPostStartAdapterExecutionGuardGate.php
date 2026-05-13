<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartAdapterExecutionGuardGate
{
    public function __construct(
        private readonly AgentProviderAdapterExecutionGuard $adapterExecutionGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function blockPostStartAdapterExecution(array $input): array
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
            $existingGuardId = (string) data_get($metadata, 'codex_real_invoker_post_start_adapter_execution_guard.execution_guard_id', '');

            if ($existingGuardId !== '') {
                if ($existingGuardId !== $normalized['execution_guard_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_adapter_execution_guard_already_recorded');
                }

                return $this->result($observedRun, idempotent: true, guardResult: null);
            }

            $this->assertAdapterInvocationBoundaryReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $guardResult = $this->adapterExecutionGuard->blockUntilProviderSpecificContract([
                'run_key' => $providerStartRunKey,
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ]);

            if ((string) data_get($guardResult, 'status') !== 'provider_adapter_execution_blocked') {
                throw new InvalidArgumentException('provider_adapter_execution_guard_not_blocked');
            }

            foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($guardResult, $field, false)) {
                    throw new InvalidArgumentException('provider_adapter_execution_guard_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_adapter_execution_guard'] = [
                'adapter_execution_guard_gate_id' => $normalized['adapter_execution_guard_gate_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider_start_run_key' => $providerStartRunKey,
                'dispatch_executor_handoff_id' => $normalized['dispatch_executor_handoff_id'],
                'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_adapter_execution_blocked_pending_codex_provider_execution_contract',
                'post_start_adapter_execution_guard_recorded' => true,
                'post_start_adapter_invocation_boundary_prepared' => true,
                'post_start_provider_start_driver_prepared' => true,
                'dispatch_receipt_used' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_specific_execution_contract_required' => true,
                'provider_adapter_execution_guard_result' => $guardResult,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start adapter execution guard recorded; provider-specific execution remains blocked.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, guardResult: $guardResult);
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
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $receiptHash = strtolower(trim((string) $input['signed_dispatch_receipt_hash']));
        if (! preg_match('/^[a-f0-9]{64}$/', $receiptHash)) {
            throw new InvalidArgumentException('invalid_signed_dispatch_receipt_hash');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'adapter_execution_guard_gate_id' => (string) $input['adapter_execution_guard_gate_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'signed_dispatch_receipt_hash' => $receiptHash,
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertAdapterInvocationBoundaryReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_adapter_invocation_boundary.';

        if ((string) data_get($metadata, $prefix.'adapter_invocation_boundary_gate_id') !== $normalized['adapter_invocation_boundary_gate_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_adapter_invocation_boundary_missing_or_mismatch');
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_adapter_invocation_boundary_prepared_pending_adapter_execution_guard') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_adapter_invocation_boundary_not_ready_for_execution_guard');
        }

        foreach ([
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
        ] as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'dispatch_allowed', 'boundary_external_process_started', 'boundary_provider_started'] as $field) {
            if ((bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['post_start_adapter_invocation_boundary_prepared', 'post_start_provider_start_driver_prepared', 'dispatch_receipt_used', 'observed_external_process_started', 'observed_provider_started'] as $field) {
            if (! (bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $guardResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $guardResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_adapter_execution_blocked',
            'idempotent' => $idempotent,
            'adapter_execution_guard_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.adapter_execution_guard_gate_id'),
            'execution_guard_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.execution_guard_id'),
            'adapter_invocation_boundary_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.adapter_invocation_boundary_gate_id'),
            'adapter_invocation_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.adapter_invocation_id'),
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.provider_start_attempt_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_adapter_execution_guard.post_start_evidence_acceptance_bridge_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_specific_execution_contract_required' => true,
            'provider_adapter_execution_guard_result' => $guardResult,
        ];
    }
}
