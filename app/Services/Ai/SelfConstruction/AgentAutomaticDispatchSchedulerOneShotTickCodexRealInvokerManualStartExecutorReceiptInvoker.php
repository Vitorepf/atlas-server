<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerManualStartExecutorReceiptWriter $manualStartExecutorReceiptWriter,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function writeCodexRealInvokerManualStartExecutorReceipt(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->manualStartExecutorReceiptWriter->writeManualStartExecutorReceipt($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_manual_start_executor_receipt_written',
            'codex_real_invoker_manual_start_executor_receipt_invoked' => true,
            'codex_real_invoker_manual_start_executor_receipt_invocation_count' => 1,
            'codex_real_invoker_manual_start_executor_receipt_result' => $result,
            'manual_start_executor_receipt_id' => data_get($result, 'manual_start_executor_receipt_id'),
            'real_invoker_process_starter_readiness_gate_id' => data_get($result, 'real_invoker_process_starter_readiness_gate_id'),
            'real_invoker_start_execution_gate_id' => data_get($result, 'real_invoker_start_execution_gate_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'manual_start_executor_receipt_written' => true,
            'manual_operator_start_required' => true,
            'process_starter_ready' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => false,
            'provider_started' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_contract',
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
            'real_invoker_process_starter_readiness_gate_id',
            'manual_start_executor_receipt_id',
            'process_starter_manifest_hash',
            'supervisor_binding_hash',
            'liveness_monitor_binding_hash',
            'cancellation_contract_hash',
            'output_capture_contract_hash',
            'cost_meter_contract_hash',
            'start_replay_guard_hash',
            'operator_process_starter_signature_hash',
            'manual_start_command_hash',
            'terminal_session_binding_hash',
            'operator_presence_hash',
            'live_supervisor_ack_hash',
            'initial_liveness_probe_hash',
            'kill_switch_ack_hash',
            'output_stream_capture_hash',
            'cost_meter_initial_hash',
            'no_autostart_attestation_hash',
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
            'process_starter_manifest_hash',
            'supervisor_binding_hash',
            'liveness_monitor_binding_hash',
            'cancellation_contract_hash',
            'output_capture_contract_hash',
            'cost_meter_contract_hash',
            'start_replay_guard_hash',
            'operator_process_starter_signature_hash',
            'manual_start_command_hash',
            'terminal_session_binding_hash',
            'operator_presence_hash',
            'live_supervisor_ack_hash',
            'initial_liveness_probe_hash',
            'kill_switch_ack_hash',
            'output_stream_capture_hash',
            'cost_meter_initial_hash',
            'no_autostart_attestation_hash',
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
            'real_invoker_process_starter_readiness_gate_id' => (string) $input['real_invoker_process_starter_readiness_gate_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'process_starter_manifest_hash' => (string) $input['process_starter_manifest_hash'],
            'supervisor_binding_hash' => (string) $input['supervisor_binding_hash'],
            'liveness_monitor_binding_hash' => (string) $input['liveness_monitor_binding_hash'],
            'cancellation_contract_hash' => (string) $input['cancellation_contract_hash'],
            'output_capture_contract_hash' => (string) $input['output_capture_contract_hash'],
            'cost_meter_contract_hash' => (string) $input['cost_meter_contract_hash'],
            'start_replay_guard_hash' => (string) $input['start_replay_guard_hash'],
            'operator_process_starter_signature_hash' => (string) $input['operator_process_starter_signature_hash'],
            'manual_start_command_hash' => (string) $input['manual_start_command_hash'],
            'terminal_session_binding_hash' => (string) $input['terminal_session_binding_hash'],
            'operator_presence_hash' => (string) $input['operator_presence_hash'],
            'live_supervisor_ack_hash' => (string) $input['live_supervisor_ack_hash'],
            'initial_liveness_probe_hash' => (string) $input['initial_liveness_probe_hash'],
            'kill_switch_ack_hash' => (string) $input['kill_switch_ack_hash'],
            'output_stream_capture_hash' => (string) $input['output_stream_capture_hash'],
            'cost_meter_initial_hash' => (string) $input['cost_meter_initial_hash'],
            'no_autostart_attestation_hash' => (string) $input['no_autostart_attestation_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
