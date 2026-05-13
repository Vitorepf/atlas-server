<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerGuardedProcessStartExecutor
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareGuardedStart(array $input): array
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
            $existingGuardedStartId = (string) data_get($metadata, 'codex_real_invoker_guarded_process_start.real_invoker_guarded_process_start_id', '');

            if ($existingGuardedStartId !== '') {
                if ($existingGuardedStartId !== $normalized['real_invoker_guarded_process_start_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_guarded_process_start_already_prepared');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertActivationPrepared($run, $metadata, $normalized);

            $metadata['codex_real_invoker_guarded_process_start'] = [
                'real_invoker_guarded_process_start_id' => $normalized['real_invoker_guarded_process_start_id'],
                'real_invoker_supervised_start_activation_id' => $normalized['real_invoker_supervised_start_activation_id'],
                'real_invoker_executor_enablement_id' => $normalized['real_invoker_executor_enablement_id'],
                'real_invoker_executor_fresh_release_id' => $normalized['real_invoker_executor_fresh_release_id'],
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'real_invoker_implementation_boundary_id' => $normalized['real_invoker_implementation_boundary_id'],
                'signed_real_invoker_release_id' => $normalized['signed_real_invoker_release_id'],
                'real_invoker_release_preflight_id' => $normalized['real_invoker_release_preflight_id'],
                'dry_run_id' => $normalized['dry_run_id'],
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_guarded_start_receipt_hash' => $normalized['operator_guarded_start_receipt_hash'],
                'process_runner_contract_hash' => $normalized['process_runner_contract_hash'],
                'dry_run_rehearsal_hash' => $normalized['dry_run_rehearsal_hash'],
                'launch_invocation_contract_hash' => $normalized['launch_invocation_contract_hash'],
                'post_start_observability_hash' => $normalized['post_start_observability_hash'],
                'revoke_guard_hash' => $normalized['revoke_guard_hash'],
                'operator_start_activation_receipt_hash' => $normalized['operator_start_activation_receipt_hash'],
                'start_window_hash' => $normalized['start_window_hash'],
                'process_start_guard_hash' => $normalized['process_start_guard_hash'],
                'supervisor_observer_hash' => $normalized['supervisor_observer_hash'],
                'pid_guard_hash' => $normalized['pid_guard_hash'],
                'cwd_integrity_hash' => $normalized['cwd_integrity_hash'],
                'operator_enablement_receipt_hash' => $normalized['operator_enablement_receipt_hash'],
                'enablement_policy_hash' => $normalized['enablement_policy_hash'],
                'pre_start_checklist_hash' => $normalized['pre_start_checklist_hash'],
                'disable_switch_hash' => $normalized['disable_switch_hash'],
                'operator_fresh_release_receipt_hash' => $normalized['operator_fresh_release_receipt_hash'],
                'plan_revalidation_report_hash' => $normalized['plan_revalidation_report_hash'],
                'freshness_window_hash' => $normalized['freshness_window_hash'],
                'final_human_signature_hash' => $normalized['final_human_signature_hash'],
                'real_invoker_contract_hash' => $normalized['real_invoker_contract_hash'],
                'release_policy_hash' => $normalized['release_policy_hash'],
                'implementation_plan_hash' => $normalized['implementation_plan_hash'],
                'executor_binary_contract_hash' => $normalized['executor_binary_contract_hash'],
                'executor_observability_contract_hash' => $normalized['executor_observability_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'rollback_plan_hash' => $normalized['rollback_plan_hash'],
                'max_runtime_policy_hash' => $normalized['max_runtime_policy_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_guarded_process_start_prepared_disabled_pending_final_start',
                'real_invoker_guarded_process_start_prepared' => true,
                'executor_enabled' => true,
                'process_start_armed' => true,
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
                'summary' => 'Codex real invoker guarded process start prepared; actual process start remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_guarded_process_start.prepared',
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
                'operator_guarded_start_receipt_hash' => $normalized['operator_guarded_start_receipt_hash'],
                'process_runner_contract_hash' => $normalized['process_runner_contract_hash'],
                'dry_run_rehearsal_hash' => $normalized['dry_run_rehearsal_hash'],
                'launch_invocation_contract_hash' => $normalized['launch_invocation_contract_hash'],
                'post_start_observability_hash' => $normalized['post_start_observability_hash'],
                'revoke_guard_hash' => $normalized['revoke_guard_hash'],
                'real_invoker_guarded_process_start_prepared' => true,
                'executor_enabled' => true,
                'process_start_armed' => true,
                'actual_process_start_allowed' => false,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_guarded_process_start',
                'receipt_id' => $normalized['real_invoker_guarded_process_start_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-guarded-process-start-executor.v1',
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
            'dry_run_id',
            'real_invoker_release_preflight_id',
            'signed_real_invoker_release_id',
            'real_invoker_implementation_boundary_id',
            'real_invoker_executor_plan_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_enablement_id',
            'real_invoker_supervised_start_activation_id',
            'real_invoker_guarded_process_start_id',
            'operator_guarded_start_receipt_hash',
            'process_runner_contract_hash',
            'dry_run_rehearsal_hash',
            'launch_invocation_contract_hash',
            'post_start_observability_hash',
            'revoke_guard_hash',
            'operator_start_activation_receipt_hash',
            'start_window_hash',
            'process_start_guard_hash',
            'supervisor_observer_hash',
            'pid_guard_hash',
            'cwd_integrity_hash',
            'operator_enablement_receipt_hash',
            'enablement_policy_hash',
            'pre_start_checklist_hash',
            'disable_switch_hash',
            'operator_fresh_release_receipt_hash',
            'plan_revalidation_report_hash',
            'freshness_window_hash',
            'final_human_signature_hash',
            'real_invoker_contract_hash',
            'release_policy_hash',
            'implementation_plan_hash',
            'executor_binary_contract_hash',
            'executor_observability_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
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
            'operator_guarded_start_receipt_hash',
            'process_runner_contract_hash',
            'dry_run_rehearsal_hash',
            'launch_invocation_contract_hash',
            'post_start_observability_hash',
            'revoke_guard_hash',
            'operator_start_activation_receipt_hash',
            'start_window_hash',
            'process_start_guard_hash',
            'supervisor_observer_hash',
            'pid_guard_hash',
            'cwd_integrity_hash',
            'operator_enablement_receipt_hash',
            'enablement_policy_hash',
            'pre_start_checklist_hash',
            'disable_switch_hash',
            'operator_fresh_release_receipt_hash',
            'plan_revalidation_report_hash',
            'freshness_window_hash',
            'final_human_signature_hash',
            'real_invoker_contract_hash',
            'release_policy_hash',
            'implementation_plan_hash',
            'executor_binary_contract_hash',
            'executor_observability_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
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
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'supervised_start_id' => (string) $input['supervised_start_id'],
            'spawn_enablement_id' => (string) $input['spawn_enablement_id'],
            'spawn_executor_id' => (string) $input['spawn_executor_id'],
            'runtime_driver_id' => (string) $input['runtime_driver_id'],
            'invocation_authorization_id' => (string) $input['invocation_authorization_id'],
            'dry_run_id' => (string) $input['dry_run_id'],
            'real_invoker_release_preflight_id' => (string) $input['real_invoker_release_preflight_id'],
            'signed_real_invoker_release_id' => (string) $input['signed_real_invoker_release_id'],
            'real_invoker_implementation_boundary_id' => (string) $input['real_invoker_implementation_boundary_id'],
            'real_invoker_executor_plan_id' => (string) $input['real_invoker_executor_plan_id'],
            'real_invoker_executor_fresh_release_id' => (string) $input['real_invoker_executor_fresh_release_id'],
            'real_invoker_executor_enablement_id' => (string) $input['real_invoker_executor_enablement_id'],
            'real_invoker_supervised_start_activation_id' => (string) $input['real_invoker_supervised_start_activation_id'],
            'real_invoker_guarded_process_start_id' => (string) $input['real_invoker_guarded_process_start_id'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ], $hashes);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertActivationPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_supervised_start_activation_id') !== $normalized['real_invoker_supervised_start_activation_id']) {
            throw new InvalidArgumentException('codex_real_invoker_supervised_start_activation_missing_or_mismatch');
        }

        foreach ([
            'real_invoker_executor_enablement_id',
            'real_invoker_executor_fresh_release_id',
            'real_invoker_executor_plan_id',
            'real_invoker_implementation_boundary_id',
            'signed_real_invoker_release_id',
            'real_invoker_release_preflight_id',
            'dry_run_id',
            'invocation_authorization_id',
            'runtime_driver_id',
            'spawn_executor_id',
            'spawn_enablement_id',
            'supervised_start_id',
            'process_start_release_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_supervised_start_activation.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_supervised_start_activation.status') !== 'real_invoker_supervised_start_activation_prepared_pending_process_start') {
            throw new InvalidArgumentException('codex_real_invoker_supervised_start_activation_not_ready_for_guarded_process_start');
        }

        foreach ([
            'operator_start_activation_receipt_hash',
            'start_window_hash',
            'process_start_guard_hash',
            'supervisor_observer_hash',
            'pid_guard_hash',
            'cwd_integrity_hash',
            'operator_enablement_receipt_hash',
            'enablement_policy_hash',
            'pre_start_checklist_hash',
            'disable_switch_hash',
            'operator_fresh_release_receipt_hash',
            'plan_revalidation_report_hash',
            'freshness_window_hash',
            'final_human_signature_hash',
            'real_invoker_contract_hash',
            'release_policy_hash',
            'implementation_plan_hash',
            'executor_binary_contract_hash',
            'executor_observability_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_supervised_start_activation.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_supervised_start_activation.executor_enabled', false)) {
            throw new InvalidArgumentException('executor_not_enabled');
        }

        if (! (bool) data_get($metadata, 'codex_real_invoker_supervised_start_activation.process_start_armed', false)) {
            throw new InvalidArgumentException('process_start_not_armed');
        }

        foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_supervised_start_activation.'.$field, false)) {
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
            'status' => 'codex_real_invoker_guarded_process_start_prepared',
            'idempotent' => $idempotent,
            'real_invoker_guarded_process_start_id' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_guarded_process_start_id'),
            'real_invoker_supervised_start_activation_id' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_supervised_start_activation_id'),
            'real_invoker_executor_enablement_id' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_executor_enablement_id'),
            'real_invoker_executor_fresh_release_id' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_executor_fresh_release_id'),
            'real_invoker_executor_plan_id' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.real_invoker_executor_plan_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_guarded_process_start.adapter'),
            'real_invoker_guarded_process_start_prepared' => true,
            'executor_enabled' => true,
            'process_start_armed' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
