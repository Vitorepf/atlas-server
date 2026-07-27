<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInputNormalizer;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartEvidenceReceiptWriter $postStartEvidenceReceiptWriter,
        private readonly OneShotTickInputNormalizer $inputNormalizer = new OneShotTickInputNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function writeCodexRealInvokerPostStartEvidenceReceipt(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartEvidenceReceiptWriter->writePostStartEvidenceReceipt($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_evidence_receipt_recorded',
            'codex_real_invoker_post_start_evidence_receipt_invoked' => true,
            'codex_real_invoker_post_start_evidence_receipt_invocation_count' => 1,
            'codex_real_invoker_post_start_evidence_receipt_result' => $result,
            'post_start_evidence_receipt_id' => data_get($result, 'post_start_evidence_receipt_id'),
            'post_start_evidence_acceptance_bridge_id' => data_get($result, 'post_start_evidence_acceptance_bridge_id'),
            'post_start_receipt_contract_id' => data_get($result, 'post_start_receipt_contract_id'),
            'operator_start_handoff_id' => data_get($result, 'operator_start_handoff_id'),
            'manual_start_executor_receipt_id' => data_get($result, 'manual_start_executor_receipt_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'post_start_evidence_receipt_recorded' => true,
            'operator_external_start_attested' => true,
            'atlas_process_spawned' => false,
            'actual_process_start_allowed' => false,
            'external_process_started' => true,
            'external_process_evidence_accepted' => true,
            'provider_started' => true,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $input = $this->inputNormalizer->normalize(
            $input,
            [
                'run_key',
                'codex_execution_id',
                'real_invoker_process_starter_readiness_gate_id',
                'real_invoker_start_execution_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
                'external_process_identity_contract_hash',
                'startup_evidence_contract_hash',
                'terminal_pid_capture_contract_hash',
                'post_start_cost_meter_contract_hash',
                'external_process_identity_evidence_hash',
                'startup_evidence_hash',
                'terminal_pid_capture_hash',
                'post_start_liveness_probe_hash',
                'post_start_cost_meter_evidence_hash',
                'operator_external_start_attestation_hash',
                'no_atlas_process_spawn_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            [
                'external_process_identity_contract_hash',
                'startup_evidence_contract_hash',
                'terminal_pid_capture_contract_hash',
                'post_start_cost_meter_contract_hash',
                'external_process_identity_evidence_hash',
                'startup_evidence_hash',
                'terminal_pid_capture_hash',
                'post_start_liveness_probe_hash',
                'post_start_cost_meter_evidence_hash',
                'operator_external_start_attestation_hash',
                'no_atlas_process_spawn_attestation_hash',
            ],
        );

        return [
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'real_invoker_process_starter_readiness_gate_id' => (string) $input['real_invoker_process_starter_readiness_gate_id'],
            'real_invoker_start_execution_gate_id' => (string) $input['real_invoker_start_execution_gate_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'operator_start_handoff_id' => (string) $input['operator_start_handoff_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'post_start_receipt_contract_id' => (string) $input['post_start_receipt_contract_id'],
            'post_start_evidence_receipt_id' => (string) $input['post_start_evidence_receipt_id'],
            'external_process_identity_contract_hash' => (string) $input['external_process_identity_contract_hash'],
            'startup_evidence_contract_hash' => (string) $input['startup_evidence_contract_hash'],
            'terminal_pid_capture_contract_hash' => (string) $input['terminal_pid_capture_contract_hash'],
            'post_start_cost_meter_contract_hash' => (string) $input['post_start_cost_meter_contract_hash'],
            'external_process_identity_evidence_hash' => (string) $input['external_process_identity_evidence_hash'],
            'startup_evidence_hash' => (string) $input['startup_evidence_hash'],
            'terminal_pid_capture_hash' => (string) $input['terminal_pid_capture_hash'],
            'post_start_liveness_probe_hash' => (string) $input['post_start_liveness_probe_hash'],
            'post_start_cost_meter_evidence_hash' => (string) $input['post_start_cost_meter_evidence_hash'],
            'operator_external_start_attestation_hash' => (string) $input['operator_external_start_attestation_hash'],
            'no_atlas_process_spawn_attestation_hash' => (string) $input['no_atlas_process_spawn_attestation_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
