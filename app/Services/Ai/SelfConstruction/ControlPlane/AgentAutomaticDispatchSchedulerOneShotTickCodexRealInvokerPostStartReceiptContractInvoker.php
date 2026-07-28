<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartReceiptContractBuilder;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerPostStartReceiptContractBuilder $postStartReceiptContractBuilder,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function buildCodexRealInvokerPostStartReceiptContract(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->postStartReceiptContractBuilder->buildPostStartReceiptContract($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_codex_real_invoker_post_start_receipt_contract_built',
            'codex_real_invoker_post_start_receipt_contract_invoked' => true,
            'codex_real_invoker_post_start_receipt_contract_invocation_count' => 1,
            'codex_real_invoker_post_start_receipt_contract_result' => $result,
            'post_start_receipt_contract_id' => data_get($result, 'post_start_receipt_contract_id'),
            'operator_start_handoff_id' => data_get($result, 'operator_start_handoff_id'),
            'manual_start_executor_receipt_id' => data_get($result, 'manual_start_executor_receipt_id'),
            // `post_start_evidence_acceptance_bridge_id` NÃO sai daqui. Em
            // 594d224e1a a responsabilidade foi para o produtor dedicado
            // (AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge) e o
            // builder deste contrato parou de devolvê-lo — mas este envelope
            // seguiu ecoando, e ecoava NULL. Um campo presente e nulo é pior que
            // ausente: quem consome lê "existe e está vazio" em vez de "não é
            // meu". Quem quer o id pergunta a quem o produz.
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'post_start_receipt_contract_built' => true,
            'actual_process_start_allowed' => false,
            'external_process_evidence_accepted' => false,
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent')], 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract');
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
            'real_invoker_process_starter_readiness_gate_id',
            'real_invoker_start_execution_gate_id',
            'post_start_evidence_acceptance_bridge_id',
            'manual_start_executor_receipt_id',
            'operator_start_handoff_id',
            'post_start_receipt_contract_id',
            'handoff_packet_hash',
            'operator_runbook_hash',
            'external_terminal_handoff_hash',
            'post_start_liveness_probe_contract_hash',
            'post_start_receipt_contract_hash',
            'failure_escalation_contract_hash',
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
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
            'handoff_packet_hash',
            'operator_runbook_hash',
            'external_terminal_handoff_hash',
            'post_start_liveness_probe_contract_hash',
            'post_start_receipt_contract_hash',
            'failure_escalation_contract_hash',
            'external_process_identity_contract_hash',
            'startup_evidence_contract_hash',
            'terminal_pid_capture_contract_hash',
            'post_start_cost_meter_contract_hash',
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
            'real_invoker_process_starter_readiness_gate_id' => (string) $input['real_invoker_process_starter_readiness_gate_id'],
            'real_invoker_start_execution_gate_id' => (string) $input['real_invoker_start_execution_gate_id'],
            'post_start_evidence_acceptance_bridge_id' => (string) $input['post_start_evidence_acceptance_bridge_id'],
            'manual_start_executor_receipt_id' => (string) $input['manual_start_executor_receipt_id'],
            'operator_start_handoff_id' => (string) $input['operator_start_handoff_id'],
            'post_start_receipt_contract_id' => (string) $input['post_start_receipt_contract_id'],
            'handoff_packet_hash' => (string) $input['handoff_packet_hash'],
            'operator_runbook_hash' => (string) $input['operator_runbook_hash'],
            'external_terminal_handoff_hash' => (string) $input['external_terminal_handoff_hash'],
            'post_start_liveness_probe_contract_hash' => (string) $input['post_start_liveness_probe_contract_hash'],
            'post_start_receipt_contract_hash' => (string) $input['post_start_receipt_contract_hash'],
            'failure_escalation_contract_hash' => (string) $input['failure_escalation_contract_hash'],
            'external_process_identity_contract_hash' => (string) $input['external_process_identity_contract_hash'],
            'startup_evidence_contract_hash' => (string) $input['startup_evidence_contract_hash'],
            'terminal_pid_capture_contract_hash' => (string) $input['terminal_pid_capture_contract_hash'],
            'post_start_cost_meter_contract_hash' => (string) $input['post_start_cost_meter_contract_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
