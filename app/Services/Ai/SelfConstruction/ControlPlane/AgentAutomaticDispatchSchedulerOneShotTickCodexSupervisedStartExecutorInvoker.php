<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSupervisedStartExecutor;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexSupervisedStartExecutorInvoker
{
    public function __construct(
        private readonly AgentCodexSupervisedStartExecutor $supervisedStartExecutor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexSupervisedStart(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->supervisedStartExecutor->prepareSupervisedStart($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_codex_supervised_start_prepared',
            'codex_supervised_start_executor_invoked' => true,
            'codex_supervised_start_executor_invocation_count' => 1,
            'codex_supervised_start_executor_result' => $result,
            'supervised_start_id' => data_get($result, 'supervised_start_id'),
            'process_start_release_id' => data_get($result, 'process_start_release_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'supervised_start_prepared' => data_get($result, 'supervised_start_prepared'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
        ], 'activate_signed_one_shot_scheduler_tick_codex_process_spawn_enablement_contract');
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
            'operator_release_receipt_hash',
            'stdout_stderr_sanitizer_hash',
            'ready_probe_plan_hash',
            'rollback_plan_hash',
            'actor',
            'session',
            'reason',
        ];

        foreach ($required as $field) {
            if (! Arr::has($input, $field) || $input[$field] === null || $input[$field] === '') {
                throw new InvalidArgumentException('missing_'.$field);
            }
        }

        foreach (['operator_release_receipt_hash', 'stdout_stderr_sanitizer_hash', 'ready_probe_plan_hash', 'rollback_plan_hash'] as $hashField) {
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
            'operator_release_receipt_hash' => (string) $input['operator_release_receipt_hash'],
            'stdout_stderr_sanitizer_hash' => (string) $input['stdout_stderr_sanitizer_hash'],
            'ready_probe_plan_hash' => (string) $input['ready_probe_plan_hash'],
            'rollback_plan_hash' => (string) $input['rollback_plan_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
