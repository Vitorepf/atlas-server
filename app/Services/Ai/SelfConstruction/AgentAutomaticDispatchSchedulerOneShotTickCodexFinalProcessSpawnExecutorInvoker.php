<?php

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Arr;
use InvalidArgumentException;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker
{
    public function __construct(
        private readonly AgentCodexProcessSpawnExecutor $processSpawnExecutor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexFinalProcessSpawn(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->processSpawnExecutor->prepareProcessSpawn($normalized);

        return [
            'status' => 'one_shot_scheduler_codex_final_process_spawn_executor_prepared',
            'codex_process_spawn_executor_invoked' => true,
            'codex_process_spawn_executor_invocation_count' => 1,
            'codex_process_spawn_executor_result' => $result,
            'spawn_executor_id' => data_get($result, 'spawn_executor_id'),
            'spawn_enablement_id' => data_get($result, 'spawn_enablement_id'),
            'supervised_start_id' => data_get($result, 'supervised_start_id'),
            'process_start_release_id' => data_get($result, 'process_start_release_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'process_spawn_executor_prepared' => data_get($result, 'process_spawn_executor_prepared'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
            'external_process_started' => false,
            'provider_started' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'dispatch_allowed' => false,
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_runtime_driver_contract',
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
            'process_start_release_id',
            'supervised_start_id',
            'spawn_enablement_id',
            'spawn_executor_id',
            'operator_final_spawn_receipt_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
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
            'operator_final_spawn_receipt_hash',
            'runtime_supervision_plan_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
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
            'process_start_release_id' => (string) $input['process_start_release_id'],
            'supervised_start_id' => (string) $input['supervised_start_id'],
            'spawn_enablement_id' => (string) $input['spawn_enablement_id'],
            'spawn_executor_id' => (string) $input['spawn_executor_id'],
            'operator_final_spawn_receipt_hash' => (string) $input['operator_final_spawn_receipt_hash'],
            'runtime_supervision_plan_hash' => (string) $input['runtime_supervision_plan_hash'],
            'stdout_stderr_sink_hash' => (string) $input['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => (string) $input['liveness_probe_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
