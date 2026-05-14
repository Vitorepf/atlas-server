<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartDispatchReleaseGate $postStartDispatchReleaseGate,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerPostStartDispatchRelease(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartDispatchReleaseGate->preparePostStartDispatchRelease($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_dispatch_release_gate_ready',
            'codex_real_invoker_post_start_dispatch_release_gate_invoked' => true,
            'codex_real_invoker_post_start_dispatch_release_gate_invocation_count' => 1,
            'codex_real_invoker_post_start_dispatch_release_gate_result' => $result,
            'dispatch_release_gate_id' => data_get($result, 'dispatch_release_gate_id'),
            'post_start_liveness_monitor_id' => data_get($result, 'post_start_liveness_monitor_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'dispatch_release_gate_ready' => true,
            'future_dispatch_release_candidate' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
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
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'signed_dispatch_policy_hash',
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
            'dispatch_scope_hash',
            'continuation_summary_hash',
            'context_pack_hash',
            'signed_dispatch_policy_hash',
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
