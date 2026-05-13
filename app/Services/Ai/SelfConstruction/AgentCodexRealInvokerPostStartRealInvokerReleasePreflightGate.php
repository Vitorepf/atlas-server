<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerPostStartRealInvokerReleasePreflightGate
{
    public function __construct(
        private readonly AgentCodexRealInvokerReleasePreflight $releasePreflight,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordPostStartRealInvokerReleasePreflight(array $input): array
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
            $existingPreflightId = (string) data_get($metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.real_invoker_release_preflight_id', '');

            if ($existingPreflightId !== '') {
                if ($existingPreflightId !== $normalized['real_invoker_release_preflight_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_post_start_real_invoker_release_preflight_already_recorded');
                }

                return $this->result($observedRun, idempotent: true, releasePreflightResult: null);
            }

            $this->assertPostStartDryRunReady($observedRun, $metadata, $normalized);

            $providerStartRunKey = 'provider-start:'.$normalized['provider_start_attempt_id'];
            $releasePreflightResult = $this->releasePreflight->recordPreflight([
                'run_key' => $providerStartRunKey,
                'codex_execution_id' => $normalized['codex_execution_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'dry_run_id' => $normalized['dry_run_id'],
                'real_invoker_release_preflight_id' => $normalized['real_invoker_release_preflight_id'],
                'operator_release_preflight_receipt_hash' => $normalized['operator_release_preflight_receipt_hash'],
                'real_invoker_contract_hash' => $normalized['real_invoker_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'rollback_plan_hash' => $normalized['rollback_plan_hash'],
                'max_runtime_policy_hash' => $normalized['max_runtime_policy_hash'],
                'actor' => $normalized['actor'],
                'session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ]);

            if ((string) data_get($releasePreflightResult, 'status') !== 'codex_real_invoker_release_preflight_recorded') {
                throw new InvalidArgumentException('codex_real_invoker_release_preflight_not_recorded');
            }

            if (! (bool) data_get($releasePreflightResult, 'real_invoker_release_preflight_passed', false)) {
                throw new InvalidArgumentException('real_invoker_release_preflight_passed_flag_missing');
            }

            foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
                if ((bool) data_get($releasePreflightResult, $field, false)) {
                    throw new InvalidArgumentException('codex_real_invoker_release_preflight_'.$field.'_unexpectedly_true');
                }
            }

            $metadata['codex_real_invoker_post_start_real_invoker_release_preflight'] = [
                'post_start_real_invoker_release_preflight_gate_id' => $normalized['post_start_real_invoker_release_preflight_gate_id'],
                'post_start_external_process_invoker_dry_run_gate_id' => $normalized['post_start_external_process_invoker_dry_run_gate_id'],
                'post_start_process_invocation_authorization_gate_id' => $normalized['post_start_process_invocation_authorization_gate_id'],
                'post_start_external_process_runtime_gate_id' => $normalized['post_start_external_process_runtime_gate_id'],
                'post_start_final_process_spawn_executor_gate_id' => $normalized['post_start_final_process_spawn_executor_gate_id'],
                'post_start_process_spawn_enablement_gate_id' => $normalized['post_start_process_spawn_enablement_gate_id'],
                'post_start_supervised_start_gate_id' => $normalized['post_start_supervised_start_gate_id'],
                'post_start_process_start_release_gate_id' => $normalized['post_start_process_start_release_gate_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'provider_execution_contract_gate_id' => $normalized['provider_execution_contract_gate_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'dry_run_id' => $normalized['dry_run_id'],
                'real_invoker_release_preflight_id' => $normalized['real_invoker_release_preflight_id'],
                'adapter_execution_guard_gate_id' => $normalized['adapter_execution_guard_gate_id'],
                'execution_guard_id' => $normalized['execution_guard_id'],
                'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
                'adapter_invocation_id' => $normalized['adapter_invocation_id'],
                'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
                'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
                'provider_start_run_key' => $providerStartRunKey,
                'post_start_evidence_acceptance_bridge_id' => $normalized['post_start_evidence_acceptance_bridge_id'],
                'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
                'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
                'operator_spawn_receipt_hash' => $normalized['operator_spawn_receipt_hash'],
                'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
                'operator_runtime_receipt_hash' => $normalized['operator_runtime_receipt_hash'],
                'operator_invocation_receipt_hash' => $normalized['operator_invocation_receipt_hash'],
                'operator_dry_run_receipt_hash' => $normalized['operator_dry_run_receipt_hash'],
                'operator_release_preflight_receipt_hash' => $normalized['operator_release_preflight_receipt_hash'],
                'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
                'supervised_start_contract_hash' => $normalized['supervised_start_contract_hash'],
                'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'runtime_driver_contract_hash' => $normalized['runtime_driver_contract_hash'],
                'invoker_contract_hash' => $normalized['invoker_contract_hash'],
                'real_invoker_contract_hash' => $normalized['real_invoker_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'rollback_plan_hash' => $normalized['rollback_plan_hash'],
                'max_runtime_policy_hash' => $normalized['max_runtime_policy_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'post_start_real_invoker_release_preflight_passed_pending_signed_release_gate',
                'post_start_real_invoker_release_preflight_recorded' => true,
                'real_invoker_release_preflight_passed' => true,
                'signed_release_gate_required' => true,
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
                'codex_real_invoker_release_preflight_result' => $releasePreflightResult,
                'recorded_by' => $normalized['actor'],
                'recorded_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $observedRun->forceFill([
                'summary' => 'Codex real invoker post-start release preflight passed; signed release gate remains required.',
                'metadata' => $metadata,
            ])->save();

            $observedRun->refresh();

            return $this->result($observedRun, idempotent: false, releasePreflightResult: $releasePreflightResult);
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
            'codex_execution_id',
            'supervised_start_id',
            'spawn_enablement_id',
            'spawn_executor_id',
            'runtime_driver_id',
            'invocation_authorization_id',
            'dry_run_id',
            'real_invoker_release_preflight_id',
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'operator_release_receipt_hash',
            'operator_spawn_receipt_hash',
            'operator_final_spawn_receipt_hash',
            'operator_runtime_receipt_hash',
            'operator_invocation_receipt_hash',
            'operator_dry_run_receipt_hash',
            'operator_release_preflight_receipt_hash',
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'runtime_driver_contract_hash',
            'invoker_contract_hash',
            'real_invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
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

        $hashes = [];
        foreach ([
            'signed_dispatch_receipt_hash',
            'operator_release_receipt_hash',
            'operator_spawn_receipt_hash',
            'operator_final_spawn_receipt_hash',
            'operator_runtime_receipt_hash',
            'operator_invocation_receipt_hash',
            'operator_dry_run_receipt_hash',
            'operator_release_preflight_receipt_hash',
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'runtime_driver_contract_hash',
            'invoker_contract_hash',
            'real_invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
        ] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = $hashes[$field] ?? (string) $input[$field];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertPostStartDryRunReady(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        $prefix = 'codex_real_invoker_post_start_external_process_invoker_dry_run.';

        foreach ([
            'post_start_external_process_invoker_dry_run_gate_id',
            'post_start_process_invocation_authorization_gate_id',
            'post_start_external_process_runtime_gate_id',
            'post_start_final_process_spawn_executor_gate_id',
            'post_start_process_spawn_enablement_gate_id',
            'post_start_supervised_start_gate_id',
            'post_start_process_start_release_gate_id',
            'process_start_release_id',
            'provider_execution_contract_gate_id',
            'codex_execution_id',
            'supervised_start_id',
            'spawn_enablement_id',
            'spawn_executor_id',
            'runtime_driver_id',
            'invocation_authorization_id',
            'dry_run_id',
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'operator_release_receipt_hash',
            'operator_spawn_receipt_hash',
            'operator_final_spawn_receipt_hash',
            'operator_runtime_receipt_hash',
            'operator_invocation_receipt_hash',
            'operator_dry_run_receipt_hash',
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'runtime_driver_contract_hash',
            'invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
        ] as $field) {
            if ((string) data_get($metadata, $prefix.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, $prefix.'status') !== 'post_start_external_process_invoker_dry_run_prepared_pending_real_invoker_execution_gate') {
            throw new InvalidArgumentException('codex_real_invoker_post_start_external_process_invoker_dry_run_not_ready_for_release_preflight');
        }

        foreach (['post_start_external_process_invoker_dry_run_recorded', 'external_process_invoker_dry_run_prepared', 'real_invoker_execution_gate_required', 'observed_external_process_started', 'observed_provider_started'] as $field) {
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
     * @param  array<string,mixed>|null  $releasePreflightResult
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?array $releasePreflightResult): array
    {
        return [
            'status' => 'codex_real_invoker_post_start_real_invoker_release_preflight_recorded',
            'idempotent' => $idempotent,
            'post_start_real_invoker_release_preflight_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_real_invoker_release_preflight_gate_id'),
            'post_start_external_process_invoker_dry_run_gate_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_external_process_invoker_dry_run_gate_id'),
            'real_invoker_release_preflight_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.real_invoker_release_preflight_id'),
            'dry_run_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.dry_run_id'),
            'invocation_authorization_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.invocation_authorization_id'),
            'runtime_driver_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.runtime_driver_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.codex_execution_id'),
            'post_start_evidence_acceptance_bridge_id' => (string) data_get($run->metadata, 'codex_real_invoker_post_start_real_invoker_release_preflight.post_start_evidence_acceptance_bridge_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'post_start_real_invoker_release_preflight_recorded' => true,
            'real_invoker_release_preflight_passed' => true,
            'signed_release_gate_required' => true,
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
            'codex_real_invoker_release_preflight_result' => $releasePreflightResult,
        ];
    }
}
