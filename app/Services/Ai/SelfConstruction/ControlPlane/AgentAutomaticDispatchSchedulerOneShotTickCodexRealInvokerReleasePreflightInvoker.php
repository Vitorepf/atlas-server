<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInvokerEnvelope;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;

final class AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker
{
    public function __construct(
        private readonly AgentCodexRealInvokerReleasePreflight $realInvokerReleasePreflight,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareCodexRealInvokerReleasePreflight(array $input): array
    {
        $normalized = $this->normalize($input);
        $result = $this->realInvokerReleasePreflight->recordPreflight($normalized);

        return OneShotTickInvokerEnvelope::withDenyFlags([
            'status' => 'one_shot_scheduler_codex_real_invoker_release_preflight_recorded',
            'codex_real_invoker_release_preflight_invoked' => true,
            'codex_real_invoker_release_preflight_invocation_count' => 1,
            'codex_real_invoker_release_preflight_result' => $result,
            'real_invoker_release_preflight_id' => data_get($result, 'real_invoker_release_preflight_id'),
            'dry_run_id' => data_get($result, 'dry_run_id'),
            'invocation_authorization_id' => data_get($result, 'invocation_authorization_id'),
            'runtime_driver_id' => data_get($result, 'runtime_driver_id'),
            'codex_execution_id' => data_get($result, 'codex_execution_id'),
            'agent_run_id' => data_get($result, 'agent_run_id'),
            'run_key' => data_get($result, 'run_key'),
            'run_status' => data_get($result, 'run_status'),
            'provider' => data_get($result, 'provider'),
            'adapter' => data_get($result, 'adapter'),
            'real_invoker_release_preflight_passed' => data_get($result, 'real_invoker_release_preflight_passed'),
            'ledger_event_id' => data_get($result, 'ledger_event_id'),
            'idempotent' => data_get($result, 'idempotent'),
        ], 'activate_signed_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_contract');
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
            'runtime_driver_id',
            'invocation_authorization_id',
            'dry_run_id',
            'real_invoker_release_preflight_id',
            'operator_release_preflight_receipt_hash',
            'real_invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
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
            'operator_release_preflight_receipt_hash',
            'real_invoker_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
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
            'runtime_driver_id' => (string) $input['runtime_driver_id'],
            'invocation_authorization_id' => (string) $input['invocation_authorization_id'],
            'dry_run_id' => (string) $input['dry_run_id'],
            'real_invoker_release_preflight_id' => (string) $input['real_invoker_release_preflight_id'],
            'operator_release_preflight_receipt_hash' => (string) $input['operator_release_preflight_receipt_hash'],
            'real_invoker_contract_hash' => (string) $input['real_invoker_contract_hash'],
            'process_command_hash' => (string) $input['process_command_hash'],
            'environment_contract_hash' => (string) $input['environment_contract_hash'],
            'termination_policy_hash' => (string) $input['termination_policy_hash'],
            'stdout_stderr_sink_hash' => (string) $input['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => (string) $input['liveness_probe_hash'],
            'rollback_plan_hash' => (string) $input['rollback_plan_hash'],
            'max_runtime_policy_hash' => (string) $input['max_runtime_policy_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }
}
