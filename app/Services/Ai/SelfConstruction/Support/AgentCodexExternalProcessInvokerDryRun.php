<?php

namespace App\Services\Ai\SelfConstruction\Support;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexExternalProcessInvokerDryRun
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareDryRun(array $input): array
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
            $existingDryRunId = (string) data_get($metadata, 'codex_external_process_invoker_dry_run.dry_run_id', '');

            if ($existingDryRunId !== '') {
                if ($existingDryRunId !== $normalized['dry_run_id']) {
                    throw new InvalidArgumentException('codex_external_process_invoker_dry_run_already_prepared');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertInvocationAuthorized($run, $metadata, $normalized);

            $metadata['codex_external_process_invoker_dry_run'] = [
                'dry_run_id' => $normalized['dry_run_id'],
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_dry_run_receipt_hash' => $normalized['operator_dry_run_receipt_hash'],
                'invoker_contract_hash' => $normalized['invoker_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'dry_run_ready_pending_real_invoker_release',
                'external_process_invoker_dry_run_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex external process invoker dry-run prepared; real invoker remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_external_process_invoker.dry_run_prepared',
                'dry_run_id' => $normalized['dry_run_id'],
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
                'operator_dry_run_receipt_hash' => $normalized['operator_dry_run_receipt_hash'],
                'invoker_contract_hash' => $normalized['invoker_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'external_process_invoker_dry_run_prepared' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_external_process_invoker_dry_run',
                'receipt_id' => $normalized['dry_run_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-external-process-invoker-dry-run.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
    }

    /**
     * Read-only preview of exactly what a real invocation WOULD do: the literal
     * command, the context scope, the environment contract, and any reasons it
     * would currently be blocked — without ever launching a process, writing to
     * the ledger, or mutating run state. Distinct from prepareDryRun(), which
     * mutates the run row and ledger; this is purely informational.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function preview(array $input): array
    {
        try {
            $normalized = $this->normalize($input);
        } catch (InvalidArgumentException $e) {
            return $this->blockedPreview([$e->getMessage()]);
        }

        $run = AtlasSelfConstructionAgentRun::query()
            ->where('run_key', $normalized['run_key'])
            ->first();

        if (! $run instanceof AtlasSelfConstructionAgentRun) {
            return $this->blockedPreview(['agent_run_not_found']);
        }

        $metadata = (array) $run->metadata;
        $blockedReasons = [];

        try {
            $this->assertInvocationAuthorized($run, $metadata, $normalized);
        } catch (InvalidArgumentException $e) {
            $blockedReasons[] = $e->getMessage();
        }

        $commandPreview = sprintf(
            'codex --execution-id=%s --runtime-driver=%s --process-command-hash=%s --env-contract-hash=%s',
            $normalized['codex_execution_id'],
            $normalized['runtime_driver_id'],
            substr($normalized['process_command_hash'], 0, 12),
            substr($normalized['environment_contract_hash'], 0, 12),
        );

        $contextScope = [
            'run_key' => $normalized['run_key'],
            'agent_run_id' => (string) $run->id,
            'packet_id' => $run->packet_id,
        ];

        $environmentContract = [
            'environment_contract_hash' => $normalized['environment_contract_hash'],
            'termination_policy_hash' => $normalized['termination_policy_hash'],
            'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => $normalized['liveness_probe_hash'],
        ];

        return [
            'status' => $blockedReasons === [] ? 'codex_external_process_invoker_dry_run_preview_ok' : 'blocked',
            'blocked_reasons' => $blockedReasons,
            'command_preview' => $commandPreview,
            'context_scope' => $contextScope,
            'environment_contract' => $environmentContract,
            'process_started' => false,
            'dispatch_allowed' => false,
            'token_spend_allowed' => false,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string,mixed>
     */
    private function blockedPreview(array $reasons): array
    {
        return [
            'status' => 'blocked',
            'blocked_reasons' => $reasons,
            'command_preview' => null,
            'context_scope' => null,
            'environment_contract' => null,
            'process_started' => false,
            'dispatch_allowed' => false,
            'token_spend_allowed' => false,
        ];
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
            'dry_run_id',
            'operator_dry_run_receipt_hash',
            'invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
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
        foreach ([
            'operator_dry_run_receipt_hash',
            'invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
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
            'dry_run_id' => (string) $input['dry_run_id'],
            'operator_dry_run_receipt_hash' => $hashes['operator_dry_run_receipt_hash'],
            'invoker_contract_hash' => $hashes['invoker_contract_hash'],
            'process_command_hash' => $hashes['process_command_hash'],
            'environment_contract_hash' => $hashes['environment_contract_hash'],
            'termination_policy_hash' => $hashes['termination_policy_hash'],
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
    private function assertInvocationAuthorized(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_external_process_invocation_authorization.invocation_authorization_id') !== $normalized['invocation_authorization_id']) {
            throw new InvalidArgumentException('codex_external_process_invocation_authorization_missing_or_mismatch');
        }

        foreach (['runtime_driver_id', 'spawn_executor_id', 'spawn_enablement_id', 'supervised_start_id', 'process_start_release_id', 'codex_execution_id'] as $field) {
            if ((string) data_get($metadata, 'codex_external_process_invocation_authorization.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_external_process_invocation_authorization.status') !== 'authorized_pending_external_process_invoker') {
            throw new InvalidArgumentException('codex_external_process_invocation_authorization_not_ready_for_invoker_dry_run');
        }

        foreach (['process_command_hash', 'environment_contract_hash', 'termination_policy_hash'] as $field) {
            if ((string) data_get($metadata, 'codex_external_process_invocation_authorization.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_external_process_invocation_authorization.'.$field, false)) {
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
            'status' => 'codex_external_process_invoker_dry_run_prepared',
            'idempotent' => $idempotent,
            'dry_run_id' => (string) data_get($run->metadata, 'codex_external_process_invoker_dry_run.dry_run_id'),
            'invocation_authorization_id' => (string) data_get($run->metadata, 'codex_external_process_invoker_dry_run.invocation_authorization_id'),
            'runtime_driver_id' => (string) data_get($run->metadata, 'codex_external_process_invoker_dry_run.runtime_driver_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_external_process_invoker_dry_run.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_external_process_invoker_dry_run.adapter'),
            'external_process_invoker_dry_run_prepared' => true,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
