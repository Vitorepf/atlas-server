<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartLivenessMonitor $postStartLivenessMonitor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordCodexRealInvokerPostStartLiveness(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartLivenessMonitor->recordPostStartLiveness($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_liveness_recorded',
            'codex_real_invoker_post_start_liveness_monitor_invoked' => true,
            'codex_real_invoker_post_start_liveness_monitor_invocation_count' => 1,
            'codex_real_invoker_post_start_liveness_monitor_result' => $result,
            'post_start_liveness_monitor_id' => data_get($result, 'post_start_liveness_monitor_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'post_start_evidence_receipt_id' => data_get($result, 'post_start_evidence_receipt_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'observed_liveness_state' => data_get($result, 'observed_liveness_state'),
            'post_start_liveness_recorded' => true,
            'atlas_process_spawned' => false,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'provider_started' => true,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
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
            'post_start_evidence_acceptance_bridge_id',
            'post_start_evidence_receipt_id',
            'post_start_liveness_monitor_id',
            'observed_liveness_state',
            'liveness_observation_hash',
            'heartbeat_observation_hash',
            'progress_observation_hash',
            'operator_visibility_attestation_hash',
            'no_provider_call_attestation_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        $state = strtolower(trim((string) $input['observed_liveness_state']));
        if (! in_array($state, ['alive', 'silent', 'stale', 'orphaned'], true)) {
            throw new InvalidArgumentException('invalid_observed_liveness_state');
        }

        foreach ([
            'liveness_observation_hash',
            'heartbeat_observation_hash',
            'progress_observation_hash',
            'operator_visibility_attestation_hash',
            'no_provider_call_attestation_hash',
        ] as $hashField) {
            $hash = strtolower(trim((string) $input[$hashField]));

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        $normalized = [];
        foreach ($required as $field) {
            $normalized[$field] = $field === 'observed_liveness_state' ? $state : (string) $input[$field];
        }

        return $normalized;
    }
}
