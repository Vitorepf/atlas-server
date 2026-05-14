<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerStartExecutionGate $startExecutionGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeCodexRealInvokerStartExecution(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->startExecutionGate->authorizeStartExecution($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_start_execution_gate_authorized',
            'codex_real_invoker_start_execution_gate_invoked' => true,
            'codex_real_invoker_start_execution_gate_invocation_count' => 1,
            'codex_real_invoker_start_execution_gate_result' => $result,
            'real_invoker_start_execution_gate_id' => data_get($result, 'real_invoker_start_execution_gate_id'),
            'real_invoker_process_start_envelope_id' => data_get($result, 'real_invoker_process_start_envelope_id'),
            'real_invoker_actual_process_start_rehearsal_id' => data_get($result, 'real_invoker_actual_process_start_rehearsal_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'real_invoker_start_execution_gate_authorized' => true,
            'start_envelope_ready' => true,
            'start_execution_authorized' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'provider_started' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_contract',
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

        foreach ([
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
        ] as $hashField) {
            $hash = strtolower(trim((string) $input[$hashField]));

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        return [
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
            'process_start_envelope_hash' => (string) $input['process_start_envelope_hash'],
            'start_command_hash' => (string) $input['start_command_hash'],
            'start_environment_hash' => (string) $input['start_environment_hash'],
            'start_cwd_hash' => (string) $input['start_cwd_hash'],
            'start_supervisor_hash' => (string) $input['start_supervisor_hash'],
            'start_liveness_contract_hash' => (string) $input['start_liveness_contract_hash'],
            'operator_execution_gate_receipt_hash' => (string) $input['operator_execution_gate_receipt_hash'],
            'execution_gate_policy_hash' => (string) $input['execution_gate_policy_hash'],
            'execution_window_hash' => (string) $input['execution_window_hash'],
            'preflight_snapshot_hash' => (string) $input['preflight_snapshot_hash'],
            'rollback_readiness_hash' => (string) $input['rollback_readiness_hash'],
            'human_start_signature_hash' => (string) $input['human_start_signature_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
