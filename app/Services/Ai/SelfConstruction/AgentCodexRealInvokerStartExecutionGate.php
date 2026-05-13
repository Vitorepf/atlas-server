<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerStartExecutionGate
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeStartExecution(array $input): array
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
            $existingGateId = (string) data_get($metadata, 'codex_real_invoker_start_execution_gate.real_invoker_start_execution_gate_id', '');

            if ($existingGateId !== '') {
                if ($existingGateId !== $normalized['real_invoker_start_execution_gate_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_start_execution_gate_already_authorized');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertEnvelopeBuilt($run, $metadata, $normalized);

            $metadata['codex_real_invoker_start_execution_gate'] = [
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
                'process_start_envelope_hash' => $normalized['process_start_envelope_hash'],
                'start_command_hash' => $normalized['start_command_hash'],
                'start_environment_hash' => $normalized['start_environment_hash'],
                'start_cwd_hash' => $normalized['start_cwd_hash'],
                'start_supervisor_hash' => $normalized['start_supervisor_hash'],
                'start_liveness_contract_hash' => $normalized['start_liveness_contract_hash'],
                'operator_execution_gate_receipt_hash' => $normalized['operator_execution_gate_receipt_hash'],
                'execution_gate_policy_hash' => $normalized['execution_gate_policy_hash'],
                'execution_window_hash' => $normalized['execution_window_hash'],
                'preflight_snapshot_hash' => $normalized['preflight_snapshot_hash'],
                'rollback_readiness_hash' => $normalized['rollback_readiness_hash'],
                'human_start_signature_hash' => $normalized['human_start_signature_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_start_execution_authorized_pending_process_starter',
                'real_invoker_start_execution_gate_authorized' => true,
                'start_envelope_ready' => true,
                'start_execution_authorized' => true,
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
                'summary' => 'Codex real invoker start execution gate authorized; actual process start remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_start_execution_gate.authorized',
                'real_invoker_start_execution_gate_id' => $normalized['real_invoker_start_execution_gate_id'],
                'real_invoker_process_start_envelope_id' => $normalized['real_invoker_process_start_envelope_id'],
                'real_invoker_actual_process_start_rehearsal_id' => $normalized['real_invoker_actual_process_start_rehearsal_id'],
                'real_invoker_final_process_start_authorization_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'operator_execution_gate_receipt_hash' => $normalized['operator_execution_gate_receipt_hash'],
                'execution_gate_policy_hash' => $normalized['execution_gate_policy_hash'],
                'execution_window_hash' => $normalized['execution_window_hash'],
                'preflight_snapshot_hash' => $normalized['preflight_snapshot_hash'],
                'rollback_readiness_hash' => $normalized['rollback_readiness_hash'],
                'human_start_signature_hash' => $normalized['human_start_signature_hash'],
                'start_execution_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_start_execution_gate',
                'receipt_id' => $normalized['real_invoker_start_execution_gate_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-start-execution-gate.v1',
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
            'process_start_envelope_hash',
            'start_command_hash',
            'start_environment_hash',
            'start_cwd_hash',
            'start_supervisor_hash',
            'start_liveness_contract_hash',
            'operator_execution_gate_receipt_hash',
            'execution_gate_policy_hash',
            'execution_window_hash',
            'preflight_snapshot_hash',
            'rollback_readiness_hash',
            'human_start_signature_hash',
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
            'process_start_envelope_hash',
            'start_command_hash',
            'start_environment_hash',
            'start_cwd_hash',
            'start_supervisor_hash',
            'start_liveness_contract_hash',
            'operator_execution_gate_receipt_hash',
            'execution_gate_policy_hash',
            'execution_window_hash',
            'preflight_snapshot_hash',
            'rollback_readiness_hash',
            'human_start_signature_hash',
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
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertEnvelopeBuilt(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_process_start_envelope.real_invoker_process_start_envelope_id') !== $normalized['real_invoker_process_start_envelope_id']) {
            throw new InvalidArgumentException('codex_real_invoker_process_start_envelope_missing_or_mismatch');
        }

        foreach ([
            'real_invoker_actual_process_start_rehearsal_id',
            'real_invoker_final_process_start_authorization_id',
            'real_invoker_guarded_process_start_id',
            'real_invoker_supervised_start_activation_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_plan_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_process_start_envelope.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_process_start_envelope.status') !== 'real_invoker_process_start_envelope_built_pending_execution_gate') {
            throw new InvalidArgumentException('codex_real_invoker_process_start_envelope_not_ready_for_execution_gate');
        }

        foreach ([
            'process_start_envelope_hash',
            'start_command_hash',
            'start_environment_hash',
            'start_cwd_hash',
            'start_supervisor_hash',
            'start_liveness_contract_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_process_start_envelope.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_process_start_envelope.start_envelope_ready', false)) {
            throw new InvalidArgumentException('process_start_envelope_not_ready');
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_process_start_envelope.'.$field, false)) {
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
            'status' => 'codex_real_invoker_start_execution_gate_authorized',
            'idempotent' => $idempotent,
            'real_invoker_start_execution_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_start_execution_gate.real_invoker_start_execution_gate_id'),
            'real_invoker_process_start_envelope_id' => (string) data_get($run->metadata, 'codex_real_invoker_start_execution_gate.real_invoker_process_start_envelope_id'),
            'real_invoker_actual_process_start_rehearsal_id' => (string) data_get($run->metadata, 'codex_real_invoker_start_execution_gate.real_invoker_actual_process_start_rehearsal_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_start_execution_gate.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_start_execution_gate.adapter'),
            'real_invoker_start_execution_gate_authorized' => true,
            'start_envelope_ready' => true,
            'start_execution_authorized' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
