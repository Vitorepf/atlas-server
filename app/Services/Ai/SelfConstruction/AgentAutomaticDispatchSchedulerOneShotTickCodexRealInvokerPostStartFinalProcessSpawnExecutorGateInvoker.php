<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartFinalProcessSpawnExecutorGate $postStartFinalProcessSpawnExecutorGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartFinalProcessSpawnExecutorGate->preparePostStartFinalProcessSpawn($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_final_process_spawn_executor_prepared',
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_invoked' => true,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_final_process_spawn_executor_gate_result' => $result,
            'post_start_final_process_spawn_executor_gate_id' => data_get($result, 'post_start_final_process_spawn_executor_gate_id'),
            'post_start_process_spawn_enablement_gate_id' => data_get($result, 'post_start_process_spawn_enablement_gate_id'),
            'post_start_supervised_start_gate_id' => $normalized['post_start_supervised_start_gate_id'],
            'post_start_process_start_release_gate_id' => $normalized['post_start_process_start_release_gate_id'],
            'process_start_release_id' => data_get($result, 'process_start_release_id'),
            'provider_execution_contract_gate_id' => $normalized['provider_execution_contract_gate_id'],
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'supervised_start_id' => data_get($result, 'supervised_start_id'),
            'spawn_enablement_id' => data_get($result, 'spawn_enablement_id'),
            'spawn_executor_id' => data_get($result, 'spawn_executor_id'),
            'adapter_execution_guard_gate_id' => $normalized['adapter_execution_guard_gate_id'],
            'execution_guard_id' => $normalized['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => $normalized['adapter_invocation_id'],
            'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => $normalized['provider_start_attempt_id'],
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
            'operator_release_receipt_hash' => $normalized['operator_release_receipt_hash'],
            'operator_spawn_receipt_hash' => $normalized['operator_spawn_receipt_hash'],
            'operator_final_spawn_receipt_hash' => $normalized['operator_final_spawn_receipt_hash'],
            'codex_execution_contract_hash' => $normalized['codex_execution_contract_hash'],
            'supervised_start_contract_hash' => $normalized['supervised_start_contract_hash'],
            'runtime_supervision_plan_hash' => $normalized['runtime_supervision_plan_hash'],
            'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => $normalized['liveness_probe_hash'],
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'codex_process_spawn_executor_result' => data_get($result, 'codex_process_spawn_executor_result'),
            'post_start_final_process_spawn_executor_prepared' => data_get($result, 'post_start_final_process_spawn_executor_prepared'),
            'process_spawn_enabled' => data_get($result, 'process_spawn_enabled'),
            'process_spawn_executor_prepared' => data_get($result, 'process_spawn_executor_prepared'),
            'external_process_runtime_required' => data_get($result, 'external_process_runtime_required'),
            'observed_external_process_started' => data_get($result, 'observed_external_process_started'),
            'observed_provider_started' => data_get($result, 'observed_provider_started'),
            'actual_process_start_allowed' => data_get($result, 'actual_process_start_allowed'),
            'provider_process_call_allowed' => data_get($result, 'provider_process_call_allowed'),
            'adapter_invocation_allowed' => data_get($result, 'adapter_invocation_allowed'),
            'adapter_execution_allowed' => data_get($result, 'adapter_execution_allowed'),
            'token_spend_allowed' => data_get($result, 'token_spend_allowed'),
            'dispatch_allowed' => data_get($result, 'dispatch_allowed'),
            'self_programming_allowed' => false,
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract',
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
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
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
            'codex_execution_contract_hash',
            'supervised_start_contract_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
        ] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (preg_match('/^[a-f0-9]{64}$/', $hashes[$field]) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'post_start_final_process_spawn_executor_gate_id' => (string) $input['post_start_final_process_spawn_executor_gate_id'],
            'post_start_process_spawn_enablement_gate_id' => (string) $input['post_start_process_spawn_enablement_gate_id'],
            'post_start_supervised_start_gate_id' => (string) $input['post_start_supervised_start_gate_id'],
            'post_start_process_start_release_gate_id' => (string) $input['post_start_process_start_release_gate_id'],
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'provider_execution_contract_gate_id' => (string) $input['provider_execution_contract_gate_id'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'supervised_start_id' => (string) $input['supervised_start_id'],
            'spawn_enablement_id' => (string) $input['spawn_enablement_id'],
            'spawn_executor_id' => (string) $input['spawn_executor_id'],
            'adapter_execution_guard_gate_id' => (string) $input['adapter_execution_guard_gate_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'signed_dispatch_receipt_hash' => $hashes['signed_dispatch_receipt_hash'],
            'operator_release_receipt_hash' => $hashes['operator_release_receipt_hash'],
            'operator_spawn_receipt_hash' => $hashes['operator_spawn_receipt_hash'],
            'operator_final_spawn_receipt_hash' => $hashes['operator_final_spawn_receipt_hash'],
            'codex_execution_contract_hash' => $hashes['codex_execution_contract_hash'],
            'supervised_start_contract_hash' => $hashes['supervised_start_contract_hash'],
            'runtime_supervision_plan_hash' => $hashes['runtime_supervision_plan_hash'],
            'stdout_stderr_sink_hash' => $hashes['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => $hashes['liveness_probe_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
