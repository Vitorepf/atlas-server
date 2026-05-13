<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartProviderExecutionContractGate
{
    public function __construct(
        private readonly AgentCodexProviderExecutionDriver $codexProviderExecution,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preparePostStartProviderExecutionContract(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_self_construction_agent_sandbox_bindings', 'atlas_ledger_events'] as $table) {
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
            $existingCodexExecutionId = (string) data_get($metadata, 'codex_real_invoker_post_start_provider_execution_contract.codex_execution_id', '');

            if ($existingCodexExecutionId !== '') {
                if ($existingCodexExecutionId !== $normalized['codex_execution_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_provider_execution_contract_already_recorded');
                }

                return $this->result($observedRun, idempotent: true, codexExecutionResult: null);
            }

            $this->assertAdapterExecutionGuardReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $codexExecutionResult = $this->codexProviderExecution->prepareCodexExecution([
                'run_key' => $providerStartRunKey,
                'codex_execution_id' => $normalized['codex_execution_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
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

            if ((string) data_get($codexExecutionResult, 'status') !== 'codex_provider_execution_prepared') {
                throw new InvalidArgumentException('codex_provider_execution_not_prepared');
            }

            foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($codexExecutionResult, $field, false)) {
                    throw new InvalidArgumentException('codex_provider_execution_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_provider_execution_contract'] = [
                'provider_execution_contract_gate_id' => $normalized['provider_execution_contract_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
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
                'command' => $normalized['command'],
                'cwd' => $normalized['cwd'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'max_runtime_minutes' => $normalized['max_runtime_minutes'],
                'max_cost_usd' => $normalized['max_cost_usd'],
                'status' => 'post_start_codex_provider_execution_contract_prepared_pending_process_start_release',
                'post_start_provider_execution_contract_prepared' => true,
                'post_start_adapter_execution_guard_recorded' => true,
                'provider_specific_execution_contract_ready' => true,
                'observed_external_process_started' => true,
                'observed_provider_started' => true,
                'actual_process_start_allowed' => false,
                'token_spend_allowed' => false,
                'provider_process_call_allowed' => false,
                'adapter_invocation_allowed' => false,
                'adapter_execution_allowed' => false,
                'dispatch_allowed' => false,
                'codex_provider_execution_result' => $codexExecutionResult,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start provider execution contract prepared; process start remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, codexExecutionResult: $codexExecutionResult);
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
            'provider_execution_contract_gate_id',
            'codex_execution_id',
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

        $receiptHash = strtolower(trim((string) $input['signed_dispatch_receipt_hash']));
        $contextPackHash = strtolower(trim((string) $input['context_pack_hash']));
        $continuationSummaryHash = strtolower(trim((string) $input['continuation_summary_hash']));

        foreach ([
            'signed_dispatch_receipt_hash' => $receiptHash,
            'context_pack_hash' => $contextPackHash,
            'continuation_summary_hash' => $continuationSummaryHash,
        ] as $field => $value) {
            if (! preg_match('/^[a-f0-9]{64}$/', $value)) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $maxRuntimeMinutes = (int) $input['max_runtime_minutes'];
        $maxCostUsd = (float) $input['max_cost_usd'];

        if ($maxRuntimeMinutes < 1 || $maxRuntimeMinutes > 480) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ($maxCostUsd <= 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'provider_execution_contract_gate_id' => (string) $input['provider_execution_contract_gate_id'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
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
            'command' => trim((string) $input['command']),
            'cwd' => rtrim((string) $input['cwd'], '/'),
            'context_pack_hash' => $contextPackHash,
            'continuation_summary_hash' => $continuationSummaryHash,
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => $maxRuntimeMinutes,
            'max_cost_usd' => $maxCostUsd,
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertAdapterExecutionGuardReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $guardPrefix = 'codex_real_invoker_post_start_adapter_execution_guard.';

        if ((string) data_get($metadata, $guardPrefix.'adapter_execution_guard_gate_id') !== $normalized['adapter_execution_guard_gate_id']) {
            throw new InvalidArgumentException('codex_real_invoker_post_start_adapter_execution_guard_missing_or_mismatch');
        }

        if ((string) data_get($metadata, $guardPrefix.'status') !== 'post_start_adapter_execution_blocked_pending_codex_provider_execution_contract') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_adapter_execution_guard_not_ready_for_provider_execution_contract');
        }

        foreach ([
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
        ] as $field) {
            if ((string) data_get($metadata, $guardPrefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $guardPrefix.'provider_adapter_execution_guard_result.status') !== 'provider_adapter_execution_blocked') {
            throw new InvalidArgumentException('provider_adapter_execution_guard_result_not_blocking');
        }

        foreach (['actual_process_start_allowed', 'token_spend_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed', 'adapter_execution_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, $guardPrefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }

        foreach (['post_start_adapter_execution_guard_recorded', 'post_start_adapter_invocation_boundary_prepared', 'post_start_provider_start_driver_prepared', 'dispatch_receipt_used', 'observed_external_process_started', 'observed_provider_started', 'provider_specific_execution_contract_required'] as $field) {
            if (! (bool) data_get($metadata, $guardPrefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }

        $boundaryPrefix = 'codex_real_invoker_post_start_adapter_invocation_boundary.';
        foreach (['context_pack_hash', 'continuation_summary_hash'] as $field) {
            if ((string) data_get($metadata, $boundaryPrefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $boundaryPrefix.'provider_start_run_key') !== 'provider-start:'.$normalized['provider_start_attempt_id']) {
            throw new InvalidArgumentException('provider_start_run_key_mismatch');
        }
    }

    /**
     * @param  array<string,mixed>|null  $codexExecutionResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $codexExecutionResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_provider_execution_contract_prepared',
            'idempotent' => $idempotent,
            'provider_execution_contract_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.provider_execution_contract_gate_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.codex_execution_id'),
            'adapter_execution_guard_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.adapter_execution_guard_gate_id'),
            'execution_guard_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.execution_guard_id'),
            'adapter_invocation_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.adapter_invocation_id'),
            'provider_start_attempt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.provider_start_attempt_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_provider_execution_contract.post_start_evidence_acceptance_bridge_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider_specific_execution_contract_ready' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'actual_process_start_allowed' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'dispatch_allowed' => false,
            'codex_provider_execution_result' => $codexExecutionResult,
        ];
    }
}
