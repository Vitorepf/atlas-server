<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterExecutionGuardGate;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartAdapterExecutionGuardGate $postStartAdapterExecutionGuardGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function blockCodexRealInvokerPostStartAdapterExecutionGuardGate(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartAdapterExecutionGuardGate->blockPostStartAdapterExecution($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_adapter_execution_blocked',
            'codex_real_invoker_post_start_adapter_execution_guard_gate_invoked' => true,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_adapter_execution_guard_gate_result' => $result,
            'adapter_execution_guard_gate_id' => data_get($result, 'adapter_execution_guard_gate_id'),
            'execution_guard_id' => data_get($result, 'execution_guard_id'),
            'adapter_invocation_boundary_gate_id' => data_get($result, 'adapter_invocation_boundary_gate_id'),
            'adapter_invocation_id' => data_get($result, 'adapter_invocation_id'),
            'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => data_get($result, 'provider_start_attempt_id'),
            'dispatch_executor_handoff_id' => $normalized['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider_adapter_execution_guard_result' => data_get($result, 'provider_adapter_execution_guard_result'),
            'observed_external_process_started' => data_get($result, 'observed_external_process_started'),
            'observed_provider_started' => data_get($result, 'observed_provider_started'),
            'actual_process_start_allowed' => data_get($result, 'actual_process_start_allowed'),
            'provider_process_call_allowed' => data_get($result, 'provider_process_call_allowed'),
            'adapter_invocation_allowed' => data_get($result, 'adapter_invocation_allowed'),
            'adapter_execution_allowed' => data_get($result, 'adapter_execution_allowed'),
            'token_spend_allowed' => data_get($result, 'token_spend_allowed'),
            'dispatch_allowed' => data_get($result, 'dispatch_allowed'),
            'self_programming_allowed' => false,
            'provider_specific_execution_contract_required' => data_get($result, 'provider_specific_execution_contract_required'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract',
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
            'adapter_execution_guard_gate_id',
            'execution_guard_id',
            'adapter_invocation_boundary_gate_id',
            'adapter_invocation_id',
            'provider_start_driver_gate_id',
            'provider_start_attempt_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $receiptHash = strtolower(trim((string) $input['signed_dispatch_receipt_hash']));

        if (preg_match('/^[a-f0-9]{64}$/', $receiptHash) !== 1) {
            throw new InvalidArgumentException('invalid_signed_dispatch_receipt_hash');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'adapter_execution_guard_gate_id' => (string) $input['adapter_execution_guard_gate_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'signed_dispatch_receipt_hash' => $receiptHash,
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
