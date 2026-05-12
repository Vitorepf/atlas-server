<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexExternalProcessInvocationAuthorizationGate
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeExternalProcessInvocation(array $input): array
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
            $existingAuthorizationId = (string) data_get($metadata, 'codex_external_process_invocation_authorization.invocation_authorization_id', '');

            if ($existingAuthorizationId !== '') {
                if ($existingAuthorizationId !== $normalized['invocation_authorization_id']) {
                    throw new InvalidArgumentException('codex_external_process_invocation_authorization_already_recorded');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertRuntimeDriverPrepared($run, $metadata, $normalized);

            $metadata['codex_external_process_invocation_authorization'] = [
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_invocation_receipt_hash' => $normalized['operator_invocation_receipt_hash'],
                'runtime_driver_contract_hash' => $normalized['runtime_driver_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'authorized_pending_external_process_invoker',
                'external_process_invocation_authorized' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'authorized_by' => $normalized['actor'],
                'authorized_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex external process invocation authorized; external process invoker remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_external_process_invocation.authorized',
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
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
                'operator_invocation_receipt_hash' => $normalized['operator_invocation_receipt_hash'],
                'runtime_driver_contract_hash' => $normalized['runtime_driver_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'external_process_invocation_authorized' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_external_process_invocation_authorization',
                'receipt_id' => $normalized['invocation_authorization_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-external-process-invocation-authorization-gate.v1',
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
            'runtime_driver_id',
            'invocation_authorization_id',
            'operator_invocation_receipt_hash',
            'runtime_driver_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
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
        foreach ([
            'operator_invocation_receipt_hash',
            'runtime_driver_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
        ] as $field) {
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
            'runtime_driver_id' => (string) $input['runtime_driver_id'],
            'invocation_authorization_id' => (string) $input['invocation_authorization_id'],
            'operator_invocation_receipt_hash' => $hashes['operator_invocation_receipt_hash'],
            'runtime_driver_contract_hash' => $hashes['runtime_driver_contract_hash'],
            'process_command_hash' => $hashes['process_command_hash'],
            'environment_contract_hash' => $hashes['environment_contract_hash'],
            'termination_policy_hash' => $hashes['termination_policy_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertRuntimeDriverPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_external_process_runtime_driver.runtime_driver_id') !== $normalized['runtime_driver_id']) {
            throw new InvalidArgumentException('codex_external_process_runtime_driver_missing_or_mismatch');
        }

        foreach (['spawn_executor_id', 'spawn_enablement_id', 'supervised_start_id', 'process_start_release_id', 'codex_execution_id'] as $field) {
            if ((string) data_get($metadata, 'codex_external_process_runtime_driver.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_external_process_runtime_driver.status') !== 'prepared_pending_process_invocation') {
            throw new InvalidArgumentException('codex_external_process_runtime_driver_not_ready_for_invocation_authorization');
        }

        foreach (['process_command_hash', 'environment_contract_hash', 'termination_policy_hash'] as $field) {
            if ((string) data_get($metadata, 'codex_external_process_runtime_driver.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_external_process_runtime_driver.'.$field, false)) {
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
            'status' => 'codex_external_process_invocation_authorization_recorded',
            'idempotent' => $idempotent,
            'invocation_authorization_id' => (string) data_get($run->metadata, 'codex_external_process_invocation_authorization.invocation_authorization_id'),
            'runtime_driver_id' => (string) data_get($run->metadata, 'codex_external_process_invocation_authorization.runtime_driver_id'),
            'spawn_executor_id' => (string) data_get($run->metadata, 'codex_external_process_invocation_authorization.spawn_executor_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_external_process_invocation_authorization.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_external_process_invocation_authorization.adapter'),
            'external_process_invocation_authorized' => true,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
