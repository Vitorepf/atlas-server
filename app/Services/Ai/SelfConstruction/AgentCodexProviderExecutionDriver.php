<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Models\AtlasSelfConstructionAgentSandboxBinding;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexProviderExecutionDriver
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AgentProviderAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexExecution(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_self_construction_agent_sandbox_bindings', self::LEDGER_TABLE] as $table) {
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
            $existingExecutionId = (string) data_get($metadata, 'codex_provider_execution.codex_execution_id', '');

            if ($existingExecutionId !== '') {
                if ($existingExecutionId !== $normalized['codex_execution_id']) {
                    throw new InvalidArgumentException('codex_provider_execution_already_prepared');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertRunReadyForCodex($run, $metadata, $normalized);
            $binding = $this->activeSandboxBinding($metadata, $normalized, $run);
            $descriptor = $this->adapterRegistry->resolve('codex', 'codex');
            $descriptorHash = $this->adapterRegistry->descriptorHash($descriptor);

            if ((string) data_get($metadata, 'adapter_invocation.adapter_descriptor_hash') !== $descriptorHash) {
                throw new InvalidArgumentException('adapter_descriptor_hash_mismatch');
            }

            if ((bool) $descriptor['external_process_start_enabled'] || (bool) $descriptor['token_spend_enabled']) {
                throw new InvalidArgumentException('codex_descriptor_not_execution_safe');
            }

            $metadata['codex_provider_execution'] = [
                'codex_execution_id' => $normalized['codex_execution_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'adapter_id' => $descriptor['adapter_id'],
                'adapter_descriptor_hash' => $descriptorHash,
                'sandbox_binding_key' => (string) $binding->binding_key,
                'command' => $normalized['command'],
                'cwd' => $normalized['cwd'],
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'max_runtime_minutes' => $normalized['max_runtime_minutes'],
                'max_cost_usd' => $normalized['max_cost_usd'],
                'status' => 'prepared_pending_explicit_codex_process_release',
                'provider_specific_contract_ready' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex provider execution envelope prepared; Codex process remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_provider.execution_prepared',
                'codex_execution_id' => $normalized['codex_execution_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'adapter_id' => $descriptor['adapter_id'],
                'adapter_descriptor_hash' => $descriptorHash,
                'sandbox_binding_key' => (string) $binding->binding_key,
                'context_pack_hash' => $normalized['context_pack_hash'],
                'continuation_summary_hash' => $normalized['continuation_summary_hash'],
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_provider_execution',
                'receipt_id' => $normalized['codex_execution_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-provider-execution-driver.v1',
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
            'execution_guard_id',
            'adapter_invocation_id',
            'provider',
            'adapter',
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

        $contextPackHash = strtolower(trim((string) $input['context_pack_hash']));
        $continuationSummaryHash = strtolower(trim((string) $input['continuation_summary_hash']));

        if (! preg_match('/^[a-f0-9]{64}$/', $contextPackHash)) {
            throw new InvalidArgumentException('invalid_context_pack_hash');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $continuationSummaryHash)) {
            throw new InvalidArgumentException('invalid_continuation_summary_hash');
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
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider' => strtolower(trim((string) $input['provider'])),
            'adapter' => strtolower(trim((string) $input['adapter'])),
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
    private function assertRunReadyForCodex(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ($normalized['provider'] !== 'codex' || $normalized['adapter'] !== 'codex') {
            throw new InvalidArgumentException('codex_provider_adapter_required');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if (! preg_match('/^codex(\s|$)/', $normalized['command'])) {
            throw new InvalidArgumentException('codex_command_not_allowlisted');
        }

        if (str_contains($normalized['command'], '&&') || str_contains($normalized['command'], ';') || str_contains($normalized['command'], '|')) {
            throw new InvalidArgumentException('arbitrary_shell_not_allowed');
        }

        if ((string) data_get($metadata, 'adapter_invocation.adapter_invocation_id') !== $normalized['adapter_invocation_id']) {
            throw new InvalidArgumentException('adapter_invocation_id_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.provider') !== 'codex') {
            throw new InvalidArgumentException('adapter_invocation_provider_not_codex');
        }

        if ((string) data_get($metadata, 'adapter_invocation.adapter') !== 'codex') {
            throw new InvalidArgumentException('adapter_invocation_adapter_not_codex');
        }

        if ((string) data_get($metadata, 'provider_adapter_execution_guard.execution_guard_id') !== $normalized['execution_guard_id']) {
            throw new InvalidArgumentException('provider_adapter_execution_guard_missing_or_mismatch');
        }

        if ((string) data_get($metadata, 'provider_adapter_execution_guard.status') !== 'blocked_pending_provider_specific_execution_contract') {
            throw new InvalidArgumentException('provider_adapter_execution_guard_not_blocking');
        }

        if ((string) data_get($metadata, 'provider_adapter_execution_guard.adapter_invocation_id') !== $normalized['adapter_invocation_id']) {
            throw new InvalidArgumentException('provider_adapter_execution_guard_adapter_invocation_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.command') !== $normalized['command']) {
            throw new InvalidArgumentException('adapter_invocation_command_mismatch');
        }

        if (rtrim((string) data_get($metadata, 'adapter_invocation.cwd'), '/') !== $normalized['cwd']) {
            throw new InvalidArgumentException('adapter_invocation_cwd_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.context_pack_hash') !== $normalized['context_pack_hash']) {
            throw new InvalidArgumentException('context_pack_hash_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.continuation_summary_hash') !== $normalized['continuation_summary_hash']) {
            throw new InvalidArgumentException('continuation_summary_hash_mismatch');
        }

        if ((bool) data_get($metadata, 'adapter_invocation.external_process_started', false)) {
            throw new InvalidArgumentException('external_process_already_started');
        }

        if ((bool) data_get($metadata, 'adapter_invocation.token_spend_allowed', false)) {
            throw new InvalidArgumentException('token_spend_already_allowed');
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function activeSandboxBinding(array $metadata, array $normalized, AtlasSelfConstructionAgentRun $run): AtlasSelfConstructionAgentSandboxBinding
    {
        $bindingKey = (string) data_get($metadata, 'sandbox_binding_key', '');

        if ($bindingKey === '') {
            throw new InvalidArgumentException('sandbox_binding_key_missing');
        }

        $binding = AtlasSelfConstructionAgentSandboxBinding::query()
            ->where('binding_key', $bindingKey)
            ->lockForUpdate()
            ->first();

        if (! $binding instanceof AtlasSelfConstructionAgentSandboxBinding) {
            throw new InvalidArgumentException('sandbox_binding_not_found');
        }

        if ($binding->status !== 'active') {
            throw new InvalidArgumentException('sandbox_binding_not_active');
        }

        if ($binding->provider !== 'codex') {
            throw new InvalidArgumentException('sandbox_binding_provider_not_codex');
        }

        if ($binding->packet_id !== $run->packet_id) {
            throw new InvalidArgumentException('sandbox_binding_packet_mismatch');
        }

        if (rtrim((string) $binding->worktree_path, '/') !== $normalized['cwd']) {
            throw new InvalidArgumentException('sandbox_binding_cwd_mismatch');
        }

        return $binding;
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_provider_execution_prepared',
            'idempotent' => $idempotent,
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_provider_execution.codex_execution_id'),
            'execution_guard_id' => (string) data_get($run->metadata, 'codex_provider_execution.execution_guard_id'),
            'adapter_invocation_id' => (string) data_get($run->metadata, 'codex_provider_execution.adapter_invocation_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_provider_execution.adapter'),
            'provider_specific_contract_ready' => true,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
