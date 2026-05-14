<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartImplementationBoundaryGate $postStartImplementationBoundaryGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartImplementationBoundaryGate(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartImplementationBoundaryGate->preparePostStartImplementationBoundary($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_implementation_boundary_prepared',
            'codex_real_invoker_post_start_implementation_boundary_gate_invoked' => true,
            'codex_real_invoker_post_start_implementation_boundary_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_implementation_boundary_gate_result' => $result,
            'post_start_implementation_boundary_gate_id' => data_get($result, 'post_start_implementation_boundary_gate_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'post_start_signed_real_invoker_release_gate_id' => data_get($result, 'post_start_signed_real_invoker_release_gate_id'),
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
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'supervised_start_id' => $normalized['supervised_start_id'],
            'spawn_enablement_id' => $normalized['spawn_enablement_id'],
            'spawn_executor_id' => $normalized['spawn_executor_id'],
            'runtime_driver_id' => $normalized['runtime_driver_id'],
            'invocation_authorization_id' => $normalized['invocation_authorization_id'],
            'dry_run_id' => data_get($result, 'dry_run_id'),
            'real_invoker_release_preflight_id' => data_get($result, 'real_invoker_release_preflight_id'),
            'signed_real_invoker_release_id' => data_get($result, 'signed_real_invoker_release_id'),
            'real_invoker_implementation_boundary_id' => data_get($result, 'real_invoker_implementation_boundary_id'),
            'adapter_execution_guard_gate_id' => $normalized['adapter_execution_guard_gate_id'],
            'execution_guard_id' => $normalized['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => $normalized['adapter_invocation_id'],
            'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
            'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
            'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
            'operator_spawn_receipt_hash' => $normalized['operator_spawn_receipt_hash'],
            'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
            'operator_runtime_receipt_hash' => $normalized['operator_runtime_receipt_hash'],
            'operator_invocation_receipt_hash' => $normalized['operator_invocation_receipt_hash'],
            'operator_dry_run_receipt_hash' => $normalized['operator_dry_run_receipt_hash'],
            'operator_release_preflight_receipt_hash' => $normalized['operator_release_preflight_receipt_hash'],
            'operator_signed_release_receipt_hash' => $normalized['operator_signed_release_receipt_hash'],
            'operator_implementation_boundary_receipt_hash' => $normalized['operator_implementation_boundary_receipt_hash'],
            'signature_verification_report_hash' => $normalized['signature_verification_report_hash'],
            'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
            'supervised_start_contract_hash' => $normalized['supervised_start_contract_hash'],
            'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
            'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => $normalized['liveness_probe_hash'],
            'runtime_driver_contract_hash' => $normalized['runtime_driver_contract_hash'],
            'invoker_contract_hash' => $normalized['invoker_contract_hash'],
            'real_invoker_contract_hash' => $normalized['real_invoker_contract_hash'],
            'release_policy_hash' => $normalized['release_policy_hash'],
            'implementation_plan_hash' => $normalized['implementation_plan_hash'],
            'process_command_hash' => $normalized['process_command_hash'],
            'environment_contract_hash' => $normalized['environment_contract_hash'],
            'termination_policy_hash' => $normalized['termination_policy_hash'],
            'rollback_plan_hash' => $normalized['rollback_plan_hash'],
            'max_runtime_policy_hash' => $normalized['max_runtime_policy_hash'],
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'codex_real_invoker_implementation_boundary_result' => data_get($result, 'codex_real_invoker_implementation_boundary_result'),
            'post_start_implementation_boundary_recorded' => data_get($result, 'post_start_implementation_boundary_recorded'),
            'real_invoker_implementation_boundary_prepared' => data_get($result, 'real_invoker_implementation_boundary_prepared'),
            'executor_plan_required' => data_get($result, 'executor_plan_required'),
            'observed_external_process_started' => data_get($result, 'observed_external_process_started'),
            'observed_provider_started' => data_get($result, 'observed_provider_started'),
            'actual_process_start_allowed' => data_get($result, 'actual_process_start_allowed'),
            'external_process_started' => data_get($result, 'external_process_started'),
            'provider_process_call_allowed' => data_get($result, 'provider_process_call_allowed'),
            'provider_started' => data_get($result, 'provider_started'),
            'adapter_invocation_allowed' => data_get($result, 'adapter_invocation_allowed'),
            'adapter_execution_allowed' => data_get($result, 'adapter_execution_allowed'),
            'token_spend_allowed' => data_get($result, 'token_spend_allowed'),
            'dispatch_allowed' => data_get($result, 'dispatch_allowed'),
            'self_programming_allowed' => false,
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'run_key',
            'post_start_implementation_boundary_gate_id',
            'post_start_evidence_acceptance_bridge_id',
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
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
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
            'operator_signed_release_receipt_hash',
            'operator_implementation_boundary_receipt_hash',
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
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
        ] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (preg_match('/^[a-f0-9]{64}$/', $hashes[$field]) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = $hashes[$field] ?? (string) $input[$field];
        }

        return $normalized;
    }
}
