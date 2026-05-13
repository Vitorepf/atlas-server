<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerActualProcessStartRehearsalExecutor $rehearsalExecutor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function rehearseCodexRealInvokerActualProcessStart(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->rehearsalExecutor->rehearseActualStart($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_actual_process_start_rehearsal_prepared',
            'codex_real_invoker_actual_process_start_rehearsal_executor_invoked' => true,
            'codex_real_invoker_actual_process_start_rehearsal_executor_invocation_count' => 1,
            'codex_real_invoker_actual_process_start_rehearsal_executor_result' => $result,
            'real_invoker_actual_process_start_rehearsal_id' => data_get($result, 'real_invoker_actual_process_start_rehearsal_id'),
            'real_invoker_final_process_start_authorization_id' => data_get($result, 'real_invoker_final_process_start_authorization_id'),
            'real_invoker_guarded_process_start_id' => data_get($result, 'real_invoker_guarded_process_start_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'real_invoker_actual_process_start_rehearsal_prepared' => true,
            'final_process_start_authorized' => true,
            'process_start_rehearsed' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'provider_started' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_contract',
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
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
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
            'process_start_rehearsal_hash' => (string) $input['process_start_rehearsal_hash'],
            'command_resolution_hash' => (string) $input['command_resolution_hash'],
            'environment_resolution_hash' => (string) $input['environment_resolution_hash'],
            'cwd_verification_hash' => (string) $input['cwd_verification_hash'],
            'supervisor_dry_run_hash' => (string) $input['supervisor_dry_run_hash'],
            'liveness_probe_rehearsal_hash' => (string) $input['liveness_probe_rehearsal_hash'],
            'operator_final_start_receipt_hash' => (string) $input['operator_final_start_receipt_hash'],
            'final_start_signature_hash' => (string) $input['final_start_signature_hash'],
            'final_start_policy_hash' => (string) $input['final_start_policy_hash'],
            'final_start_window_hash' => (string) $input['final_start_window_hash'],
            'final_start_replay_guard_hash' => (string) $input['final_start_replay_guard_hash'],
            'final_start_kill_switch_hash' => (string) $input['final_start_kill_switch_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
