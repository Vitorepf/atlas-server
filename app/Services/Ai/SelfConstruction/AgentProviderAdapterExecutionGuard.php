<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentProviderAdapterExecutionGuard
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
    public function blockUntilProviderSpecificContract(array $input): array
    {
        $normalized = $this->normalize($input);

        if (! Schema::hasTable('atlas_self_construction_agent_runs')) {
            throw new InvalidArgumentException('atlas_self_construction_agent_runs_missing');
        }

        if (! Schema::hasTable(self::LEDGER_TABLE)) {
            throw new InvalidArgumentException('append_only_ledger_table_missing');
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
            $existingGuardId = (string) data_get($metadata, 'provider_adapter_execution_guard.execution_guard_id', '');

            if ($existingGuardId !== '') {
                if ($existingGuardId !== $normalized['execution_guard_id']) {
                    throw new InvalidArgumentException('provider_adapter_execution_guard_already_recorded');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertAdapterInvocationPrepared($run, $metadata, $normalized);

            $descriptor = $this->adapterRegistry->resolve($normalized['provider'], $normalized['adapter']);
            $descriptorHash = $this->adapterRegistry->descriptorHash($descriptor);

            if ((string) data_get($metadata, 'adapter_invocation.adapter_descriptor_hash') !== $descriptorHash) {
                throw new InvalidArgumentException('adapter_descriptor_hash_mismatch');
            }

            if ((bool) $descriptor['external_process_start_enabled'] || (bool) $descriptor['token_spend_enabled']) {
                throw new InvalidArgumentException('provider_adapter_descriptor_not_execution_safe');
            }

            $metadata['provider_adapter_execution_guard'] = [
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider' => $normalized['provider'],
                'adapter' => $normalized['adapter'],
                'adapter_id' => $descriptor['adapter_id'],
                'adapter_descriptor_hash' => $descriptorHash,
                'status' => 'blocked_pending_provider_specific_execution_contract',
                'blocked_by' => 'provider_specific_execution_contract_missing',
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Provider adapter execution guard recorded; external provider execution remains blocked.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_provider_adapter.execution_blocked',
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => $normalized['provider'],
                'adapter' => $normalized['adapter'],
                'adapter_id' => $descriptor['adapter_id'],
                'adapter_descriptor_hash' => $descriptorHash,
                'blocked_by' => 'provider_specific_execution_contract_missing',
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_provider_adapter_execution_guard',
                'receipt_id' => $normalized['execution_guard_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-provider-adapter-execution-guard.v1',
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
            'execution_guard_id',
            'adapter_invocation_id',
            'provider',
            'adapter',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider' => strtolower(trim((string) $input['provider'])),
            'adapter' => strtolower(trim((string) $input['adapter'])),
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertAdapterInvocationPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== $normalized['provider']) {
            throw new InvalidArgumentException('agent_run_provider_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.adapter_invocation_id') !== $normalized['adapter_invocation_id']) {
            throw new InvalidArgumentException('adapter_invocation_id_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.provider') !== $normalized['provider']) {
            throw new InvalidArgumentException('adapter_invocation_provider_mismatch');
        }

        if ((string) data_get($metadata, 'adapter_invocation.adapter') !== $normalized['adapter']) {
            throw new InvalidArgumentException('adapter_invocation_adapter_mismatch');
        }

        if ((bool) data_get($metadata, 'adapter_invocation.external_process_started', false)) {
            throw new InvalidArgumentException('external_process_already_started');
        }

        if ((bool) data_get($metadata, 'adapter_invocation.token_spend_allowed', false)) {
            throw new InvalidArgumentException('token_spend_already_allowed');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'provider_adapter_execution_blocked',
            'idempotent' => $idempotent,
            'execution_guard_id' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.execution_guard_id'),
            'adapter_invocation_id' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.adapter_invocation_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.adapter'),
            'blocked_by' => (string) data_get($run->metadata, 'provider_adapter_execution_guard.blocked_by'),
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
