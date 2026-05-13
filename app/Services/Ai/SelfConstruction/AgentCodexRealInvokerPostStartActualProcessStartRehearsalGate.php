<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartActualProcessStartRehearsalGate
{
    /**
     * @var list<string>
     */
    private const BRIDGE_FIELDS = [
        'post_start_final_process_start_authorization_gate_id',
        'post_start_guarded_process_start_gate_id',
        'post_start_supervised_start_activation_gate_id',
        'post_start_executor_enablement_gate_id',
        'post_start_executor_fresh_release_gate_id',
        'post_start_executor_plan_gate_id',
        'post_start_implementation_boundary_gate_id',
        'post_start_signed_real_invoker_release_gate_id',
        'post_start_real_invoker_release_preflight_gate_id',
        'post_start_external_process_invoker_dry_run_gate_id',
        'post_start_process_invocation_authorization_gate_id',
        'post_start_external_process_runtime_gate_id',
        'post_start_final_process_spawn_executor_gate_id',
        'post_start_process_spawn_enablement_gate_id',
        'post_start_supervised_start_gate_id',
        'post_start_process_start_release_gate_id',
        'process_start_release_id',
        'provider_execution_contract_gate_id',
        'adapter_execution_guard_gate_id',
        'execution_guard_id',
        'adapter_invocation_boundary_gate_id',
        'adapter_invocation_id',
        'provider_start_driver_gate_id',
        'provider_start_attempt_id',
    ];

    /**
     * @var list<string>
     */
    private const CHAIN_FIELDS = [
        'codex_execution_id',
        'supervised_start_id',
        'spawn_enablement_id',
        'spawn_executor_id',
        'runtime_driver_id',
        'invocation_authorization_id',
        'dry_run_id',
        'real_invoker_release_preflight_id',
        'signed_real_invoker_release_id',
        'real_invoker_implementation_boundary_id',
        'real_invoker_executor_plan_id',
        'real_invoker_executor_fresh_release_id',
        'real_invoker_executor_enablement_id',
        'real_invoker_supervised_start_activation_id',
        'real_invoker_guarded_process_start_id',
        'real_invoker_final_process_start_authorization_id',
    ];

    /**
     * @var list<string>
     */
    private const HASH_FIELDS = [
        'signed_dispatch_receipt_hash',
        'operator_release_receipt_hash',
        'operator_spawn_receipt_hash',
        'operator_final_spawn_receipt_hash',
        'operator_runtime_receipt_hash',
        'operator_invocation_receipt_hash',
        'operator_dry_run_receipt_hash',
        'operator_release_preflight_receipt_hash',
        'operator_signed_release_receipt_hash',
        'operator_implementation_boundary_receipt_hash',
        'operator_executor_plan_receipt_hash',
        'operator_fresh_release_receipt_hash',
        'operator_enablement_receipt_hash',
        'operator_start_activation_receipt_hash',
        'operator_guarded_start_receipt_hash',
        'operator_final_start_receipt_hash',
        'enablement_policy_hash',
        'pre_start_checklist_hash',
        'disable_switch_hash',
        'start_window_hash',
        'process_start_guard_hash',
        'supervisor_observer_hash',
        'pid_guard_hash',
        'cwd_integrity_hash',
        'process_runner_contract_hash',
        'dry_run_rehearsal_hash',
        'launch_invocation_contract_hash',
        'post_start_observability_hash',
        'revoke_guard_hash',
        'final_start_signature_hash',
        'final_start_policy_hash',
        'final_start_window_hash',
        'final_start_replay_guard_hash',
        'final_start_kill_switch_hash',
        'process_start_rehearsal_hash',
        'command_resolution_hash',
        'environment_resolution_hash',
        'cwd_verification_hash',
        'supervisor_dry_run_hash',
        'liveness_probe_rehearsal_hash',
        'plan_revalidation_report_hash',
        'freshness_window_hash',
        'final_human_signature_hash',
        'signature_verification_report_hash',
        'codex_execution_contract_hash',
        'supervised_start_contract_hash',
        'runtime_supervision_plan_hash',
        'stdout_stderr_sink_hash',
        'liveness_probe_hash',
        'runtime_driver_contract_hash',
        'invoker_contract_hash',
        'real_invoker_contract_hash',
        'release_policy_hash',
        'implementation_plan_hash',
        'executor_binary_contract_hash',
        'executor_observability_contract_hash',
        'process_command_hash',
        'environment_contract_hash',
        'termination_policy_hash',
        'rollback_plan_hash',
        'max_runtime_policy_hash',
    ];

    /**
     * @var list<string>
     */
    private const REHEARSAL_HASH_FIELDS = [
        'process_start_rehearsal_hash',
        'command_resolution_hash',
        'environment_resolution_hash',
        'cwd_verification_hash',
        'supervisor_dry_run_hash',
        'liveness_probe_rehearsal_hash',
    ];

    public function __construct(
        private readonly AgentCodexRealInvokerActualProcessStartRehearsalExecutor $rehearsalExecutor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function rehearsePostStartActualProcessStart(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', 'atlas_ledger_events'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        return DB::transaction(function () use ($normalized): array {
            $observedRun = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $observedRun instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $observedRun->metadata;
            $existingRehearsalId = (string) data_get($metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_actual_process_start_rehearsal_id', '');

            if ($existingRehearsalId !== '') {
                if ($existingRehearsalId !== $normalized['real_invoker_actual_process_start_rehearsal_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_actual_process_start_rehearsal_already_recorded');
                }

                return $this->result($observedRun, idempotent: true, rehearsalResult: null);
            }

            $this->assertPostStartFinalAuthorizationPrepared($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $rehearsalResult = $this->rehearsalExecutor->rehearseActualStart($this->baseRehearsalInput($normalized, $providerStartRunKey));

            if ((string) data_get($rehearsalResult, 'status') !== 'codex_real_invoker_actual_process_start_rehearsal_prepared') {
                throw new InvalidArgumentException('codex_real_invoker_actual_process_start_rehearsal_not_prepared');
            }

            foreach (['real_invoker_actual_process_start_rehearsal_prepared', 'final_process_start_authorized', 'process_start_rehearsed'] as $field) {
                if (! (bool) data_get($rehearsalResult, $field, false)) {
                    throw new InvalidArgumentException('codex_real_invoker_actual_process_start_rehearsal_'.$field.'_missing');
                }
            }

            foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($rehearsalResult, $field, false)) {
                    throw new InvalidArgumentException('codex_real_invoker_actual_process_start_rehearsal_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_actual_process_start_rehearsal'] = array_merge(
                Arr::only($normalized, array_merge(
                    ['post_start_actual_process_start_rehearsal_gate_id', 'real_invoker_actual_process_start_rehearsal_id'],
                    self::BRIDGE_FIELDS,
                    self::CHAIN_FIELDS,
                    self::HASH_FIELDS,
                )),
                [
                    'provider_start_run_key' => $providerStartRunKey,
                    'provider' => 'codex',
                    'adapter' => 'codex',
                    'status' => 'post_start_actual_process_start_rehearsed_pending_start_envelope',
                    'post_start_actual_process_start_rehearsal_recorded' => true,
                    'real_invoker_actual_process_start_rehearsal_prepared' => true,
                    'final_process_start_authorized' => true,
                    'process_start_rehearsed' => true,
                    'observed_external_process_started' => true,
                    'observed_provider_started' => true,
                    'actual_process_start_allowed' => false,
                    'external_process_started' => false,
                    'token_spend_allowed' => false,
                    'provider_process_call_allowed' => false,
                    'provider_started' => false,
                    'adapter_invocation_allowed' => false,
                    'adapter_execution_allowed' => false,
                    'dispatch_allowed' => false,
                    'codex_real_invoker_actual_process_start_rehearsal_result' => $rehearsalResult,
                    'recorded_by' => $normalized['actor'],
                    'recorded_session' => $normalized['session'],
                    'reason' => $normalized['reason'],
                ],
            );

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start actual process start rehearsal recorded; process start envelope still controls execution.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, rehearsalResult: $rehearsalResult);
        });
    }

    /**
     * @param  array<string,string>  $normalized
     * @return array<string,string>
     */
    private function baseRehearsalInput(array $normalized, string $providerStartRunKey): array
    {
        return array_merge(Arr::only($normalized, [
            'codex_execution_id',
            'real_invoker_executor_plan_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_supervised_start_activation_id',
            'real_invoker_guarded_process_start_id',
            'real_invoker_final_process_start_authorization_id',
            'real_invoker_actual_process_start_rehearsal_id',
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
            'actor',
            'session',
            'reason',
        ]), [
            'run_key' => $providerStartRunKey,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string>
     */
    private function normalize(array $input): array
    {
        $required = array_merge(
            ['run_key', 'post_start_actual_process_start_rehearsal_gate_id', 'real_invoker_actual_process_start_rehearsal_id'],
            self::BRIDGE_FIELDS,
            self::CHAIN_FIELDS,
            self::HASH_FIELDS,
            ['actor', 'session', 'reason'],
        );

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = strtolower(trim((string) $input[$field]));
        }

        foreach (self::HASH_FIELDS as $field) {
            if (! preg_match('/^[a-f0-9]{64}$/', $normalized[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        foreach (array_diff($required, self::HASH_FIELDS) as $field) {
            $normalized[$field] = (string) $input[$field];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,string>  $normalized
     */
    private function assertPostStartFinalAuthorizationPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_final_process_start_authorization.';
        $preRehearsalHashFields = array_values(array_diff(self::HASH_FIELDS, self::REHEARSAL_HASH_FIELDS));

        foreach (array_merge(self::BRIDGE_FIELDS, self::CHAIN_FIELDS, $preRehearsalHashFields) as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_final_process_start_authorized_pending_actual_start_rehearsal') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_final_process_start_authorization_not_ready_for_rehearsal');
        }

        foreach (['post_start_final_process_start_authorization_recorded', 'real_invoker_final_process_start_authorization_prepared', 'final_process_start_authorized', 'observed_external_process_started', 'observed_provider_started'] as $field) {
            if (! (bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_not_true');
            }
        }

        foreach (['actual_process_start_allowed', 'external_process_started', 'token_spend_allowed', 'provider_process_call_allowed', 'provider_started', 'adapter_invocation_allowed', 'adapter_execution_allowed', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, $prefix.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }
    }

    /**
     * @param  array<string,mixed>|null  $rehearsalResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $rehearsalResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_actual_process_start_rehearsal_prepared',
            'idempotent' => $idempotent,
            'post_start_actual_process_start_rehearsal_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.post_start_actual_process_start_rehearsal_gate_id'),
            'real_invoker_actual_process_start_rehearsal_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_actual_process_start_rehearsal_id'),
            'real_invoker_final_process_start_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_final_process_start_authorization_id'),
            'real_invoker_guarded_process_start_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.real_invoker_guarded_process_start_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_actual_process_start_rehearsal.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'post_start_actual_process_start_rehearsal_recorded' => true,
            'real_invoker_actual_process_start_rehearsal_prepared' => true,
            'final_process_start_authorized' => true,
            'process_start_rehearsed' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_process_call_allowed' => false,
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'dispatch_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_result' => $rehearsalResult,
        ];
    }
}
