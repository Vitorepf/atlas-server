<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker
{
    public function __construct(
        private readonly AgentProviderAdapterExecutionGuard $adapterExecutionGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function blockProviderAdapterExecution(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->adapterExecutionGuard->blockUntilProviderSpecificContract($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_provider_adapter_execution_guard_blocked',
            'provider_adapter_execution_guard_invoked' => true,
            'provider_adapter_execution_guard_invocation_count' => 1,
            'provider_adapter_execution_guard_result' => $result,
            'execution_guard_id' => data_get($result, 'execution_guard_id'),
            'adapter_invocation_id' => data_get($result, 'adapter_invocation_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'blocked_by' => data_get($result, 'blocked_by'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
        ], 'activate_signed_one_shot_scheduler_tick_provider_specific_execution_contract_release');
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'run_key',
            'execution_guard_id',
            'adapter_invocation_id',
            'provider',
            'adapter',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        return [
            'run_key' => (string) $input['run_key'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider' => strtolower(trim((string) $input['provider'])),
            'adapter' => strtolower(trim((string) $input['adapter'])),
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
