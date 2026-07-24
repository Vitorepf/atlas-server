<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInputNormalizer;
use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker
{
    public function __construct(
        private readonly AgentDispatchExecutorAdapterInvocationBoundary $adapterInvocationBoundary,
        private readonly OneShotTickInputNormalizer $inputNormalizer = new OneShotTickInputNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareAdapterInvocationBoundary(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->adapterInvocationBoundary->prepareInvocation($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_adapter_invocation_boundary_prepared',
            'adapter_invocation_boundary_invoked' => true,
            'adapter_invocation_boundary_invocation_count' => 1,
            'adapter_invocation_boundary_result' => $result,
            'adapter_invocation_id' => data_get($result, 'adapter_invocation_id'),
            'provider_start_attempt_id' => data_get($result, 'provider_start_attempt_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'adapter_id' => data_get($result, 'adapter_id'),
            'adapter_descriptor_hash' => data_get($result, 'adapter_descriptor_hash'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
        ], 'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_release_contract');
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
                'adapter_invocation_id',
                'provider_start_attempt_id',
                'provider',
                'adapter',
                'command',
                'cwd',
                'context_pack_hash',
                'continuation_summary_hash',
                'actor',
                'session',
                'max_runtime_minutes',
                'max_cost_usd',
                'reason',
            ],
            ['context_pack_hash', 'continuation_summary_hash'],
        );

        if ((int) $input['max_runtime_minutes'] < 1) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ((float) $input['max_cost_usd'] < 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'provider' => (string) $input['provider'],
            'adapter' => (string) $input['adapter'],
            'command' => (string) $input['command'],
            'cwd' => (string) $input['cwd'],
            'context_pack_hash' => (string) $input['context_pack_hash'],
            'continuation_summary_hash' => (string) $input['continuation_summary_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => (int) $input['max_runtime_minutes'],
            'max_cost_usd' => (float) $input['max_cost_usd'],
            'reason' => (string) $input['reason'],
        ];
    }
}
