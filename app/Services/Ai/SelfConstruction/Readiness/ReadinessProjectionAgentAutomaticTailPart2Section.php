<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProviderExecutionDriver;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionAgentAutomaticTailPart2Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $genericBoundaryReady = class_exists(AgentDispatchExecutorAdapterInvocationBoundary::class)
            && method_exists(AgentDispatchExecutorAdapterInvocationBoundary::class, 'prepareInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class, 'prepareAdapterInvocationBoundary');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->adapter_invocation->adapter_invocation_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $genericBoundaryReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_adapter_invocation_boundary_service_ready' : 'blocked',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareAdapterInvocationBoundary',
            'generic_boundary_service' => AgentDispatchExecutorAdapterInvocationBoundary::class,
            'generic_boundary_service_ready' => $genericBoundaryReady,
            'generic_boundary_canonical_method' => 'prepareInvocation',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'adapter_invocation_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_adapter_invocation_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'adapter_invocation_id' => data_get($latestPreparedRun->metadata, 'adapter_invocation.adapter_invocation_id'),
                    'adapter_id' => data_get($latestPreparedRun->metadata, 'adapter_invocation.adapter_id'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'adapter_invocation.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'adapter_invocation.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_call_adapter_invocation_boundary_when_called_with_signed_input' => true,
                'adapter_invocation_boundary_is_metadata_preparation_not_adapter_execution' => true,
                'adapter_execution_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_release_contract'
                : 'repair_one_shot_scheduler_tick_adapter_invocation_boundary_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_invocation_boundary_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_does_not_call_adapter_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick adapter invocation boundary service is ready and inspectable; status remains read-only and adapter execution is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick adapter invocation boundary service is blocked until invoker, generic boundary and observability storage are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardReleaseContract(array $options = []): array
    {
        $adapterBoundaryStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryStatus($options);
        $adapterBoundaryStatus = (array) data_get($adapterBoundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status', []);
        $genericGuardPayload = $this->parent->agentProviderAdapterExecutionGuardPreflight($options);
        $genericGuard = (array) data_get($genericGuardPayload, 'provider_adapter_execution_guard_preflight', []);

        $contract = [
            'status' => 'one_shot_tick_provider_adapter_execution_guard_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-ADAPTER-EXECUTION-GUARD-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_adapter_invocation_boundary_status' => data_get($adapterBoundaryStatus, 'status'),
            'source_adapter_invocation_boundary_status_hash' => data_get($adapterBoundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_status_hash'),
            'source_provider_adapter_execution_guard_status' => data_get($genericGuardPayload, 'status'),
            'source_provider_adapter_execution_guard_preflight_hash' => data_get($genericGuardPayload, 'provider_adapter_execution_guard_preflight_hash'),
            'release_boundary' => [
                'canonical_guard' => AgentProviderAdapterExecutionGuard::class,
                'canonical_guard_method' => 'blockUntilProviderSpecificContract',
                'guard_effect' => 'record_provider_adapter_execution_block_until_provider_specific_contract',
                'external_process_started_by_guard' => false,
                'provider_started_by_guard' => false,
                'adapter_execution_allowed_by_guard' => false,
                'token_spend_allowed_by_guard' => false,
                'required_run_status_before_guard' => 'adapter_invocation_prepared',
                'required_run_status_after_guard' => 'adapter_invocation_prepared',
                'idempotency_key' => 'execution_guard_id',
                'blocked_by' => 'provider_specific_execution_contract_missing',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'execution_guard_id',
                'adapter_invocation_id',
                'provider',
                'adapter',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_provider_adapter_execution_guard_metadata_on_agent_run',
                'append_provider_adapter_execution_blocked_evidence_event',
            ],
            'forbidden_even_after_guard' => [
                'spawn_provider_process',
                'execute_provider_adapter',
                'spend_provider_tokens',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'provider_adapter_execution_guard_is_blocking_tripwire_not_execution' => true,
                'provider_specific_execution_requires_separate_signed_contract' => true,
                'provider_specific_execution_requires_future_signed_release' => true,
                'full_chat_history_is_not_valid_context_pack' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_allowed' => false,
            'adapter_execution_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_does_not_call_execution_guard',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick provider adapter execution guard release contract is ready; it defines the future blocking tripwire before any provider-specific execution contract.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardReleaseContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_release_contract_hash');
        $guardReady = class_exists(AgentProviderAdapterExecutionGuard::class)
            && method_exists(AgentProviderAdapterExecutionGuard::class, 'blockUntilProviderSpecificContract');
        $invokerDependencyReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_provider_adapter_execution_guard_release_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'adapter_invocation_boundary_service_ready' => data_get($contract, 'source_adapter_invocation_boundary_status') === 'one_shot_tick_adapter_invocation_boundary_service_ready',
            'generic_provider_adapter_execution_guard_preflight_ready' => data_get($contract, 'source_provider_adapter_execution_guard_status') === 'provider_adapter_execution_guard_ready',
            'provider_adapter_execution_guard_ready' => $guardReady,
            'adapter_invocation_boundary_invoker_dependency_ready' => $invokerDependencyReady,
            'canonical_guard_method_ready' => data_get($contract, 'release_boundary.canonical_guard_method') === 'blockUntilProviderSpecificContract',
            'guard_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_guard') === false,
            'guard_does_not_allow_adapter_execution' => data_get($contract, 'release_boundary.adapter_execution_allowed_by_guard') === false,
            'guard_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_guard') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_guard', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_provider_adapter_execution_guard_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-ADAPTER-EXECUTION-GUARD-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_one_shot_scheduler_provider_adapter_execution_guard_invoker',
                'delegate_to_agent_provider_adapter_execution_guard',
                'require_adapter_invocation_prepared_run_key',
                'require_execution_guard_id_and_adapter_invocation_id',
                'preserve_no_adapter_execution_after_guard',
                'return_provider_adapter_execution_blocked_result_without_spawning_provider_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'provider_adapter_execution_guard_call_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_allowed' => false,
            'adapter_execution_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_does_not_call_execution_guard',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick provider adapter execution guard preflight is ready; the next slice may generate the scoped guard invoker implementation packet.'
                : 'Automatic dispatch scheduler one-shot tick provider adapter execution guard preflight is blocked until adapter boundary, generic guard, storage and no-execution prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_provider_adapter_execution_guard_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-ADAPTER-EXECUTION-GUARD-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_provider_adapter_execution_guard_preflight_status' => data_get($preflight, 'status'),
            'source_provider_adapter_execution_guard_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Create one-shot scheduler provider adapter execution guard invoker', 'type' => 'service', 'acceptance' => 'Invoker validates adapter-prepared run input and delegates to AgentProviderAdapterExecutionGuard.'],
                ['id' => 'T2', 'title' => 'Preserve provider-specific execution block', 'type' => 'service_logic', 'acceptance' => 'Invoker returns provider-adapter-execution-blocked result while provider process start, adapter execution, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add provider adapter execution guard invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful block, idempotent retry, missing guard id rejection, non-adapter-prepared run rejection and adapter invocation mismatch rejection.'],
                ['id' => 'T4', 'title' => 'Expose provider adapter execution guard status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next provider-specific execution contract release slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'provider_adapter_execution_guard_invoker_blocks_provider_execution_without_starting_provider',
                'provider_adapter_execution_guard_invoker_records_blocking_guard_metadata',
                'provider_adapter_execution_guard_invoker_is_idempotent_by_execution_guard_id',
                'provider_adapter_execution_guard_invoker_never_executes_adapter',
                'provider_adapter_execution_guard_invoker_never_starts_provider_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_provider_adapter_execution_guard_invoker',
                'dedicated_provider_adapter_execution_guard_invoker_feature_tests',
                'generic_provider_adapter_execution_guard_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'provider_adapter_execution_guard_call_allowed_by_future_invoker' => true,
                'adapter_execution_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_provider_process',
                'need_to_execute_codex_claude_gemini_local_or_http_adapter',
                'need_to_spend_provider_tokens',
                'need_to_mark_packet_completed',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_adapter_execution_guard_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_allowed' => false,
            'adapter_execution_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet_does_not_call_execution_guard',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick provider adapter execution guard implementation packet is ready; it scopes the future invoker that may record the blocking guard and still stop before provider-specific execution.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $genericGuardReady = class_exists(AgentProviderAdapterExecutionGuard::class)
            && method_exists(AgentProviderAdapterExecutionGuard::class, 'blockUntilProviderSpecificContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class, 'blockProviderAdapterExecution');

        $guardedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->provider_adapter_execution_guard->execution_guard_id')
            : null;
        $latestGuardedRun = $guardedRunsQuery === null
            ? null
            : (clone $guardedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $genericGuardReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_provider_adapter_execution_guard_service_ready' : 'blocked',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickProviderAdapterExecutionGuardInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'blockProviderAdapterExecution',
            'generic_guard_service' => AgentProviderAdapterExecutionGuard::class,
            'generic_guard_service_ready' => $genericGuardReady,
            'generic_guard_canonical_method' => 'blockUntilProviderSpecificContract',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'provider_adapter_execution_guarded_run_count' => $guardedRunsQuery === null ? null : (clone $guardedRunsQuery)->count(),
            'latest_provider_adapter_execution_guarded_run' => $latestGuardedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestGuardedRun->id,
                    'run_key' => $latestGuardedRun->run_key,
                    'packet_id' => $latestGuardedRun->packet_id,
                    'provider' => $latestGuardedRun->provider,
                    'status' => $latestGuardedRun->status,
                    'execution_guard_id' => data_get($latestGuardedRun->metadata, 'provider_adapter_execution_guard.execution_guard_id'),
                    'adapter_invocation_id' => data_get($latestGuardedRun->metadata, 'provider_adapter_execution_guard.adapter_invocation_id'),
                    'blocked_by' => data_get($latestGuardedRun->metadata, 'provider_adapter_execution_guard.blocked_by'),
                    'external_process_started' => data_get($latestGuardedRun->metadata, 'provider_adapter_execution_guard.external_process_started'),
                    'token_spend_allowed' => data_get($latestGuardedRun->metadata, 'provider_adapter_execution_guard.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_call_provider_adapter_execution_guard_when_called_with_signed_input' => true,
                'provider_adapter_execution_guard_is_blocking_tripwire_not_execution' => true,
                'adapter_execution_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_provider_specific_execution_contract_release'
                : 'repair_one_shot_scheduler_tick_provider_adapter_execution_guard_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_adapter_execution_guard_allowed' => false,
            'adapter_execution_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_does_not_call_execution_guard',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_adapter_execution_guard_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick provider adapter execution guard service is ready and inspectable; status remains read-only and provider-specific execution is still blocked.'
                : 'Automatic dispatch scheduler one-shot tick provider adapter execution guard service is blocked until invoker, generic guard and observability storage are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractRelease($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_release_hash');
        $driverReady = class_exists(AgentCodexProviderExecutionDriver::class)
            && method_exists(AgentCodexProviderExecutionDriver::class, 'prepareCodexExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker::class, 'prepareCodexProviderExecutionContract');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $bindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_provider_specific_execution_contract_release_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'provider_adapter_execution_guard_service_ready' => data_get($contract, 'source_provider_adapter_execution_guard_status') === 'one_shot_tick_provider_adapter_execution_guard_service_ready',
            'codex_provider_execution_contract_template_ready' => data_get($contract, 'source_codex_provider_execution_contract_status') === 'codex_provider_execution_contract_template_ready',
            'codex_provider_execution_driver_ready' => $driverReady,
            'codex_provider_execution_invoker_ready' => $invokerReady,
            'canonical_driver_method_ready' => data_get($contract, 'release_boundary.canonical_driver_method') === 'prepareCodexExecution',
            'driver_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_driver') === false,
            'driver_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_driver') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'sandbox_bindings_table_ready' => $bindingsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_provider_specific_execution_contract_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-SPECIFIC-EXECUTION-CONTRACT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_provider_execution_contract_invoker',
                'delegate_to_agent_codex_provider_execution_driver',
                'require_provider_adapter_execution_guard_metadata',
                'require_active_codex_sandbox_binding',
                'preserve_no_codex_process_start_after_contract',
                'return_codex_provider_execution_prepared_result_without_spawning_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_provider_execution_driver_call_allowed_here' => false,
                'codex_process_start_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_specific_execution_contract_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_specific_execution_contract_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_does_not_call_codex_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick provider-specific execution contract preflight is ready; the next slice can expose the scoped Codex contract invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick provider-specific execution contract preflight is blocked until guard, driver, sandbox binding storage and no-process-start prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_provider_specific_execution_contract_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-SPECIFIC-EXECUTION-CONTRACT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_provider_specific_execution_contract_preflight_status' => data_get($preflight, 'status'),
            'source_provider_specific_execution_contract_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex provider execution contract invoker', 'type' => 'service', 'acceptance' => 'Invoker validates Codex provider-specific input and delegates to AgentCodexProviderExecutionDriver.'],
                ['id' => 'T2', 'title' => 'Preserve no Codex process start boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker returns Codex provider execution prepared result while process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex provider execution contract invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotent retry, invalid hash rejection, non-Codex rejection and sandbox mismatch rejection.'],
                ['id' => 'T4', 'title' => 'Expose provider-specific execution contract status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next Codex process-start release slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_provider_execution_contract_invoker_prepares_codex_execution_without_starting_codex',
                'codex_provider_execution_contract_invoker_requires_execution_guard_metadata',
                'codex_provider_execution_contract_invoker_requires_active_sandbox_binding',
                'codex_provider_execution_contract_invoker_is_idempotent_by_codex_execution_id',
                'codex_provider_execution_contract_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_provider_execution_contract_invoker',
                'dedicated_codex_provider_execution_contract_invoker_feature_tests',
                'generic_codex_provider_execution_driver_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_provider_execution_driver_call_allowed_by_future_invoker' => true,
                'codex_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_spend_provider_tokens',
                'need_to_mark_packet_completed',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_specific_execution_contract_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_specific_execution_contract_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet_does_not_call_codex_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick provider-specific execution contract implementation packet is ready; it scopes Codex execution preparation and still stops before process start.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderSpecificExecutionContractStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $bindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $driverReady = class_exists(AgentCodexProviderExecutionDriver::class)
            && method_exists(AgentCodexProviderExecutionDriver::class, 'prepareCodexExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker::class, 'prepareCodexProviderExecutionContract');

        $preparedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_provider_execution->codex_execution_id')
            : null;
        $latestPreparedRun = $preparedRunsQuery === null
            ? null
            : (clone $preparedRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $bindingsTableReady && $ledgerTableReady && $driverReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_provider_specific_execution_contract_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexProviderExecutionContractInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexProviderExecutionContract',
            'generic_driver_service' => AgentCodexProviderExecutionDriver::class,
            'generic_driver_service_ready' => $driverReady,
            'generic_driver_canonical_method' => 'prepareCodexExecution',
            'agent_runs_table_ready' => $runsTableReady,
            'sandbox_bindings_table_ready' => $bindingsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_provider_execution_prepared_run_count' => $preparedRunsQuery === null ? null : (clone $preparedRunsQuery)->count(),
            'latest_codex_provider_execution_prepared_run' => $latestPreparedRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreparedRun->id,
                    'run_key' => $latestPreparedRun->run_key,
                    'packet_id' => $latestPreparedRun->packet_id,
                    'provider' => $latestPreparedRun->provider,
                    'status' => $latestPreparedRun->status,
                    'codex_execution_id' => data_get($latestPreparedRun->metadata, 'codex_provider_execution.codex_execution_id'),
                    'execution_guard_id' => data_get($latestPreparedRun->metadata, 'codex_provider_execution.execution_guard_id'),
                    'provider_specific_contract_ready' => data_get($latestPreparedRun->metadata, 'codex_provider_execution.provider_specific_contract_ready'),
                    'external_process_started' => data_get($latestPreparedRun->metadata, 'codex_provider_execution.external_process_started'),
                    'token_spend_allowed' => data_get($latestPreparedRun->metadata, 'codex_provider_execution.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_provider_execution_contract_when_called_with_signed_input' => true,
                'provider_specific_execution_contract_is_not_process_start' => true,
                'codex_process_start_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_process_start_release_contract'
                : 'repair_one_shot_scheduler_tick_provider_specific_execution_contract_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_specific_execution_contract_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_does_not_call_codex_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_specific_execution_contract_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick provider-specific execution contract service is ready and inspectable; status remains read-only and Codex process start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick provider-specific execution contract service is blocked until invoker, Codex driver, sandbox binding storage and ledger are ready.',
        ];
    }
}
