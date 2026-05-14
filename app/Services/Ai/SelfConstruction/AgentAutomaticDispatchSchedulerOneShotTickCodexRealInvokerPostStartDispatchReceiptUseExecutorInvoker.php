<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor $postStartDispatchReceiptUseExecutor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function executeCodexRealInvokerPostStartDispatchReceiptUse(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartDispatchReceiptUseExecutor->executePostStartDispatchReceiptUse($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_dispatch_receipt_used',
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoked' => true,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_invocation_count' => 1,
            'codex_real_invoker_post_start_dispatch_receipt_use_executor_result' => $result,
            'provider_start_attempt_id' => data_get($result, 'provider_start_attempt_id'),
            'dispatch_executor_handoff_id' => data_get($result, 'dispatch_executor_handoff_id'),
            'signed_dispatch_authorization_id' => data_get($result, 'signed_dispatch_authorization_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'signed_dispatch_receipt_hash' => data_get($result, 'signed_dispatch_receipt_hash'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'dispatch_receipt_used' => true,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'provider_started' => true,
            'provider_start_allowed_after_mark' => false,
            'provider_process_call_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'receipt_use_result' => data_get($result, 'receipt_use_result'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
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
            'dispatch_executor_handoff_id',
            'signed_dispatch_authorization_id',
            'post_start_evidence_acceptance_bridge_id',
            'signed_dispatch_receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
            'provider_start_attempt_id',
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

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = (string) $input[$field];
        }

        return $normalized;
    }
}
