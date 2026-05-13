<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker
{
    public function __construct(
        private readonly AgentCodexProviderExecutionDriver $codexProviderExecutionDriver,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexProviderExecutionContract(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->codexProviderExecutionDriver->prepareCodexExecution($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_provider_execution_contract_prepared',
            'codex_provider_execution_driver_invoked' => true,
            'codex_provider_execution_driver_invocation_count' => 1,
            'codex_provider_execution_result' => $result,
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'execution_guard_id' => data_get($result, 'execution_guard_id'),
            'adapter_invocation_id' => data_get($result, 'adapter_invocation_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'provider_specific_contract_ready' => data_get($result, 'provider_specific_contract_ready'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'external_process_started' => false,
            'provider_started' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_start_release_contract',
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
            'execution_guard_id',
            'adapter_invocation_id',
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
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach (['context_pack_hash', 'continuation_summary_hash'] as $hashField) {
            $hash = strtolower((string) $input[$hashField]);

            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('invalid_'.$hashField);
            }

            $input[$hashField] = $hash;
        }

        if ((int) $input['max_runtime_minutes'] < 1 || (int) $input['max_runtime_minutes'] > 480) {
            throw new InvalidArgumentException('invalid_max_runtime_minutes');
        }

        if ((float) $input['max_cost_usd'] <= 0) {
            throw new InvalidArgumentException('invalid_max_cost_usd');
        }

        return [
            'run_key' => (string) $input['run_key'],
            'codex_execution_id' => (string) $input['codex_execution_id'],
            'execution_guard_id' => (string) $input['execution_guard_id'],
            'adapter_invocation_id' => (string) $input['adapter_invocation_id'],
            'provider' => strtolower(trim((string) $input['provider'])),
            'adapter' => strtolower(trim((string) $input['adapter'])),
            'command' => trim((string) $input['command']),
            'cwd' => rtrim((string) $input['cwd'], '/'),
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
