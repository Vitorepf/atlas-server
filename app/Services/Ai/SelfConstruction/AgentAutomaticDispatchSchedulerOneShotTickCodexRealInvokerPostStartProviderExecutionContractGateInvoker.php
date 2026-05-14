<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartProviderExecutionContractGate $postStartProviderExecutionContractGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartProviderExecutionContractGate(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartProviderExecutionContractGate->preparePostStartProviderExecutionContract($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_provider_execution_contract_prepared',
            'codex_real_invoker_post_start_provider_execution_contract_gate_invoked' => true,
            'codex_real_invoker_post_start_provider_execution_contract_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_provider_execution_contract_gate_result' => $result,
            'provider_execution_contract_gate_id' => data_get($result, 'provider_execution_contract_gate_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'adapter_execution_guard_gate_id' => data_get($result, 'adapter_execution_guard_gate_id'),
            'execution_guard_id' => data_get($result, 'execution_guard_id'),
            'adapter_invocation_boundary_gate_id' => $normalized['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => data_get($result, 'adapter_invocation_id'),
            'provider_start_driver_gate_id' => $normalized['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => data_get($result, 'provider_start_attempt_id'),
            'dispatch_executor_handoff_id' => $normalized['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => $normalized['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => $normalized['signed_dispatch_receipt_hash'],
            'command' => $normalized['command'],
            'cwd' => $normalized['cwd'],
            'context_pack_hash' => $normalized['context_pack_hash'],
            'continuation_summary_hash' => $normalized['continuation_summary_hash'],
            'max_runtime_minutes' => $normalized['max_runtime_minutes'],
            'max_cost_usd' => $normalized['max_cost_usd'],
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'codex_provider_execution_result' => data_get($result, 'codex_provider_execution_result'),
            'provider_specific_execution_contract_ready' => data_get($result, 'provider_specific_execution_contract_ready'),
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
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
            'provider_execution_contract_gate_id',
            'codex_execution_id',
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
            'command',
            'cwd',
            'context_pack_hash',
            'continuation_summary_hash',
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

        $hashes = [
            'signed_dispatch_receipt_hash' => strtolower(trim((string) $input['signed_dispatch_receipt_hash'])),
            'context_pack_hash' => strtolower(trim((string) $input['context_pack_hash'])),
            'continuation_summary_hash' => strtolower(trim((string) $input['continuation_summary_hash'])),
        ];

        foreach ($hashes as $field => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
        }

        $maxRuntimeMinutes = (int) $input['max_runtime_minutes'];
        $maxCostUsd = (float) $input['max_cost_usd'];

        if ($maxRuntimeMinutes < 1 || $maxRuntimeMinutes > 480) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ($maxCostUsd <= 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'provider_execution_contract_gate_id' => (string) $input['provider_execution_contract_gate_id'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'adapter_execution_guard_gate_id' => (string) $input['adapter_execution_guard_gate_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_boundary_gate_id' => (string) $input['adapter_invocation_boundary_gate_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_driver_gate_id' => (string) $input['provider_start_driver_gate_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'dispatch_executor_handoff_id' => (string) $input['dispatch_executor_handoff_id'],
            'signed_dispatch_authorization_id' => (string) $input['signed_dispatch_authorization_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'signed_dispatch_receipt_hash' => $hashes['signed_dispatch_receipt_hash'],
            'command' => trim((string) $input['command']),
            'cwd' => rtrim((string) $input['cwd'], '/'),
            'context_pack_hash' => $hashes['context_pack_hash'],
            'continuation_summary_hash' => $hashes['continuation_summary_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => $maxRuntimeMinutes,
            'max_cost_usd' => $maxCostUsd,
            'reason' => (string) $input['reason'],
        ];
    }
}
