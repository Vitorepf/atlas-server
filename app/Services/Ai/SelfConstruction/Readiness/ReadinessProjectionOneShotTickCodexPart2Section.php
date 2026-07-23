<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvokerDryRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessRuntimeDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart2Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_final_process_spawn_executor_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-FINAL-PROCESS-SPAWN-EXECUTOR-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_final_process_spawn_executor_preflight_status' => data_get($preflight, 'status'),
            'source_codex_final_process_spawn_executor_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex final process spawn executor invoker', 'type' => 'service', 'acceptance' => 'Invoker validates final spawn input and delegates to AgentCodexProcessSpawnExecutor.'],
                ['id' => 'T2', 'title' => 'Preserve no external process runtime boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares final spawn executor metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex final process spawn executor invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotent retry, invalid supervision hash rejection, missing enablement metadata and duplicate executor rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex final process spawn executor status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next external process runtime driver slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_final_process_spawn_executor_invoker_prepares_without_starting_codex',
                'codex_final_process_spawn_executor_invoker_requires_spawn_enablement_metadata',
                'codex_final_process_spawn_executor_invoker_requires_final_receipt_supervision_sink_and_liveness_hashes',
                'codex_final_process_spawn_executor_invoker_is_idempotent_by_spawn_executor_id',
                'codex_final_process_spawn_executor_invoker_never_spawns_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_final_process_spawn_executor_invoker',
                'dedicated_codex_final_process_spawn_executor_invoker_feature_tests',
                'generic_codex_process_spawn_executor_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_process_spawn_executor_call_allowed_by_future_invoker' => true,
                'codex_external_process_runtime_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_final_process_spawn_executor_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_final_process_spawn_executor_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet_does_not_call_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex final process spawn executor implementation packet is ready; it scopes executor preparation and still stops before external runtime.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $executorReady = class_exists(AgentCodexProcessSpawnExecutor::class)
            && method_exists(AgentCodexProcessSpawnExecutor::class, 'prepareProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker::class, 'prepareCodexFinalProcessSpawn');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_process_spawn_executor->spawn_executor_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $executorReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_final_process_spawn_executor_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexFinalProcessSpawnExecutorInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexFinalProcessSpawn',
            'generic_executor_service' => AgentCodexProcessSpawnExecutor::class,
            'generic_executor_service_ready' => $executorReady,
            'generic_executor_canonical_method' => 'prepareProcessSpawn',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_process_spawn_executor_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_process_spawn_executor_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'spawn_executor_id' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.spawn_executor_id'),
                    'spawn_enablement_id' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.spawn_enablement_id'),
                    'supervised_start_id' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.supervised_start_id'),
                    'process_start_release_id' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.process_start_release_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.codex_execution_id'),
                    'process_spawn_executor_prepared' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.process_spawn_executor_prepared'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_process_spawn_executor.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_final_process_spawn_executor_when_called_with_signed_input' => true,
                'final_process_spawn_executor_is_not_external_process_runtime' => true,
                'codex_external_process_runtime_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_external_process_runtime_driver_contract'
                : 'repair_one_shot_scheduler_tick_codex_final_process_spawn_executor_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_final_process_spawn_executor_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_does_not_call_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_final_process_spawn_executor_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex final process spawn executor service is ready and inspectable; status remains read-only and external process runtime is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex final process spawn executor service is blocked until invoker, generic executor and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_contract_hash');
        $driverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class)
            && method_exists(AgentCodexExternalProcessRuntimeDriver::class, 'prepareExternalRuntime');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class, 'prepareCodexExternalProcessRuntime');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_external_process_runtime_driver_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'codex_final_process_spawn_executor_status_ready' => data_get($contract, 'source_codex_final_process_spawn_executor_status') === 'one_shot_tick_codex_final_process_spawn_executor_service_ready',
            'codex_external_process_runtime_driver_contract_template_ready' => data_get($contract, 'source_codex_external_process_runtime_driver_contract_status') === 'codex_external_process_runtime_driver_contract_template_ready',
            'codex_external_process_runtime_driver_ready' => $driverReady,
            'codex_external_process_runtime_driver_invoker_ready' => $invokerReady,
            'canonical_driver_method_ready' => data_get($contract, 'release_boundary.canonical_driver_method') === 'prepareExternalRuntime',
            'driver_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_driver') === false,
            'driver_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_driver') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_external_process_runtime_driver_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-RUNTIME-DRIVER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_external_process_runtime_driver_invoker',
                'delegate_to_agent_codex_external_process_runtime_driver',
                'require_codex_process_spawn_executor_prepared_metadata',
                'require_operator_runtime_receipt_hash',
                'require_process_command_hash',
                'require_environment_contract_hash',
                'require_termination_policy_hash',
                'preserve_no_codex_process_invocation_after_runtime_preparation',
                'return_codex_external_process_runtime_driver_prepared_result_without_invoking_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_external_process_runtime_driver_call_allowed_here' => false,
                'codex_process_invocation_authorization_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_runtime_driver_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_does_not_call_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex external process runtime driver preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex external process runtime driver preflight is blocked until final spawn, runtime driver, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_external_process_runtime_driver_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-RUNTIME-DRIVER-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_external_process_runtime_driver_preflight_status' => data_get($preflight, 'status'),
            'source_codex_external_process_runtime_driver_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex external process runtime driver invoker', 'type' => 'service', 'acceptance' => 'Invoker validates runtime-driver input and delegates to AgentCodexExternalProcessRuntimeDriver.'],
                ['id' => 'T2', 'title' => 'Preserve no external process invocation boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares external runtime metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex external process runtime driver invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotent retry, invalid runtime hash rejection, missing spawn executor metadata and duplicate runtime rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex external process runtime driver status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next process invocation authorization slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_external_process_runtime_driver_invoker_prepares_without_starting_codex',
                'codex_external_process_runtime_driver_invoker_requires_spawn_executor_metadata',
                'codex_external_process_runtime_driver_invoker_requires_runtime_receipt_command_environment_and_termination_hashes',
                'codex_external_process_runtime_driver_invoker_is_idempotent_by_runtime_driver_id',
                'codex_external_process_runtime_driver_invoker_never_invokes_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_external_process_runtime_driver_invoker',
                'dedicated_codex_external_process_runtime_driver_invoker_feature_tests',
                'generic_codex_external_process_runtime_driver_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_external_process_runtime_driver_call_allowed_by_future_invoker' => true,
                'codex_process_invocation_authorization_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_runtime_driver_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet_does_not_call_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex external process runtime driver implementation packet is ready; it scopes runtime-driver preparation and still stops before process invocation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $driverReady = class_exists(AgentCodexExternalProcessRuntimeDriver::class)
            && method_exists(AgentCodexExternalProcessRuntimeDriver::class, 'prepareExternalRuntime');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class, 'prepareCodexExternalProcessRuntime');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_external_process_runtime_driver->runtime_driver_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $driverReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_external_process_runtime_driver_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessRuntimeDriverInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexExternalProcessRuntime',
            'generic_driver_service' => AgentCodexExternalProcessRuntimeDriver::class,
            'generic_driver_service_ready' => $driverReady,
            'generic_driver_canonical_method' => 'prepareExternalRuntime',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_external_process_runtime_driver_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_external_process_runtime_driver_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'runtime_driver_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.runtime_driver_id'),
                    'spawn_executor_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.spawn_executor_id'),
                    'spawn_enablement_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.spawn_enablement_id'),
                    'process_start_release_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.process_start_release_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.codex_execution_id'),
                    'external_runtime_driver_prepared' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.external_runtime_driver_prepared'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_external_process_runtime_driver.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_external_process_runtime_driver_when_called_with_signed_input' => true,
                'external_process_runtime_driver_is_not_process_invocation' => true,
                'codex_process_invocation_authorization_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_process_invocation_authorization_contract'
                : 'repair_one_shot_scheduler_tick_codex_external_process_runtime_driver_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_runtime_driver_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_does_not_call_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_runtime_driver_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex external process runtime driver service is ready and inspectable; status remains read-only and process invocation authorization is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex external process runtime driver service is blocked until invoker, generic driver and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_contract_hash');
        $authorizationReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class, 'authorizeExternalProcessInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class, 'authorizeCodexProcessInvocation');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_process_invocation_authorization_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'codex_external_process_runtime_driver_status_ready' => data_get($contract, 'source_codex_external_process_runtime_driver_status') === 'one_shot_tick_codex_external_process_runtime_driver_service_ready',
            'codex_external_process_invocation_authorization_contract_template_ready' => data_get($contract, 'source_codex_external_process_invocation_authorization_contract_status') === 'codex_external_process_invocation_authorization_contract_template_ready',
            'codex_external_process_invocation_authorization_gate_ready' => $authorizationReady,
            'codex_process_invocation_authorization_invoker_ready' => $invokerReady,
            'canonical_authorization_gate_method_ready' => data_get($contract, 'release_boundary.canonical_authorization_gate_method') === 'authorizeExternalProcessInvocation',
            'authorization_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_authorization') === false,
            'authorization_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_authorization') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_process_invocation_authorization_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-INVOCATION-AUTHORIZATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_process_invocation_authorization_invoker',
                'delegate_to_agent_codex_external_process_invocation_authorization_gate',
                'require_codex_external_process_runtime_driver_prepared_metadata',
                'require_operator_invocation_receipt_hash',
                'require_runtime_driver_contract_hash',
                'require_process_command_hash',
                'require_environment_contract_hash',
                'require_termination_policy_hash',
                'preserve_no_codex_process_invocation_after_authorization',
                'return_codex_process_invocation_authorization_result_without_invoking_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_process_invocation_authorization_gate_call_allowed_here' => false,
                'codex_external_process_invoker_dry_run_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_invocation_authorization_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_invocation_authorization_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_does_not_call_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex process invocation authorization preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex process invocation authorization preflight is blocked until runtime driver, authorization gate, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_process_invocation_authorization_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-PROCESS-INVOCATION-AUTHORIZATION-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_invocation_authorization_preflight_status' => data_get($preflight, 'status'),
            'source_codex_process_invocation_authorization_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex process invocation authorization invoker', 'type' => 'service', 'acceptance' => 'Invoker validates authorization input and delegates to AgentCodexExternalProcessInvocationAuthorizationGate.'],
                ['id' => 'T2', 'title' => 'Preserve no external process invocation boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records process invocation authorization while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex process invocation authorization invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful authorization, idempotent retry, invalid contract hash rejection, missing runtime driver metadata and duplicate authorization rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex process invocation authorization status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next external process invoker dry-run slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_process_invocation_authorization_invoker_records_without_starting_codex',
                'codex_process_invocation_authorization_invoker_requires_runtime_driver_metadata',
                'codex_process_invocation_authorization_invoker_requires_invocation_receipt_and_contract_hashes',
                'codex_process_invocation_authorization_invoker_is_idempotent_by_invocation_authorization_id',
                'codex_process_invocation_authorization_invoker_never_invokes_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_process_invocation_authorization_invoker',
                'dedicated_codex_process_invocation_authorization_invoker_feature_tests',
                'generic_codex_external_process_invocation_authorization_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_process_invocation_authorization_gate_call_allowed_by_future_invoker' => true,
                'codex_external_process_invoker_dry_run_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_process_invocation_authorization_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_invocation_authorization_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet_does_not_call_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex process invocation authorization implementation packet is ready; it scopes authorization and still stops before dry-run/real invocation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $authorizationReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class, 'authorizeExternalProcessInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class, 'authorizeCodexProcessInvocation');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_external_process_invocation_authorization->invocation_authorization_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $authorizationReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_process_invocation_authorization_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexProcessInvocation',
            'generic_authorization_gate_service' => AgentCodexExternalProcessInvocationAuthorizationGate::class,
            'generic_authorization_gate_service_ready' => $authorizationReady,
            'generic_authorization_gate_canonical_method' => 'authorizeExternalProcessInvocation',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_process_invocation_authorized_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_process_invocation_authorized_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'invocation_authorization_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.runtime_driver_id'),
                    'spawn_executor_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.spawn_executor_id'),
                    'process_start_release_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.process_start_release_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.codex_execution_id'),
                    'external_process_invocation_authorized' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.external_process_invocation_authorized'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_external_process_invocation_authorization.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_authorize_codex_process_invocation_when_called_with_signed_input' => true,
                'process_invocation_authorization_is_not_process_invocation' => true,
                'codex_external_process_invoker_dry_run_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_contract'
                : 'repair_one_shot_scheduler_tick_codex_process_invocation_authorization_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_process_invocation_authorization_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_does_not_call_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex process invocation authorization service is ready and inspectable; status remains read-only and external process invoker dry-run is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex process invocation authorization service is blocked until invoker, generic authorization gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_hash');
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class)
            && method_exists(AgentCodexExternalProcessInvokerDryRun::class, 'prepareDryRun');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class, 'prepareCodexExternalProcessInvokerDryRun');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_external_process_invoker_dry_run_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'codex_process_invocation_authorization_status_ready' => data_get($contract, 'source_codex_process_invocation_authorization_status') === 'one_shot_tick_codex_process_invocation_authorization_service_ready',
            'codex_external_process_invoker_dry_run_contract_template_ready' => data_get($contract, 'source_codex_external_process_invoker_dry_run_contract_status') === 'codex_external_process_invoker_dry_run_contract_template_ready',
            'codex_external_process_invoker_dry_run_ready' => $dryRunReady,
            'codex_external_process_invoker_dry_run_invoker_ready' => $invokerReady,
            'canonical_dry_run_method_ready' => data_get($contract, 'release_boundary.canonical_dry_run_method') === 'prepareDryRun',
            'dry_run_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_dry_run') === false,
            'dry_run_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_dry_run') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_external_process_invoker_dry_run_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-INVOKER-DRY-RUN-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_external_process_invoker_dry_run_invoker',
                'delegate_to_agent_codex_external_process_invoker_dry_run',
                'require_codex_external_process_invocation_authorization_recorded_metadata',
                'require_operator_dry_run_receipt_hash',
                'require_invoker_contract_hash',
                'require_stdout_stderr_sink_hash',
                'require_liveness_probe_hash',
                'preserve_no_codex_process_invocation_after_dry_run',
                'return_codex_external_process_invoker_dry_run_result_without_invoking_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_external_process_invoker_dry_run_call_allowed_here' => false,
                'codex_real_invoker_release_preflight_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_does_not_call_dry_run',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run preflight is blocked until authorization, dry-run, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_external_process_invoker_dry_run_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-INVOKER-DRY-RUN-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_external_process_invoker_dry_run_preflight_status' => data_get($preflight, 'status'),
            'source_codex_external_process_invoker_dry_run_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex external process invoker dry-run invoker', 'type' => 'service', 'acceptance' => 'Invoker validates dry-run input and delegates to AgentCodexExternalProcessInvokerDryRun.'],
                ['id' => 'T2', 'title' => 'Preserve no real process invocation boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares dry-run metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex external process invoker dry-run invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful dry-run preparation, idempotent retry, invalid liveness hash rejection, missing authorization metadata and duplicate dry-run rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex external process invoker dry-run status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next real invoker release preflight slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_external_process_invoker_dry_run_invoker_prepares_without_starting_codex',
                'codex_external_process_invoker_dry_run_invoker_requires_invocation_authorization_metadata',
                'codex_external_process_invoker_dry_run_invoker_requires_dry_run_receipt_sink_and_liveness_hashes',
                'codex_external_process_invoker_dry_run_invoker_is_idempotent_by_dry_run_id',
                'codex_external_process_invoker_dry_run_invoker_never_invokes_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_external_process_invoker_dry_run_invoker',
                'dedicated_codex_external_process_invoker_dry_run_invoker_feature_tests',
                'generic_codex_external_process_invoker_dry_run_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_external_process_invoker_dry_run_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_release_preflight_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet_does_not_call_dry_run',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run implementation packet is ready; it scopes dry-run preparation and still stops before real invocation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class)
            && method_exists(AgentCodexExternalProcessInvokerDryRun::class, 'prepareDryRun');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class, 'prepareCodexExternalProcessInvokerDryRun');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_external_process_invoker_dry_run->dry_run_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $dryRunReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_external_process_invoker_dry_run_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexExternalProcessInvokerDryRun',
            'generic_dry_run_service' => AgentCodexExternalProcessInvokerDryRun::class,
            'generic_dry_run_service_ready' => $dryRunReady,
            'generic_dry_run_canonical_method' => 'prepareDryRun',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_external_process_invoker_dry_run_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_external_process_invoker_dry_run_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'dry_run_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.dry_run_id'),
                    'invocation_authorization_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.invocation_authorization_id'),
                    'runtime_driver_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.runtime_driver_id'),
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.codex_execution_id'),
                    'external_process_invoker_dry_run_prepared' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.external_process_invoker_dry_run_prepared'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_external_process_invoker_dry_run.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_external_process_invoker_dry_run_when_called_with_signed_input' => true,
                'external_process_invoker_dry_run_is_not_real_process_invocation' => true,
                'codex_real_invoker_release_preflight_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_release_preflight_contract'
                : 'repair_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_does_not_call_dry_run',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run service is ready and inspectable; status remains read-only and real invoker release preflight is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run service is blocked until invoker, generic dry-run and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_hash');
        $releasePreflightReady = class_exists(AgentCodexRealInvokerReleasePreflight::class)
            && method_exists(AgentCodexRealInvokerReleasePreflight::class, 'recordPreflight');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class, 'prepareCodexRealInvokerReleasePreflight');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_release_preflight_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'codex_external_process_invoker_dry_run_status_ready' => data_get($contract, 'source_codex_external_process_invoker_dry_run_status') === 'one_shot_tick_codex_external_process_invoker_dry_run_service_ready',
            'codex_real_invoker_release_preflight_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_release_preflight_contract_status') === 'codex_real_invoker_release_preflight_contract_template_ready',
            'codex_real_invoker_release_preflight_ready' => $releasePreflightReady,
            'codex_real_invoker_release_preflight_invoker_ready' => $invokerReady,
            'canonical_release_preflight_method_ready' => data_get($contract, 'release_boundary.canonical_release_preflight_method') === 'recordPreflight',
            'release_preflight_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_release_preflight') === false,
            'release_preflight_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_release_preflight') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_release_preflight_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-RELEASE-PREFLIGHT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_release_preflight_invoker',
                'delegate_to_agent_codex_real_invoker_release_preflight',
                'require_codex_external_process_invoker_dry_run_prepared_metadata',
                'require_operator_release_preflight_receipt_hash',
                'require_real_invoker_contract_hash',
                'require_rollback_plan_hash',
                'require_max_runtime_policy_hash',
                'preserve_no_codex_process_invocation_after_release_preflight',
                'return_codex_real_invoker_release_preflight_result_without_invoking_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_release_preflight_call_allowed_here' => false,
                'codex_signed_real_invoker_release_gate_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_release_preflight_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_does_not_call_release_preflight',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight is ready; the next slice can expose the scoped invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight is blocked until dry-run, release preflight, storage and no-runtime prerequisites are ready.',
        ];
    }
}
