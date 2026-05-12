<?php

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasSelfConstructionAgentRun;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentCodexRealInvokerExecutorPlan
{
    private const LEDGER_TABLE = 'atlas_ledger_events';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prepareExecutorPlan(array $input): array
    {
        $normalized = $this->normalize($input);

        foreach (['atlas_self_construction_agent_runs', self::LEDGER_TABLE] as $table) {
            if (! Schema::hasTable($table)) {
                throw new InvalidArgumentException($table.'_missing');
            }
        }

        return DB::transaction(function () use ($normalized): array {
            $run = AtlasSelfConstructionAgentRun::query()
                ->where('run_key', $normalized['run_key'])
                ->lockForUpdate()
                ->first();

            if (! $run instanceof AtlasSelfConstructionAgentRun) {
                throw new InvalidArgumentException('agent_run_not_found');
            }

            $metadata = (array) $run->metadata;
            $existingPlanId = (string) data_get($metadata, 'codex_real_invoker_executor_plan.real_invoker_executor_plan_id', '');

            if ($existingPlanId !== '') {
                if ($existingPlanId !== $normalized['real_invoker_executor_plan_id']) {
                    throw new InvalidArgumentException('codex_real_invoker_executor_plan_already_prepared');
                }

                return $this->result($run, idempotent: true, ledgerEventId: null);
            }

            $this->assertBoundaryPrepared($run, $metadata, $normalized);

            $metadata['codex_real_invoker_executor_plan'] = [
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'real_invoker_implementation_boundary_id' => $normalized['real_invoker_implementation_boundary_id'],
                'signed_real_invoker_release_id' => $normalized['signed_real_invoker_release_id'],
                'real_invoker_release_preflight_id' => $normalized['real_invoker_release_preflight_id'],
                'dry_run_id' => $normalized['dry_run_id'],
                'invocation_authorization_id' => $normalized['invocation_authorization_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'spawn_executor_id' => $normalized['spawn_executor_id'],
                'spawn_enablement_id' => $normalized['spawn_enablement_id'],
                'supervised_start_id' => $normalized['supervised_start_id'],
                'process_start_release_id' => $normalized['process_start_release_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'operator_executor_plan_receipt_hash' => $normalized['operator_executor_plan_receipt_hash'],
                'real_invoker_contract_hash' => $normalized['real_invoker_contract_hash'],
                'release_policy_hash' => $normalized['release_policy_hash'],
                'implementation_plan_hash' => $normalized['implementation_plan_hash'],
                'executor_binary_contract_hash' => $normalized['executor_binary_contract_hash'],
                'executor_observability_contract_hash' => $normalized['executor_observability_contract_hash'],
                'process_command_hash' => $normalized['process_command_hash'],
                'environment_contract_hash' => $normalized['environment_contract_hash'],
                'termination_policy_hash' => $normalized['termination_policy_hash'],
                'stdout_stderr_sink_hash' => $normalized['stdout_stderr_sink_hash'],
                'liveness_probe_hash' => $normalized['liveness_probe_hash'],
                'rollback_plan_hash' => $normalized['rollback_plan_hash'],
                'max_runtime_policy_hash' => $normalized['max_runtime_policy_hash'],
                'provider' => 'codex',
                'adapter' => 'codex',
                'status' => 'real_invoker_executor_plan_prepared_disabled_pending_fresh_release',
                'real_invoker_executor_plan_prepared' => true,
                'executor_enabled' => false,
                'fresh_release_required_before_start' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
                'prepared_by' => $normalized['actor'],
                'prepared_session' => $normalized['session'],
                'reason' => $normalized['reason'],
            ];

            $run->forceFill([
                'summary' => 'Codex real invoker executor plan prepared; executor remains disabled.',
                'metadata' => $metadata,
            ])->save();

            $ledgerEvent = $this->ledger->record(LedgerEventType::OperationCompleted, [
                'domain_event_type' => 'self_construction.agent_codex_real_invoker_executor_plan.prepared',
                'real_invoker_executor_plan_id' => $normalized['real_invoker_executor_plan_id'],
                'real_invoker_implementation_boundary_id' => $normalized['real_invoker_implementation_boundary_id'],
                'signed_real_invoker_release_id' => $normalized['signed_real_invoker_release_id'],
                'real_invoker_release_preflight_id' => $normalized['real_invoker_release_preflight_id'],
                'dry_run_id' => $normalized['dry_run_id'],
                'runtime_driver_id' => $normalized['runtime_driver_id'],
                'codex_execution_id' => $normalized['codex_execution_id'],
                'agent_run_id' => (string) $run->id,
                'run_key' => $run->run_key,
                'packet_id' => $run->packet_id,
                'provider' => 'codex',
                'adapter' => 'codex',
                'operator_executor_plan_receipt_hash' => $normalized['operator_executor_plan_receipt_hash'],
                'real_invoker_contract_hash' => $normalized['real_invoker_contract_hash'],
                'release_policy_hash' => $normalized['release_policy_hash'],
                'implementation_plan_hash' => $normalized['implementation_plan_hash'],
                'executor_binary_contract_hash' => $normalized['executor_binary_contract_hash'],
                'executor_observability_contract_hash' => $normalized['executor_observability_contract_hash'],
                'real_invoker_executor_plan_prepared' => true,
                'executor_enabled' => false,
                'fresh_release_required_before_start' => true,
                'external_process_started' => false,
                'token_spend_allowed' => false,
                'provider_started' => false,
                'dispatch_allowed' => false,
            ], [
                'envelope_id' => 'self_construction:agent_codex_real_invoker_executor_plan',
                'receipt_id' => $normalized['real_invoker_executor_plan_id'],
                'correlation_id' => $run->run_key,
                'emitter_stage' => 'atlas.self_construction.agent_control_plane',
                'emitter_version' => 'agent-codex-real-invoker-executor-plan.v1',
            ]);

            if ($ledgerEvent === null) {
                throw new InvalidArgumentException('append_only_ledger_event_write_failed');
            }

            $run->refresh();

            return $this->result($run, idempotent: false, ledgerEventId: (string) $ledgerEvent->event_id);
        });
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
            'signed_real_invoker_release_id',
            'real_invoker_implementation_boundary_id',
            'real_invoker_executor_plan_id',
            'operator_executor_plan_receipt_hash',
            'real_invoker_contract_hash',
            'release_policy_hash',
            'implementation_plan_hash',
            'executor_binary_contract_hash',
            'executor_observability_contract_hash',
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

        $hashes = [];
        foreach ([
            'operator_executor_plan_receipt_hash',
            'real_invoker_contract_hash',
            'release_policy_hash',
            'implementation_plan_hash',
            'executor_binary_contract_hash',
            'executor_observability_contract_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
        ] as $field) {
            $hashes[$field] = strtolower(trim((string) $input[$field]));

            if (! preg_match('/^[a-f0-9]{64}$/', $hashes[$field])) {
                throw new InvalidArgumentException('invalid_'.$field);
            }
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
            'signed_real_invoker_release_id' => (string) $input['signed_real_invoker_release_id'],
            'real_invoker_implementation_boundary_id' => (string) $input['real_invoker_implementation_boundary_id'],
            'real_invoker_executor_plan_id' => (string) $input['real_invoker_executor_plan_id'],
            'operator_executor_plan_receipt_hash' => $hashes['operator_executor_plan_receipt_hash'],
            'real_invoker_contract_hash' => $hashes['real_invoker_contract_hash'],
            'release_policy_hash' => $hashes['release_policy_hash'],
            'implementation_plan_hash' => $hashes['implementation_plan_hash'],
            'executor_binary_contract_hash' => $hashes['executor_binary_contract_hash'],
            'executor_observability_contract_hash' => $hashes['executor_observability_contract_hash'],
            'process_command_hash' => $hashes['process_command_hash'],
            'environment_contract_hash' => $hashes['environment_contract_hash'],
            'termination_policy_hash' => $hashes['termination_policy_hash'],
            'stdout_stderr_sink_hash' => $hashes['stdout_stderr_sink_hash'],
            'liveness_probe_hash' => $hashes['liveness_probe_hash'],
            'rollback_plan_hash' => $hashes['rollback_plan_hash'],
            'max_runtime_policy_hash' => $hashes['max_runtime_policy_hash'],
            'actor' => (string) $input['actor'],
            'session' => (string) $input['session'],
            'reason' => (string) $input['reason'],
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $normalized
     */
    private function assertBoundaryPrepared(AtlasSelfConstructionAgentRun $run, array $metadata, array $normalized): void
    {
        if ($run->status !== 'adapter_invocation_prepared') {
            throw new InvalidArgumentException('agent_run_not_adapter_invocation_prepared');
        }

        if ((string) $run->provider !== 'codex') {
            throw new InvalidArgumentException('agent_run_provider_not_codex');
        }

        if ((string) data_get($metadata, 'codex_real_invoker_implementation_boundary.real_invoker_implementation_boundary_id') !== $normalized['real_invoker_implementation_boundary_id']) {
            throw new InvalidArgumentException('codex_real_invoker_implementation_boundary_missing_or_mismatch');
        }

        foreach ([
            'signed_real_invoker_release_id',
            'real_invoker_release_preflight_id',
            'dry_run_id',
            'invocation_authorization_id',
            'runtime_driver_id',
            'spawn_executor_id',
            'spawn_enablement_id',
            'supervised_start_id',
            'process_start_release_id',
            'codex_execution_id',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_implementation_boundary.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        if ((string) data_get($metadata, 'codex_real_invoker_implementation_boundary.status') !== 'real_invoker_implementation_boundary_prepared_pending_executor') {
            throw new InvalidArgumentException('codex_real_invoker_implementation_boundary_not_ready_for_executor_plan');
        }

        foreach ([
            'real_invoker_contract_hash',
            'release_policy_hash',
            'implementation_plan_hash',
            'process_command_hash',
            'environment_contract_hash',
            'termination_policy_hash',
            'stdout_stderr_sink_hash',
            'liveness_probe_hash',
            'rollback_plan_hash',
            'max_runtime_policy_hash',
        ] as $field) {
            if ((string) data_get($metadata, 'codex_real_invoker_implementation_boundary.'.$field) !== $normalized[$field]) {
                throw new InvalidArgumentException($field.'_mismatch');
            }
        }

        foreach (['external_process_started', 'token_spend_allowed', 'provider_started', 'dispatch_allowed'] as $field) {
            if ((bool) data_get($metadata, 'codex_real_invoker_implementation_boundary.'.$field, false)) {
                throw new InvalidArgumentException($field.'_already_true');
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function result(AtlasSelfConstructionAgentRun $run, bool $idempotent, ?string $ledgerEventId): array
    {
        return [
            'status' => 'codex_real_invoker_executor_plan_prepared',
            'idempotent' => $idempotent,
            'real_invoker_executor_plan_id' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.real_invoker_executor_plan_id'),
            'real_invoker_implementation_boundary_id' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.real_invoker_implementation_boundary_id'),
            'signed_real_invoker_release_id' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.signed_real_invoker_release_id'),
            'real_invoker_release_preflight_id' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.real_invoker_release_preflight_id'),
            'dry_run_id' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.dry_run_id'),
            'codex_execution_id' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.codex_execution_id'),
            'agent_run_id' => (string) $run->id,
            'run_key' => $run->run_key,
            'run_status' => $run->status,
            'provider' => $run->provider,
            'adapter' => (string) data_get($run->metadata, 'codex_real_invoker_executor_plan.adapter'),
            'real_invoker_executor_plan_prepared' => true,
            'executor_enabled' => false,
            'fresh_release_required_before_start' => true,
            'external_process_started' => false,
            'token_spend_allowed' => false,
            'provider_started' => false,
            'dispatch_allowed' => false,
            'ledger_event_id' => $ledgerEventId,
        ];
    }
}
