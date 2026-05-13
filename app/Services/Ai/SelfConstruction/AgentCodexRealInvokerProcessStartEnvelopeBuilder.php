<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerProcessStartEnvelopeBuilder
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function buildStartEnvelope(array $input): array
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
            $existingEnvelopeId = (string) data_get($metadata, 'codex_real_invoker_process_start_envelope.real_invoker_process_start_envelope_id', '');

            if ($existingEnvelopeId !== '') {
                if ($existingEnvelopeId !== $normalized['real_invoker_process_start_envelope_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_process_start_envelope_already_built');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertRehearsalPrepared($run, $metadata, $normalized);

            $metadata['codex_real_invoker_process_start_envelope'] = [
                'real_invoker_process_start_envelope_id' => $normalized['real_invoker_process_start_envelope_id'],
                'real_invoker_actual_process_start_rehearsal_id' => $normalized['real_invoker_actual_process_start_rehearsal_id'],
                'real_invoker_final_process_start_authorization_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'real_invoker_guarded_process_start_id' => $normalized['real_invoker_guarded_process_start_id'],
                'real_invoker_supervised_start_activation_id' => $normalized['real_invoker_supervised_start_activation_id'],
                'real_invoker_executor_enablement_id' => $normalized['real_invoker_executor_enablement_id'],
                'real_invoker_executor_fresh_release_id' => $normalized['real_invoker_executor_fresh_release_id'],
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'process_start_rehearsal_hash' => $normalized['process_start_rehearsal_hash'],
                'command_resolution_hash' => $normalized['command_resolution_hash'],
                'environment_resolution_hash' => $normalized['environment_resolution_hash'],
                'cwd_verification_hash' => $normalized['cwd_verification_hash'],
                'supervisor_dry_run_hash' => $normalized['supervisor_dry_run_hash'],
                'liveness_probe_rehearsal_hash' => $normalized['liveness_probe_rehearsal_hash'],
                'operator_final_start_receipt_hash' => $normalized['operator_final_start_receipt_hash'],
                'final_start_signature_hash' => $normalized['final_start_signature_hash'],
                'final_start_policy_hash' => $normalized['final_start_policy_hash'],
                'final_start_window_hash' => $normalized['final_start_window_hash'],
                'final_start_replay_guard_hash' => $normalized['final_start_replay_guard_hash'],
                'final_start_kill_switch_hash' => $normalized['final_start_kill_switch_hash'],
                'process_start_envelope_hash' => $normalized['process_start_envelope_hash'],
                'start_command_hash' => $normalized['start_command_hash'],
                'start_environment_hash' => $normalized['start_environment_hash'],
                'start_cwd_hash' => $normalized['start_cwd_hash'],
                'start_supervisor_hash' => $normalized['start_supervisor_hash'],
                'start_liveness_contract_hash' => $normalized['start_liveness_contract_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_process_start_envelope_built_pending_execution_gate',
                'real_invoker_process_start_envelope_built' => true,
                'process_start_rehearsed' => true,
                'start_envelope_ready' => true,
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
                'summary' => 'Codex real invoker process start envelope built; actual process start remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_process_start_envelope.built',
                'real_invoker_process_start_envelope_id' => $normalized['real_invoker_process_start_envelope_id'],
                'real_invoker_actual_process_start_rehearsal_id' => $normalized['real_invoker_actual_process_start_rehearsal_id'],
                'real_invoker_final_process_start_authorization_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'real_invoker_guarded_process_start_id' => $normalized['real_invoker_guarded_process_start_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'process_start_envelope_hash' => $normalized['process_start_envelope_hash'],
                'start_command_hash' => $normalized['start_command_hash'],
                'start_environment_hash' => $normalized['start_environment_hash'],
                'start_cwd_hash' => $normalized['start_cwd_hash'],
                'start_supervisor_hash' => $normalized['start_supervisor_hash'],
                'start_liveness_contract_hash' => $normalized['start_liveness_contract_hash'],
                'start_envelope_ready' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_process_start_envelope',
                'receipt_id' => $normalized['real_invoker_process_start_envelope_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-process-start-envelope-builder.v1',
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
            'process_start_rehearsal_hash',
            'command_resolution_hash',
            'environment_resolution_hash',
            'cwd_verification_hash',
            'supervisor_dry_run_hash',
            'liveness_probe_rehearsal_hash',
            'operator_final_start_receipt_hash',
            'final_start_signature_hash',
            'final_start_policy_hash',
            'final_start_window_hash',
            'final_start_replay_guard_hash',
            'final_start_kill_switch_hash',
            'process_start_envelope_hash',
            'start_command_hash',
            'start_environment_hash',
            'start_cwd_hash',
            'start_supervisor_hash',
            'start_liveness_contract_hash',
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
            'process_start_rehearsal_hash',
            'command_resolution_hash',
            'environment_resolution_hash',
            'cwd_verification_hash',
            'supervisor_dry_run_hash',
            'liveness_probe_rehearsal_hash',
            'operator_final_start_receipt_hash',
            'final_start_signature_hash',
            'final_start_policy_hash',
            'final_start_window_hash',
            'final_start_replay_guard_hash',
            'final_start_kill_switch_hash',
            'process_start_envelope_hash',
            'start_command_hash',
            'start_environment_hash',
            'start_cwd_hash',
            'start_supervisor_hash',
            'start_liveness_contract_hash',
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
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertRehearsalPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_actual_process_start_rehearsal.real_invoker_actual_process_start_rehearsal_id') !== $normalized['real_invoker_actual_process_start_rehearsal_id']) {
            throw new InvalidArgumentException('codex_real_invoker_actual_process_start_rehearsal_missing_or_mismatch');
        }

        foreach ([
            'real_invoker_final_process_start_authorization_id',
            'real_invoker_guarded_process_start_id',
            'real_invoker_supervised_start_activation_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_plan_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_actual_process_start_rehearsal.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_actual_process_start_rehearsal.status') !== 'real_invoker_actual_process_start_rehearsed_pending_start_executor') {
            throw new InvalidArgumentException('codex_real_invoker_actual_process_start_rehearsal_not_ready_for_start_envelope');
        }

        foreach ([
            'process_start_rehearsal_hash',
            'command_resolution_hash',
            'environment_resolution_hash',
            'cwd_verification_hash',
            'supervisor_dry_run_hash',
            'liveness_probe_rehearsal_hash',
            'operator_final_start_receipt_hash',
            'final_start_signature_hash',
            'final_start_policy_hash',
            'final_start_window_hash',
            'final_start_replay_guard_hash',
            'final_start_kill_switch_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_actual_process_start_rehearsal.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_actual_process_start_rehearsal.process_start_rehearsed', false)) {
            throw new InvalidArgumentException('process_start_not_rehearsed');
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_actual_process_start_rehearsal.'.$field, false)) {
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
            'status' => 'codex_real_invoker_process_start_envelope_built',
            'idempotent' => $idempotent,
            'real_invoker_process_start_envelope_id' => (string) data_get($run->metadata, 'codex_real_invoker_process_start_envelope.real_invoker_process_start_envelope_id'),
            'real_invoker_actual_process_start_rehearsal_id' => (string) data_get($run->metadata, 'codex_real_invoker_process_start_envelope.real_invoker_actual_process_start_rehearsal_id'),
            'real_invoker_final_process_start_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_process_start_envelope.real_invoker_final_process_start_authorization_id'),
            'real_invoker_guarded_process_start_id' => (string) data_get($run->metadata, 'codex_real_invoker_process_start_envelope.real_invoker_guarded_process_start_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_process_start_envelope.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_process_start_envelope.adapter'),
            'real_invoker_process_start_envelope_built' => true,
            'process_start_rehearsed' => true,
            'start_envelope_ready' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
