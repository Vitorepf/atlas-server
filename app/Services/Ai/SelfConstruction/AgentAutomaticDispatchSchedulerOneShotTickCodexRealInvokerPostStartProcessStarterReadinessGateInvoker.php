<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker
{
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
        'process_starter_manifest_hash',
        'supervisor_binding_hash',
        'liveness_monitor_binding_hash',
        'cancellation_contract_hash',
        'output_capture_contract_hash',
        'cost_meter_contract_hash',
        'start_replay_guard_hash',
        'operator_process_starter_signature_hash',
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
    private const IDENTIFIER_FIELDS = [
        'run_key',
        'post_start_process_starter_readiness_gate_id',
        'real_invoker_process_starter_readiness_gate_id',
        'post_start_start_execution_gate_id',
        'post_start_evidence_acceptance_bridge_id',
        'post_start_process_start_envelope_gate_id',
        'post_start_actual_process_start_rehearsal_gate_id',
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
        'real_invoker_actual_process_start_rehearsal_id',
        'real_invoker_process_start_envelope_id',
        'real_invoker_start_execution_gate_id',
        'adapter_execution_guard_gate_id',
        'execution_guard_id',
        'adapter_invocation_boundary_gate_id',
        'adapter_invocation_id',
        'provider_start_driver_gate_id',
        'provider_start_attempt_id',
        'actor',
        'session',
        'reason',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_TRUE_FLAGS = [
        'actual_process_start_allowed',
        'provider_process_call_allowed',
        'adapter_invocation_allowed',
        'adapter_execution_allowed',
        'token_spend_allowed',
        'dispatch_allowed',
        'self_programming_allowed',
        'external_process_started',
        'provider_started',
        'process_started',
    ];

    public function __construct(
        private readonly AgentCodexRealInvokerPostStartProcessStarterReadinessGate $postStartProcessStarterReadinessGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartProcessStarterReadinessGate(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartProcessStarterReadinessGate->preparePostStartProcessStarterReadiness($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_process_starter_readiness_gate_prepared',
            'codex_real_invoker_post_start_process_starter_readiness_gate_invoked' => true,
            'codex_real_invoker_post_start_process_starter_readiness_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_process_starter_readiness_gate_result' => $result,
            'post_start_process_starter_readiness_gate_id' => data_get($result, 'post_start_process_starter_readiness_gate_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'real_invoker_process_starter_readiness_gate_id' => data_get($result, 'real_invoker_process_starter_readiness_gate_id'),
            'real_invoker_start_execution_gate_id' => data_get($result, 'real_invoker_start_execution_gate_id'),
            'real_invoker_process_start_envelope_id' => data_get($result, 'real_invoker_process_start_envelope_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'post_start_process_starter_readiness_gate_prepared' => data_get($result, 'post_start_process_starter_readiness_gate_prepared'),
            'real_invoker_process_starter_readiness_gate_prepared' => data_get($result, 'real_invoker_process_starter_readiness_gate_prepared'),
            'process_starter_ready' => (bool) data_get($result, 'process_starter_ready', false),
            'process_starter_readiness_gate_ready' => (bool) data_get($result, 'process_starter_ready', false),
            'manual_start_executor_receipt_required' => true,
            'start_execution_authorized' => true,
            'observed_external_process_started' => data_get($result, 'observed_external_process_started'),
            'observed_provider_started' => data_get($result, 'observed_provider_started'),
            'actual_process_start_allowed' => data_get($result, 'actual_process_start_allowed'),
            'external_process_started' => data_get($result, 'external_process_started'),
            'provider_process_call_allowed' => data_get($result, 'provider_process_call_allowed'),
            'provider_started' => data_get($result, 'provider_started'),
            'process_started' => false,
            'adapter_invocation_allowed' => data_get($result, 'adapter_invocation_allowed'),
            'adapter_execution_allowed' => data_get($result, 'adapter_execution_allowed'),
            'token_spend_allowed' => data_get($result, 'token_spend_allowed'),
            'dispatch_allowed' => data_get($result, 'dispatch_allowed'),
            'self_programming_allowed' => false,
            'idempotent' => data_get($result, 'idempotent'),
            'codex_real_invoker_process_starter_readiness_gate_result' => data_get($result, 'codex_real_invoker_process_starter_readiness_gate_result'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        foreach (self::FORBIDDEN_TRUE_FLAGS as $flag) {
            if (Arr::has($input, $flag) && (bool) $input[$flag] === true) {
                throw new InvalidArgumentException('forbidden_true_flag_'.$flag);
            }
        }

        $required = array_merge(self::IDENTIFIER_FIELDS, self::HASH_FIELDS);

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [];
        foreach (self::HASH_FIELDS as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (preg_match('/^[a-f0-9]{64}$/', $hashes[$field]) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $normalized = [];
        foreach (self::IDENTIFIER_FIELDS as $field) {
            $normalized[$field] = (string) $input[$field];
        }
        foreach (self::HASH_FIELDS as $field) {
            $normalized[$field] = $hashes[$field];
        }

        return $normalized;
    }
}
