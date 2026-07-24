<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInputNormalizer;
use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessStartReleaseGate;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexProcessStartReleaseInvoker
{
    public function __construct(
        private readonly AgentCodexProcessStartReleaseGate $processStartReleaseGate,
        private readonly OneShotTickInputNormalizer $inputNormalizer = new OneShotTickInputNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function authorizeCodexProcessStartRelease(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->processStartReleaseGate->authorizeCodexProcessStart($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_codex_process_start_release_authorized',
            'codex_process_start_release_gate_invoked' => true,
            'codex_process_start_release_gate_invocation_count' => 1,
            'codex_process_start_release_result' => $result,
            'process_start_release_id' => data_get($result, 'process_start_release_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'process_start_release_authorized' => data_get($result, 'process_start_release_authorized'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
        ], 'activate_signed_one_shot_scheduler_tick_codex_supervised_start_executor_release_contract');
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
            'process_start_release_id',
            'operator_release_receipt_hash',
            'codex_execution_contract_hash',
            'actor',
            'session',
            'reason',
        ];

        $input = $this->inputNormalizer->normalize(
            $input,
            $required,
            ['operator_release_receipt_hash', 'codex_execution_contract_hash'],
        );

        return [
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'operator_release_receipt_hash' => (string) $input['operator_release_receipt_hash'],
            'codex_execution_contract_hash' => (string) $input['codex_execution_contract_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
