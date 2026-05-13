<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker
{
    public function __construct(
        private readonly AgentDispatchExecutorProviderStartDriver $providerStartDriver,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareProviderStartDriver(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->providerStartDriver->startProviderOnce($normalized);

        return [
            'status' => 'one_shot_scheduler_provider_start_driver_prepared',
            'provider_start_driver_invoked' => true,
            'provider_start_driver_invocation_count' => 1,
            'provider_start_driver_result' => $result,
            'provider_start_attempt_id' => data_get($result, 'provider_start_attempt_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'pre_start_heartbeat_id' => data_get($result, 'pre_start_heartbeat_id'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'provider_external_process_started' => false,
            'provider_started' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_release_contract',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $required = [
            'receipt_hash',
            'executor_contract_hash',
            'executor_release_authorization_hash',
            'sandbox_binding_key',
            'provider_start_attempt_id',
            'packet_id',
            'provider',
            'adapter',
            'command',
            'cwd',
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

        foreach (['receipt_hash', 'executor_contract_hash', 'executor_release_authorization_hash'] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        if ((int) $input['max_runtime_minutes'] < 1) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ((float) $input['max_cost_usd'] < 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'receipt_hash' => (string) $input['receipt_hash'],
            'executor_contract_hash' => (string) $input['executor_contract_hash'],
            'executor_release_authorization_hash' => (string) $input['executor_release_authorization_hash'],
            'sandbox_binding_key' => (string) $input['sandbox_binding_key'],
            'provider_start_attempt_id' => (string) $input['provider_start_attempt_id'],
            'packet_id' => (string) $input['packet_id'],
            'provider' => (string) $input['provider'],
            'adapter' => (string) $input['adapter'],
            'command' => (string) $input['command'],
            'cwd' => (string) $input['cwd'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'max_runtime_minutes' => (int) $input['max_runtime_minutes'],
            'max_cost_usd' => (float) $input['max_cost_usd'],
            'reason' => (string) $input['reason'],
        ];
    }
}
