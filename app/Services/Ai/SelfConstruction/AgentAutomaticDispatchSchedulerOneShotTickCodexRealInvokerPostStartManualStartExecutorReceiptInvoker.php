<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker
{
    /**
     * @var list<string>
     */
    private const PROCESS_STARTER_HASH_FIELDS = [
        'process_starter_manifest_hash',
        'supervisor_binding_hash',
        'liveness_monitor_binding_hash',
        'cancellation_contract_hash',
        'output_capture_contract_hash',
        'cost_meter_contract_hash',
        'start_replay_guard_hash',
        'operator_process_starter_signature_hash',
    ];

    /**
     * @var list<string>
     */
    private const MANUAL_RECEIPT_HASH_FIELDS = [
        'manual_start_command_hash',
        'terminal_session_binding_hash',
        'operator_presence_hash',
        'live_supervisor_ack_hash',
        'initial_liveness_probe_hash',
        'kill_switch_ack_hash',
        'output_stream_capture_hash',
        'cost_meter_initial_hash',
        'no_autostart_attestation_hash',
    ];

    /**
     * @var list<string>
     */
    private const IDENTIFIER_FIELDS = [
        'run_key',
        'post_start_manual_start_executor_receipt_id',
        'manual_start_executor_receipt_id',
        'post_start_process_starter_readiness_gate_id',
        'provider_start_attempt_id',
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
        'post_start_evidence_acceptance_bridge_id',
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
        private readonly AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter $postStartManualStartExecutorReceiptWriter,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartManualStartExecutorReceipt(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartManualStartExecutorReceiptWriter->writePostStartManualStartExecutorReceipt($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_manual_start_executor_receipt_prepared',
            'codex_real_invoker_post_start_manual_start_executor_receipt_invoked' => true,
            'codex_real_invoker_post_start_manual_start_executor_receipt_invocation_count' => 1,
            'codex_real_invoker_post_start_manual_start_executor_receipt_result' => $result,
            'post_start_manual_start_executor_receipt_id' => data_get($result, 'post_start_manual_start_executor_receipt_id'),
            'manual_start_executor_receipt_id' => data_get($result, 'manual_start_executor_receipt_id'),
            'real_invoker_process_starter_readiness_gate_id' => data_get($result, 'real_invoker_process_starter_readiness_gate_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'post_start_manual_start_executor_receipt_written' => data_get($result, 'post_start_manual_start_executor_receipt_written'),
            'manual_start_executor_receipt_written' => (bool) data_get($result, 'manual_start_executor_receipt_written', false),
            'manual_operator_start_required' => (bool) data_get($result, 'manual_operator_start_required', false),
            'operator_start_handoff_required' => true,
            'process_starter_ready' => (bool) data_get($result, 'process_starter_ready', false),
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
            'codex_real_invoker_manual_start_executor_receipt_result' => data_get($result, 'codex_real_invoker_manual_start_executor_receipt_result'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_contract',
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

        $hashFields = array_merge(self::PROCESS_STARTER_HASH_FIELDS, self::MANUAL_RECEIPT_HASH_FIELDS);
        $required = array_merge(self::IDENTIFIER_FIELDS, $hashFields);

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $hashes = [];
        foreach ($hashFields as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (preg_match('/^[a-f0-9]{64}$/', $hashes[$field]) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $normalized = [];
        foreach (self::IDENTIFIER_FIELDS as $field) {
            $normalized[$field] = (string) $input[$field];
        }
        foreach ($hashFields as $field) {
            $normalized[$field] = $hashes[$field];
        }

        return $normalized;
    }
}
