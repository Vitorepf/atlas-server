<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartProviderStartDriverGate $postStartProviderStartDriverGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartProviderStartDriverGate(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartProviderStartDriverGate->preparePostStartProviderStartDriver($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_provider_start_driver_gate_prepared',
            'codex_real_invoker_post_start_provider_start_driver_gate_invoked' => true,
            'codex_real_invoker_post_start_provider_start_driver_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_provider_start_driver_gate_result' => $result,
            'provider_start_driver_gate_id' => data_get($result, 'provider_start_driver_gate_id'),
            'provider_start_attempt_id' => data_get($result, 'provider_start_attempt_id'),
            'dispatch_executor_handoff_id' => data_get($result, 'dispatch_executor_handoff_id'),
            'signed_dispatch_authorization_id' => data_get($result, 'signed_dispatch_authorization_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => data_get($result, 'signed_dispatch_receipt_hash'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider_start_result' => data_get($result, 'provider_start_result'),
            'dispatch_receipt_used' => true,
            'observed_external_process_started' => true,
            'observed_provider_started' => true,
            'provider_start_driver_external_process_started' => false,
            'driver_provider_started' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
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
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
            'sandbox_binding_key',
            'command',
            'cwd',
            'actor',
            'session',
            'max_runtime_minutes',
            'max_cost_usd',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach ([
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
        ] as $hashField) {
            $hash = strtolower(trim((string) $input[$hashField]));

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        if ((int) $input['max_runtime_minutes'] < 1) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ((float) $input['max_cost_usd'] < 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'signed_dispatch_receipt_hash' => (string) $input['signed_dispatch_receipt_hash'],
            'executor_contract_hash' => (string) $input['executor_contract_hash'],
            'executor_release_authorization_hash' => (string) $input['executor_release_authorization_hash'],
            'executor_handoff_packet_hash' => (string) $input['executor_handoff_packet_hash'],
            'executor_workspace_hash' => (string) $input['executor_workspace_hash'],
            'executor_scope_lock_hash' => (string) $input['executor_scope_lock_hash'],
            'sandbox_binding_key' => (string) $input['sandbox_binding_key'],
            'command' => (string) $input['command'],
            'cwd' => (string) $input['cwd'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => (int) $input['max_runtime_minutes'],
            'max_cost_usd' => (float) $input['max_cost_usd'],
            'reason' => (string) $input['reason'],
        ];
    }
}
