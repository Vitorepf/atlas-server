<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartDispatchExecutorHandoff $postStartDispatchExecutorHandoff,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartDispatchExecutorHandoff(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartDispatchExecutorHandoff->preparePostStartDispatchExecutorHandoff($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_dispatch_executor_handoff_prepared',
            'codex_real_invoker_post_start_dispatch_executor_handoff_invoked' => true,
            'codex_real_invoker_post_start_dispatch_executor_handoff_invocation_count' => 1,
            'codex_real_invoker_post_start_dispatch_executor_handoff_result' => $result,
            'dispatch_executor_handoff_id' => data_get($result, 'dispatch_executor_handoff_id'),
            'signed_dispatch_authorization_id' => data_get($result, 'signed_dispatch_authorization_id'),
            'dispatch_release_gate_id' => data_get($result, 'dispatch_release_gate_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'dispatch_executor_handoff_prepared' => true,
            'future_dispatch_authorized' => true,
            'atlas_process_spawned' => false,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'provider_started' => true,
            'provider_process_call_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
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
            'manual_start_executor_receipt_id',
            'operator_start_handoff_id',
            'post_start_receipt_contract_id',
            'post_start_evidence_receipt_id',
            'post_start_evidence_acceptance_bridge_id',
            'post_start_liveness_monitor_id',
            'dispatch_release_gate_id',
            'signed_dispatch_authorization_id',
            'dispatch_executor_handoff_id',
            'signed_dispatch_receipt_hash',
            'human_dispatch_signature_hash',
            'signed_dispatch_policy_hash',
            'dispatch_window_hash',
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'dispatch_replay_guard_hash',
            'dispatch_kill_switch_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
            'no_direct_provider_call_attestation_hash',
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
            'human_dispatch_signature_hash',
            'signed_dispatch_policy_hash',
            'dispatch_window_hash',
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'dispatch_replay_guard_hash',
            'dispatch_kill_switch_hash',
            'executor_handoff_packet_hash',
            'executor_workspace_hash',
            'executor_scope_lock_hash',
            'no_direct_provider_call_attestation_hash',
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
