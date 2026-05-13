<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerManualStartExecutorReceiptWriter
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function writeManualStartExecutorReceipt(array $input): array
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
            $existingReceiptId = (string) data_get($metadata, 'codex_real_invoker_manual_start_executor_receipt.manual_start_executor_receipt_id', '');

            if ($existingReceiptId !== '') {
                if ($existingReceiptId !== $normalized['manual_start_executor_receipt_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_manual_start_executor_receipt_already_written');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertProcessStarterReadiness($run, $metadata, $normalized);

            $metadata['codex_real_invoker_manual_start_executor_receipt'] = [
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'real_invoker_process_starter_readiness_gate_id' => $normalized['real_invoker_process_starter_readiness_gate_id'],
                'real_invoker_start_execution_gate_id' => $normalized['real_invoker_start_execution_gate_id'],
                'real_invoker_process_start_envelope_id' => $normalized['real_invoker_process_start_envelope_id'],
                'real_invoker_actual_process_start_rehearsal_id' => $normalized['real_invoker_actual_process_start_rehearsal_id'],
                'real_invoker_final_process_start_authorization_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'real_invoker_guarded_process_start_id' => $normalized['real_invoker_guarded_process_start_id'],
                'real_invoker_supervised_start_activation_id' => $normalized['real_invoker_supervised_start_activation_id'],
                'real_invoker_executor_enablement_id' => $normalized['real_invoker_executor_enablement_id'],
                'real_invoker_executor_fresh_release_id' => $normalized['real_invoker_executor_fresh_release_id'],
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'process_starter_manifest_hash' => $normalized['process_starter_manifest_hash'],
                'supervisor_binding_hash' => $normalized['supervisor_binding_hash'],
                'liveness_monitor_binding_hash' => $normalized['liveness_monitor_binding_hash'],
                'cancellation_contract_hash' => $normalized['cancellation_contract_hash'],
                'output_capture_contract_hash' => $normalized['output_capture_contract_hash'],
                'cost_meter_contract_hash' => $normalized['cost_meter_contract_hash'],
                'start_replay_guard_hash' => $normalized['start_replay_guard_hash'],
                'operator_process_starter_signature_hash' => $normalized['operator_process_starter_signature_hash'],
                'manual_start_command_hash' => $normalized['manual_start_command_hash'],
                'terminal_session_binding_hash' => $normalized['terminal_session_binding_hash'],
                'operator_presence_hash' => $normalized['operator_presence_hash'],
                'live_supervisor_ack_hash' => $normalized['live_supervisor_ack_hash'],
                'initial_liveness_probe_hash' => $normalized['initial_liveness_probe_hash'],
                'kill_switch_ack_hash' => $normalized['kill_switch_ack_hash'],
                'output_stream_capture_hash' => $normalized['output_stream_capture_hash'],
                'cost_meter_initial_hash' => $normalized['cost_meter_initial_hash'],
                'no_autostart_attestation_hash' => $normalized['no_autostart_attestation_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'manual_start_executor_receipt_written_pending_operator_external_start',
                'manual_start_executor_receipt_written' => true,
                'process_starter_ready' => true,
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
                'summary' => 'Codex real invoker manual start executor receipt written; actual process start remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_manual_start_executor_receipt.written',
                'manual_start_executor_receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'real_invoker_process_starter_readiness_gate_id' => $normalized['real_invoker_process_starter_readiness_gate_id'],
                'real_invoker_start_execution_gate_id' => $normalized['real_invoker_start_execution_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'manual_start_command_hash' => $normalized['manual_start_command_hash'],
                'terminal_session_binding_hash' => $normalized['terminal_session_binding_hash'],
                'operator_presence_hash' => $normalized['operator_presence_hash'],
                'live_supervisor_ack_hash' => $normalized['live_supervisor_ack_hash'],
                'initial_liveness_probe_hash' => $normalized['initial_liveness_probe_hash'],
                'kill_switch_ack_hash' => $normalized['kill_switch_ack_hash'],
                'output_stream_capture_hash' => $normalized['output_stream_capture_hash'],
                'cost_meter_initial_hash' => $normalized['cost_meter_initial_hash'],
                'no_autostart_attestation_hash' => $normalized['no_autostart_attestation_hash'],
                'manual_operator_start_required' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_manual_start_executor_receipt',
                'receipt_id' => $normalized['manual_start_executor_receipt_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-manual-start-executor-receipt.v1',
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
            'real_invoker_executor_plan_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_supervised_start_activation_id',
            'real_invoker_guarded_process_start_id',
            'real_invoker_final_process_start_authorization_id',
            'real_invoker_actual_process_start_rehearsal_id',
            'real_invoker_process_start_envelope_id',
            'real_invoker_start_execution_gate_id',
            'real_invoker_process_starter_readiness_gate_id',
            'manual_start_executor_receipt_id',
            'process_starter_manifest_hash',
            'supervisor_binding_hash',
            'liveness_monitor_binding_hash',
            'cancellation_contract_hash',
            'output_capture_contract_hash',
            'cost_meter_contract_hash',
            'start_replay_guard_hash',
            'operator_process_starter_signature_hash',
            'manual_start_command_hash',
            'terminal_session_binding_hash',
            'operator_presence_hash',
            'live_supervisor_ack_hash',
            'initial_liveness_probe_hash',
            'kill_switch_ack_hash',
            'output_stream_capture_hash',
            'cost_meter_initial_hash',
            'no_autostart_attestation_hash',
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
            'process_starter_manifest_hash',
            'supervisor_binding_hash',
            'liveness_monitor_binding_hash',
            'cancellation_contract_hash',
            'output_capture_contract_hash',
            'cost_meter_contract_hash',
            'start_replay_guard_hash',
            'operator_process_starter_signature_hash',
            'manual_start_command_hash',
            'terminal_session_binding_hash',
            'operator_presence_hash',
            'live_supervisor_ack_hash',
            'initial_liveness_probe_hash',
            'kill_switch_ack_hash',
            'output_stream_capture_hash',
            'cost_meter_initial_hash',
            'no_autostart_attestation_hash',
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
            'real_invoker_executor_plan_id' => (string) $input['real_invoker_executor_plan_id'],
            'real_invoker_executor_fresh_release_id' => (string) $input['real_invoker_executor_fresh_release_id'],
            'real_invoker_executor_enablement_id' => (string) $input['real_invoker_executor_enablement_id'],
            'real_invoker_supervised_start_activation_id' => (string) $input['real_invoker_supervised_start_activation_id'],
            'real_invoker_guarded_process_start_id' => (string) $input['real_invoker_guarded_process_start_id'],
            'real_invoker_final_process_start_authorization_id' => (string) $input['real_invoker_final_process_start_authorization_id'],
            'real_invoker_actual_process_start_rehearsal_id' => (string) $input['real_invoker_actual_process_start_rehearsal_id'],
            'real_invoker_process_start_envelope_id' => (string) $input['real_invoker_process_start_envelope_id'],
            'real_invoker_start_execution_gate_id' => (string) $input['real_invoker_start_execution_gate_id'],
            'real_invoker_process_starter_readiness_gate_id' => (string) $input['real_invoker_process_starter_readiness_gate_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertProcessStarterReadiness(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_process_starter_readiness_gate.real_invoker_process_starter_readiness_gate_id') !== $normalized['real_invoker_process_starter_readiness_gate_id']) {
            throw new InvalidArgumentException('codex_real_invoker_process_starter_readiness_gate_missing_or_mismatch');
        }

        foreach ([
            'real_invoker_start_execution_gate_id',
            'real_invoker_process_start_envelope_id',
            'real_invoker_actual_process_start_rehearsal_id',
            'real_invoker_final_process_start_authorization_id',
            'real_invoker_guarded_process_start_id',
            'real_invoker_supervised_start_activation_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_plan_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_process_starter_readiness_gate.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_process_starter_readiness_gate.status') !== 'real_invoker_process_starter_ready_pending_manual_start_executor') {
            throw new InvalidArgumentException('codex_real_invoker_process_starter_readiness_gate_not_ready_for_manual_start_executor');
        }

        foreach ([
            'process_starter_manifest_hash',
            'supervisor_binding_hash',
            'liveness_monitor_binding_hash',
            'cancellation_contract_hash',
            'output_capture_contract_hash',
            'cost_meter_contract_hash',
            'start_replay_guard_hash',
            'operator_process_starter_signature_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_process_starter_readiness_gate.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_process_starter_readiness_gate.process_starter_ready', false)) {
            throw new InvalidArgumentException('process_starter_not_ready');
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_process_starter_readiness_gate.'.$field, false)) {
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
            'status' => 'codex_real_invoker_manual_start_executor_receipt_written',
            'idempotent' => $idempotent,
            'manual_start_executor_receipt_id' => (string) data_get($run->metadata, 'codex_real_invoker_manual_start_executor_receipt.manual_start_executor_receipt_id'),
            'real_invoker_process_starter_readiness_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_manual_start_executor_receipt.real_invoker_process_starter_readiness_gate_id'),
            'real_invoker_start_execution_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_manual_start_executor_receipt.real_invoker_start_execution_gate_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_manual_start_executor_receipt.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_manual_start_executor_receipt.adapter'),
            'manual_start_executor_receipt_written' => true,
            'manual_operator_start_required' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
