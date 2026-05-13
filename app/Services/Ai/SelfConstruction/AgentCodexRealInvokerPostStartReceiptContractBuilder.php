<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartReceiptContractBuilder
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function buildPostStartReceiptContract(array $input): array
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
            $existingContractId = (string) data_get($metadata, 'codex_real_invoker_post_start_receipt_contract.post_start_receipt_contract_id', '');

            if ($existingContractId !== '') {
                if ($existingContractId !== $normalized['post_start_receipt_contract_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_receipt_contract_already_built');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertOperatorStartHandoff($run, $metadata, $normalized);

            $metadata['codex_real_invoker_post_start_receipt_contract'] = [
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'real_invoker_process_starter_readiness_gate_id' => $normalized['real_invoker_process_starter_readiness_gate_id'],
                'real_invoker_start_execution_gate_id' => $normalized['real_invoker_start_execution_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'handoff_packet_hash' => $normalized['handoff_packet_hash'],
                'operator_runbook_hash' => $normalized['operator_runbook_hash'],
                'external_terminal_handoff_hash' => $normalized['external_terminal_handoff_hash'],
                'post_start_liveness_probe_contract_hash' => $normalized['post_start_liveness_probe_contract_hash'],
                'post_start_receipt_contract_hash' => $normalized['post_start_receipt_contract_hash'],
                'failure_escalation_contract_hash' => $normalized['failure_escalation_contract_hash'],
                'external_process_identity_contract_hash' => $normalized['external_process_identity_contract_hash'],
                'startup_evidence_contract_hash' => $normalized['startup_evidence_contract_hash'],
                'terminal_pid_capture_contract_hash' => $normalized['terminal_pid_capture_contract_hash'],
                'post_start_cost_meter_contract_hash' => $normalized['post_start_cost_meter_contract_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_receipt_contract_ready_pending_external_start_evidence',
                'post_start_receipt_contract_built' => true,
                'operator_start_handoff_built' => true,
                'manual_operator_start_required' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex real invoker post-start receipt contract built; no external process evidence has been accepted.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_post_start_receipt_contract.built',
                'post_start_receipt_contract_id' => $normalized['post_start_receipt_contract_id'],
                'operator_start_handoff_id' => $normalized['operator_start_handoff_id'],
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'external_process_identity_contract_hash' => $normalized['external_process_identity_contract_hash'],
                'startup_evidence_contract_hash' => $normalized['startup_evidence_contract_hash'],
                'terminal_pid_capture_contract_hash' => $normalized['terminal_pid_capture_contract_hash'],
                'post_start_cost_meter_contract_hash' => $normalized['post_start_cost_meter_contract_hash'],
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_post_start_receipt_contract',
                'receipt_id' => $normalized['post_start_receipt_contract_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-post-start-receipt-contract.v1',
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
            'real_invoker_process_starter_readiness_gate_id',
            'real_invoker_start_execution_gate_id',
            'manual_start_executor_receipt_id',
            'operator_start_handoff_id',
            'post_start_receipt_contract_id',
            'handoff_packet_hash',
            'operator_runbook_hash',
            'external_terminal_handoff_hash',
            'post_start_liveness_probe_contract_hash',
            'post_start_receipt_contract_hash',
            'failure_escalation_contract_hash',
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashFields = [
            'handoff_packet_hash',
            'operator_runbook_hash',
            'external_terminal_handoff_hash',
            'post_start_liveness_probe_contract_hash',
            'post_start_receipt_contract_hash',
            'failure_escalation_contract_hash',
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
        ];

        $hashes = [];
        foreach ($hashFields as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        return array_merge([
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'real_invoker_process_starter_readiness_gate_id' => (string) $input['real_invoker_process_starter_readiness_gate_id'],
            'real_invoker_start_execution_gate_id' => (string) $input['real_invoker_start_execution_gate_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'operator_start_handoff_id' => (string) $input['operator_start_handoff_id'],
            'post_start_receipt_contract_id' => (string) $input['post_start_receipt_contract_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertOperatorStartHandoff(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_operator_start_handoff.operator_start_handoff_id') !== $normalized['operator_start_handoff_id']) {
            throw new InvalidArgumentException('codex_real_invoker_operator_start_handoff_missing_or_mismatch');
        }

        foreach ([
            'manual_start_executor_receipt_id',
            'real_invoker_process_starter_readiness_gate_id',
            'real_invoker_start_execution_gate_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_operator_start_handoff.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_operator_start_handoff.status') !== 'operator_start_handoff_ready_pending_manual_external_start') {
            throw new InvalidArgumentException('codex_real_invoker_operator_start_handoff_not_ready_for_post_start_contract');
        }

        foreach ([
            'handoff_packet_hash',
            'operator_runbook_hash',
            'external_terminal_handoff_hash',
            'post_start_liveness_probe_contract_hash',
            'post_start_receipt_contract_hash',
            'failure_escalation_contract_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_operator_start_handoff.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_operator_start_handoff.operator_start_handoff_built', false)) {
            throw new InvalidArgumentException('operator_start_handoff_not_built');
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_operator_start_handoff.'.$field, false)) {
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
            'status' => 'codex_real_invoker_post_start_receipt_contract_built',
            'idempotent' => $idempotent,
            'post_start_receipt_contract_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.post_start_receipt_contract_id'),
            'operator_start_handoff_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.operator_start_handoff_id'),
            'manual_start_executor_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.manual_start_executor_receipt_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_receipt_contract.adapter'),
            'post_start_receipt_contract_built' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
