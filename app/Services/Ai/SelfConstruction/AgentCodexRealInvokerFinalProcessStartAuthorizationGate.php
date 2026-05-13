<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerFinalProcessStartAuthorizationGate
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeFinalStart(array $input): array
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
            $existingAuthorizationId = (string) data_get($metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_final_process_start_authorization_id', '');

            if ($existingAuthorizationId !== '') {
                if ($existingAuthorizationId !== $normalized['real_invoker_final_process_start_authorization_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_final_process_start_authorization_already_prepared');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertGuardedStartPrepared($run, $metadata, $normalized);

            $metadata['codex_real_invoker_final_process_start_authorization'] = [
                'real_invoker_final_process_start_authorization_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'real_invoker_guarded_process_start_id' => $normalized['real_invoker_guarded_process_start_id'],
                'real_invoker_supervised_start_activation_id' => $normalized['real_invoker_supervised_start_activation_id'],
                'real_invoker_executor_enablement_id' => $normalized['real_invoker_executor_enablement_id'],
                'real_invoker_executor_fresh_release_id' => $normalized['real_invoker_executor_fresh_release_id'],
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_final_start_receipt_hash' => $normalized['operator_final_start_receipt_hash'],
                'final_start_signature_hash' => $normalized['final_start_signature_hash'],
                'final_start_policy_hash' => $normalized['final_start_policy_hash'],
                'final_start_window_hash' => $normalized['final_start_window_hash'],
                'final_start_replay_guard_hash' => $normalized['final_start_replay_guard_hash'],
                'final_start_kill_switch_hash' => $normalized['final_start_kill_switch_hash'],
                'operator_guarded_start_receipt_hash' => $normalized['operator_guarded_start_receipt_hash'],
                'process_runner_contract_hash' => $normalized['process_runner_contract_hash'],
                'dry_run_rehearsal_hash' => $normalized['dry_run_rehearsal_hash'],
                'launch_invocation_contract_hash' => $normalized['launch_invocation_contract_hash'],
                'post_start_observability_hash' => $normalized['post_start_observability_hash'],
                'revoke_guard_hash' => $normalized['revoke_guard_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_final_process_start_authorized_pending_start_executor',
                'real_invoker_final_process_start_authorization_prepared' => true,
                'final_process_start_authorized' => true,
                'executor_enabled' => true,
                'process_start_armed' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'authorized_by' => $normalized['actor'],
                'authorized_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex real invoker final process start authorization recorded; actual process start remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_final_process_start_authorization.prepared',
                'real_invoker_final_process_start_authorization_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'real_invoker_guarded_process_start_id' => $normalized['real_invoker_guarded_process_start_id'],
                'real_invoker_supervised_start_activation_id' => $normalized['real_invoker_supervised_start_activation_id'],
                'real_invoker_executor_enablement_id' => $normalized['real_invoker_executor_enablement_id'],
                'real_invoker_executor_fresh_release_id' => $normalized['real_invoker_executor_fresh_release_id'],
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'operator_final_start_receipt_hash' => $normalized['operator_final_start_receipt_hash'],
                'final_start_signature_hash' => $normalized['final_start_signature_hash'],
                'final_start_policy_hash' => $normalized['final_start_policy_hash'],
                'final_start_window_hash' => $normalized['final_start_window_hash'],
                'final_start_replay_guard_hash' => $normalized['final_start_replay_guard_hash'],
                'final_start_kill_switch_hash' => $normalized['final_start_kill_switch_hash'],
                'final_process_start_authorized' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_final_process_start_authorization',
                'receipt_id' => $normalized['real_invoker_final_process_start_authorization_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-final-process-start-authorization-gate.v1',
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
            'operator_final_start_receipt_hash',
            'final_start_signature_hash',
            'final_start_policy_hash',
            'final_start_window_hash',
            'final_start_replay_guard_hash',
            'final_start_kill_switch_hash',
            'operator_guarded_start_receipt_hash',
            'process_runner_contract_hash',
            'dry_run_rehearsal_hash',
            'launch_invocation_contract_hash',
            'post_start_observability_hash',
            'revoke_guard_hash',
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
            'operator_final_start_receipt_hash',
            'final_start_signature_hash',
            'final_start_policy_hash',
            'final_start_window_hash',
            'final_start_replay_guard_hash',
            'final_start_kill_switch_hash',
            'operator_guarded_start_receipt_hash',
            'process_runner_contract_hash',
            'dry_run_rehearsal_hash',
            'launch_invocation_contract_hash',
            'post_start_observability_hash',
            'revoke_guard_hash',
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
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertGuardedStartPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_guarded_process_start.real_invoker_guarded_process_start_id') !== $normalized['real_invoker_guarded_process_start_id']) {
            throw new InvalidArgumentException('codex_real_invoker_guarded_process_start_missing_or_mismatch');
        }

        foreach ([
            'real_invoker_supervised_start_activation_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_plan_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_guarded_process_start.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_guarded_process_start.status') !== 'real_invoker_guarded_process_start_prepared_disabled_pending_final_start') {
            throw new InvalidArgumentException('codex_real_invoker_guarded_process_start_not_ready_for_final_authorization');
        }

        foreach ([
            'operator_guarded_start_receipt_hash',
            'process_runner_contract_hash',
            'dry_run_rehearsal_hash',
            'launch_invocation_contract_hash',
            'post_start_observability_hash',
            'revoke_guard_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_guarded_process_start.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_guarded_process_start.executor_enabled', false)) {
            throw new InvalidArgumentException('executor_not_enabled');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_guarded_process_start.process_start_armed', false)) {
            throw new InvalidArgumentException('process_start_not_armed');
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_guarded_process_start.'.$field, false)) {
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
            'status' => 'codex_real_invoker_final_process_start_authorization_prepared',
            'idempotent' => $idempotent,
            'real_invoker_final_process_start_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_final_process_start_authorization_id'),
            'real_invoker_guarded_process_start_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_guarded_process_start_id'),
            'real_invoker_supervised_start_activation_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_supervised_start_activation_id'),
            'real_invoker_executor_enablement_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_executor_enablement_id'),
            'real_invoker_executor_fresh_release_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_executor_fresh_release_id'),
            'real_invoker_executor_plan_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.real_invoker_executor_plan_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_final_process_start_authorization.adapter'),
            'real_invoker_final_process_start_authorization_prepared' => true,
            'final_process_start_authorized' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
