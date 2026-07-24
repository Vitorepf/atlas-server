<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerFinalProcessStartAuthorizationGate $authorizationGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeCodexRealInvokerFinalProcessStart(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->authorizationGate->authorizeFinalStart($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_codex_real_invoker_final_process_start_authorization_prepared',
            'codex_real_invoker_final_process_start_authorization_gate_invoked' => true,
            'codex_real_invoker_final_process_start_authorization_gate_invocation_count' => 1,
            'codex_real_invoker_final_process_start_authorization_gate_result' => $result,
            'real_invoker_final_process_start_authorization_id' => data_get($result, 'real_invoker_final_process_start_authorization_id'),
            'real_invoker_guarded_process_start_id' => data_get($result, 'real_invoker_guarded_process_start_id'),
            'real_invoker_supervised_start_activation_id' => data_get($result, 'real_invoker_supervised_start_activation_id'),
            'real_invoker_executor_enablement_id' => data_get($result, 'real_invoker_executor_enablement_id'),
            'real_invoker_executor_fresh_release_id' => data_get($result, 'real_invoker_executor_fresh_release_id'),
            'real_invoker_executor_plan_id' => data_get($result, 'real_invoker_executor_plan_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'real_invoker_final_process_start_authorization_prepared' => true,
            'final_process_start_authorized' => true,
            'actual_process_start_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent')], 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_actual_process_start_rehearsal_contract');
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

        foreach ([
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
            'operator_final_start_receipt_hash' => (string) $input['operator_final_start_receipt_hash'],
            'final_start_signature_hash' => (string) $input['final_start_signature_hash'],
            'final_start_policy_hash' => (string) $input['final_start_policy_hash'],
            'final_start_window_hash' => (string) $input['final_start_window_hash'],
            'final_start_replay_guard_hash' => (string) $input['final_start_replay_guard_hash'],
            'final_start_kill_switch_hash' => (string) $input['final_start_kill_switch_hash'],
            'operator_guarded_start_receipt_hash' => (string) $input['operator_guarded_start_receipt_hash'],
            'process_runner_contract_hash' => (string) $input['process_runner_contract_hash'],
            'dry_run_rehearsal_hash' => (string) $input['dry_run_rehearsal_hash'],
            'launch_invocation_contract_hash' => (string) $input['launch_invocation_contract_hash'],
            'post_start_observability_hash' => (string) $input['post_start_observability_hash'],
            'revoke_guard_hash' => (string) $input['revoke_guard_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
