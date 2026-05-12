<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexProcessStartReleaseGate
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeCodexProcessStart(array $input): array
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
            $existingReleaseId = (string) data_get($metadata, 'codex_process_start_release.process_start_release_id', '');

            if ($existingReleaseId !== '') {
                if ($existingReleaseId !== $normalized['process_start_release_id']) {
                    throw new InvalidArgumentException('codex_process_start_release_already_authorized');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertCodexExecutionPrepared($run, $metadata, $normalized);

            $metadata['codex_process_start_release'] = [
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
                'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'authorized_pending_supervised_start_executor',
                'process_start_release_authorized' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'authorized_by' => $normalized['actor'],
                'authorized_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex process start release authorized; supervised start executor remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_process_start.release_authorized',
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
                'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'process_start_release_authorized' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_process_start_release',
                'receipt_id' => $normalized['process_start_release_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-process-start-release-gate.v1',
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

        $operatorReleaseReceiptHash = strtolower(trim((string) $input['operator_release_receipt_hash']));
        $codexExecutionContractHash = strtolower(trim((string) $input['codex_execution_contract_hash']));

        if (! preg_match('/^[a-f0-9]{64}$/', $operatorReleaseReceiptHash)) {
            throw new InvalidArgumentException('invalid_operator_release_receipt_hash');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $codexExecutionContractHash)) {
            throw new InvalidArgumentException('invalid_codex_execution_contract_hash');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'operator_release_receipt_hash' => $operatorReleaseReceiptHash,
            'codex_execution_contract_hash' => $codexExecutionContractHash,
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertCodexExecutionPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_provider_execution.codex_execution_id') !== $normalized['codex_execution_id']) {
            throw new InvalidArgumentException('codex_provider_execution_missing_or_mismatch');
        }

        if ((string) data_get($metadata, 'codex_provider_execution.status') !== 'prepared_pending_explicit_codex_process_release') {
            throw new InvalidArgumentException('codex_provider_execution_not_prepared_for_release');
        }

        if ((bool) data_get($metadata, 'codex_provider_execution.external_process_started', false)) {
            throw new InvalidArgumentException('external_process_already_started');
        }

        if ((bool) data_get($metadata, 'codex_provider_execution.token_spend_allowed', false)) {
            throw new InvalidArgumentException('token_spend_already_allowed');
        }

        if ((bool) data_get($metadata, 'codex_provider_execution.dispatch_allowed', false)) {
            throw new InvalidArgumentException('dispatch_already_allowed');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_process_start_release_authorized',
            'idempotent' => $idempotent,
            'process_start_release_id' => (string) data_get($run->metadata, 'codex_process_start_release.process_start_release_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_process_start_release.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_process_start_release.adapter'),
            'process_start_release_authorized' => true,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
