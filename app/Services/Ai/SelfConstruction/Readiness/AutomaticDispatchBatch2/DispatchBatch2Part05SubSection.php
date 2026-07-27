<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\AutomaticDispatchBatch2;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorPlan;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchExecutorHandoff;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section;

/**
 * AUTOMATIC DISPATCH BATCH2 projection sub-section 05 of 05, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls and mother back-calls route
 * through the injected Section facade (`$this->section->*`), whose __call
 * re-dispatches to the owning sub-section or forwards to the mother verbatim.
 *
 * Stage range: agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanImplementationPacket
 *           .. agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight
 */
final class DispatchBatch2Part05SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch2Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_executor_plan_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-PLAN-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_plan_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_executor_plan_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker executor plan invoker', 'type' => 'service', 'acceptance' => 'Invoker validates executor plan input and delegates to AgentCodexRealInvokerExecutorPlan.'],
                ['id' => 'T2', 'title' => 'Preserve disabled executor boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records executor plan while executor enablement, actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker executor plan invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful plan, idempotent retry, invalid executor binary hash rejection, missing boundary metadata and duplicate plan rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker executor plan status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next fresh release gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_executor_plan_invoker_prepares_without_enabling_executor',
                'codex_real_invoker_executor_plan_invoker_requires_implementation_boundary_metadata',
                'codex_real_invoker_executor_plan_invoker_requires_executor_binary_contract_hash',
                'codex_real_invoker_executor_plan_invoker_is_idempotent_by_executor_plan_id',
                'codex_real_invoker_executor_plan_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_executor_plan_invoker',
                'dedicated_codex_real_invoker_executor_plan_invoker_feature_tests',
                'generic_codex_real_invoker_executor_plan_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_executor_plan_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_executor_fresh_release_gate_allowed_by_packet' => false,
                'executor_enablement_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_enable_executor',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_hash' => $this->section->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_prepare_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan implementation packet is ready; it scopes disabled executor planning and still stops before fresh release.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryReleaseContract(array $options = []): array
    {
        $providerStartStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverStatus($options);
        $providerStartStatus = (array) data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status', []);
        $boundaryPayload = $this->section->agentDispatchExecutorAdapterInvocationBoundaryPreflight($options);
        $boundary = (array) data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight', []);

        $contract = [
            'status' => 'one_shot_tick_adapter_invocation_boundary_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-ADAPTER-INVOCATION-BOUNDARY-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_provider_start_driver_status' => data_get($providerStartStatus, 'status'),
            'source_provider_start_driver_status_hash' => data_get($providerStartStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_hash'),
            'source_adapter_invocation_boundary_preflight_status' => data_get($boundaryPayload, 'status'),
            'source_adapter_invocation_boundary_preflight_hash' => data_get($boundaryPayload, 'dispatch_executor_adapter_invocation_boundary_preflight_hash'),
            'release_boundary' => [
                'canonical_boundary' => AgentDispatchExecutorAdapterInvocationBoundary::class,
                'canonical_boundary_method' => 'prepareInvocation',
                'boundary_effect' => 'prepare_adapter_invocation_metadata_on_pre_start_guarded_run',
                'external_process_started_by_boundary' => false,
                'provider_started_by_boundary' => false,
                'token_spend_allowed_by_boundary' => false,
                'required_run_status_before_boundary' => 'pre_start_guarded',
                'required_run_status_after_boundary' => 'adapter_invocation_prepared',
                'idempotency_key' => 'adapter_invocation_id',
            ],
            'required_input_fields_for_future_invoker' => [
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
            'allowed_future_mutations' => [
                'write_adapter_invocation_metadata_on_agent_run',
                'transition_run_to_adapter_invocation_prepared',
                'append_adapter_invocation_prepared_evidence_event',
            ],
            'forbidden_even_after_boundary' => [
                'spawn_provider_process',
                'execute_provider_adapter',
                'spend_provider_tokens',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'adapter_invocation_boundary_is_metadata_preparation_not_adapter_execution' => true,
                'provider_adapter_execution_requires_separate_guard_contract' => true,
                'provider_specific_execution_requires_future_signed_release' => true,
                'full_chat_history_is_not_valid_context_pack' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_invocation_boundary_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_call_adapter_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick adapter invocation boundary release contract is ready; it defines the future metadata boundary while still forbidding adapter execution.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_contract_hash');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorPlanInvoker::class, 'prepareCodexRealInvokerExecutorPlan');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'executor_plan_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_executor_plan_contract_ready',
            'executor_plan_contract_hash_present' => $contractHash !== '',
            'implementation_boundary_status_ready' => data_get($contract, 'source_codex_real_invoker_implementation_boundary_status') === 'one_shot_tick_codex_real_invoker_implementation_boundary_service_ready',
            'generic_executor_plan_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_executor_plan_contract_status') === 'codex_real_invoker_executor_plan_contract_template_ready',
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'codex_real_invoker_executor_plan_invoker_ready' => $invokerReady,
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'canonical_executor_plan_method_ready' => data_get($contract, 'release_boundary.canonical_executor_plan_method') === 'prepareExecutorPlan',
            'executor_plan_does_not_enable_executor' => data_get($contract, 'release_boundary.executor_enabled_by_executor_plan') === false,
            'executor_plan_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_executor_plan') === false,
            'executor_plan_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_executor_plan') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_executor_plan_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-PLAN-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_executor_plan_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_executor_plan_invoker',
                'delegate_to_agent_codex_real_invoker_executor_plan',
                'require_codex_real_invoker_implementation_boundary_metadata',
                'require_operator_executor_plan_receipt_hash',
                'require_executor_binary_contract_hash',
                'require_executor_observability_contract_hash',
                'preserve_executor_disabled_until_fresh_release_gate',
                'return_codex_real_invoker_executor_plan_result_without_starting_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_executor_plan_call_allowed_here' => false,
                'codex_real_invoker_executor_fresh_release_gate_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_plan_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_plan_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_prepare_executor_plan',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_plan_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan preflight is ready; the next slice can expose the scoped executor plan invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor plan preflight is blocked until boundary, executor plan, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_hash');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker::class, 'authorizeCodexRealInvokerExecutorFreshRelease');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'fresh_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_contract_ready',
            'fresh_release_gate_contract_hash_present' => $contractHash !== '',
            'executor_plan_status_ready' => data_get($contract, 'source_codex_real_invoker_executor_plan_status') === 'one_shot_tick_codex_real_invoker_executor_plan_service_ready',
            'generic_fresh_release_gate_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_executor_fresh_release_gate_contract_status') === 'codex_real_invoker_executor_fresh_release_gate_contract_template_ready',
            'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'codex_real_invoker_executor_fresh_release_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'canonical_fresh_release_gate_method_ready' => data_get($contract, 'release_boundary.canonical_executor_fresh_release_gate_method') === 'authorizeFreshRelease',
            'fresh_release_does_not_enable_executor' => data_get($contract, 'release_boundary.executor_enabled_by_fresh_release') === false,
            'fresh_release_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_fresh_release') === false,
            'fresh_release_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_fresh_release') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_executor_fresh_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_executor_fresh_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_executor_fresh_release_gate',
                'require_codex_real_invoker_executor_plan_metadata',
                'require_operator_fresh_release_receipt_hash',
                'require_plan_revalidation_report_hash',
                'require_freshness_window_hash',
                'require_final_human_signature_hash',
                'preserve_executor_disabled_until_enablement_gate',
                'return_codex_real_invoker_executor_fresh_release_result_without_starting_codex_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_executor_fresh_release_gate_call_allowed_here' => false,
                'executor_enablement_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_authorize_fresh_release',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate preflight is ready; the next slice can expose the scoped fresh release invoker packet.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate preflight is blocked until executor plan, fresh release, storage and no-runtime prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-EXECUTOR-FRESH-RELEASE-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_executor_fresh_release_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_executor_fresh_release_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerExecutorFreshReleaseGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/Readiness/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker executor fresh release invoker', 'type' => 'service', 'acceptance' => 'Invoker validates fresh release input and delegates to AgentCodexRealInvokerExecutorFreshReleaseGate.'],
                ['id' => 'T2', 'title' => 'Preserve disabled executor boundary after fresh release', 'type' => 'service_logic', 'acceptance' => 'Invoker records fresh release authorization while executor enablement, actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker executor fresh release invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful fresh release, idempotent retry, invalid plan revalidation hash rejection, missing executor plan metadata and duplicate fresh release rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker executor fresh release status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next executor enablement gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_executor_fresh_release_gate_invoker_authorizes_without_enabling_executor',
                'codex_real_invoker_executor_fresh_release_gate_invoker_requires_executor_plan_metadata',
                'codex_real_invoker_executor_fresh_release_gate_invoker_requires_final_human_signature_hash',
                'codex_real_invoker_executor_fresh_release_gate_invoker_is_idempotent_by_fresh_release_id',
                'codex_real_invoker_executor_fresh_release_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_executor_fresh_release_gate_invoker',
                'dedicated_codex_real_invoker_executor_fresh_release_gate_invoker_feature_tests',
                'generic_codex_real_invoker_executor_fresh_release_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_executor_fresh_release_gate_call_allowed_by_future_invoker' => true,
                'executor_enablement_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_codex_process',
                'need_to_call_codex_cli_or_codex_app',
                'need_to_enable_executor',
                'need_to_spend_provider_tokens',
                'need_to_mark_run_running_or_terminal',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_executor_fresh_release_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_executor_fresh_release_gate_allowed' => false,
            'executor_enabled' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_hash' => $this->section->stableHash($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_authorize_fresh_release',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_executor_fresh_release_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker executor fresh release gate implementation packet is ready; it scopes fresh release authorization and still stops before executor enablement.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class, 'prepareCodexRealInvokerSupervisedStartActivation');

        $activationRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_supervised_start_activation->real_invoker_supervised_start_activation_id')
            : null;
        $latestActivationRun = $activationRunsQuery === null
            ? null
            : (clone $activationRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $ledgerTableReady && $activationReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_supervised_start_activation_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerSupervisedStartActivation',
            'generic_supervised_start_activation_gate_service' => AgentCodexRealInvokerSupervisedStartActivationGate::class,
            'generic_supervised_start_activation_gate_service_ready' => $activationReady,
            'generic_supervised_start_activation_gate_canonical_method' => 'prepareActivation',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_supervised_start_activation_prepared_run_count' => $activationRunsQuery === null ? null : (clone $activationRunsQuery)->count(),
            'latest_codex_real_invoker_supervised_start_activation_run' => $latestActivationRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestActivationRun->id,
                    'run_key' => $latestActivationRun->run_key,
                    'packet_id' => $latestActivationRun->packet_id,
                    'provider' => $latestActivationRun->provider,
                    'status' => $latestActivationRun->status,
                    'real_invoker_supervised_start_activation_id' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_supervised_start_activation_id'),
                    'real_invoker_executor_enablement_id' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_executor_enablement_id'),
                    'codex_execution_id' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.codex_execution_id'),
                    'real_invoker_supervised_start_activation_prepared' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.real_invoker_supervised_start_activation_prepared'),
                    'executor_enabled' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.executor_enabled'),
                    'process_start_armed' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.process_start_armed'),
                    'external_process_started' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.external_process_started'),
                    'token_spend_allowed' => data_get($latestActivationRun->metadata, 'codex_real_invoker_supervised_start_activation.token_spend_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_supervised_start_activation_when_called_with_enablement_input' => true,
                'real_invoker_supervised_start_activation_is_not_real_process_invocation' => true,
                'real_invoker_supervised_start_activation_keeps_external_process_stopped' => true,
                'codex_real_invoker_guarded_process_start_executor_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_supervised_start_activation_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_supervised_start_activation_gate_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_call_activation_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate service is ready and inspectable; status remains read-only and guarded process start is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate service is blocked until invoker, generic activation gate and ledger are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorContract(array $options = []): array
    {
        $activationStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateStatus($options);
        $activationStatus = (array) data_get($activationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status', []);
        $guardedPayload = $this->section->agentCodexRealInvokerGuardedProcessStartExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-GUARDED-PROCESS-START-EXECUTOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_supervised_start_activation_gate_status' => data_get($activationStatus, 'status'),
            'source_codex_real_invoker_supervised_start_activation_gate_status_hash' => data_get($activationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_status_hash'),
            'source_codex_real_invoker_guarded_process_start_executor_contract_status' => data_get($guardedPayload, 'status'),
            'source_codex_real_invoker_guarded_process_start_executor_contract_hash' => data_get($guardedPayload, 'codex_real_invoker_guarded_process_start_executor_contract_template_hash'),
            'release_boundary' => [
                'canonical_guarded_process_start_executor' => AgentCodexRealInvokerGuardedProcessStartExecutor::class,
                'canonical_guarded_process_start_executor_method' => 'prepareGuardedStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerGuardedProcessStart',
                'guarded_process_start_effect' => 'prepare_disabled_guarded_start_metadata_without_starting_process',
                'supervised_start_activation_required_before_guarded_start' => true,
                'process_start_armed_by_guarded_start' => true,
                'actual_process_start_allowed_by_guarded_start' => false,
                'external_process_started_by_guarded_start' => false,
                'provider_started_by_guarded_start' => false,
                'adapter_execution_allowed_by_guarded_start' => false,
                'token_spend_allowed_by_guarded_start' => false,
                'idempotency_key' => 'real_invoker_guarded_process_start_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'operator_guarded_start_receipt_hash',
                'process_runner_contract_hash',
                'dry_run_rehearsal_hash',
                'launch_invocation_contract_hash',
                'post_start_observability_hash',
                'revoke_guard_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_guarded_process_start_metadata_on_agent_run',
                'append_codex_real_invoker_guarded_process_start_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_guarded_process_start_executor_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_prepare_guarded_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor contract is ready; it defines disabled guarded-start preparation and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_hash');
        $writerReady = class_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class, 'writePostStartEvidenceReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class, 'writeCodexRealInvokerPostStartEvidenceReceipt');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_evidence_receipt_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_ready',
            'post_start_evidence_receipt_contract_hash_present' => $contractHash !== '',
            'post_start_receipt_contract_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_receipt_contract_status') === 'one_shot_tick_codex_real_invoker_post_start_receipt_contract_service_ready',
            'generic_post_start_evidence_receipt_writer_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_receipt_writer_status') === 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_ready',
            'codex_real_invoker_post_start_evidence_receipt_writer_ready' => $writerReady,
            'codex_real_invoker_post_start_evidence_receipt_invoker_ready' => $invokerReady,
            'canonical_post_start_evidence_receipt_writer_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_evidence_receipt_writer_method') === 'writePostStartEvidenceReceipt',
            'contract_accepts_external_process_evidence_only_as_evidence' => data_get($contract, 'release_boundary.external_process_evidence_accepted_by_contract') === true,
            'contract_requires_no_atlas_process_spawn_attestation' => data_get($contract, 'release_boundary.no_atlas_process_spawn_attestation_required') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_atlas_process_spawn_disabled' => data_get($contract, 'release_boundary.atlas_process_spawned_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_evidence_receipt_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_evidence_receipt_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_evidence_receipt_writer',
                'require_codex_real_invoker_post_start_receipt_contract_metadata',
                'require_post_start_evidence_acceptance_bridge_from_receipt_contract',
                'require_external_process_identity_startup_terminal_pid_liveness_and_cost_hashes',
                'require_operator_external_start_attestation_hash',
                'require_no_atlas_process_spawn_attestation_hash',
                'preserve_dispatch_disabled_until_liveness_monitor_and_dispatch_release',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_post_start_evidence_receipt_call_allowed_here' => false,
                'post_start_evidence_receipt_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_receipt_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt preflight is blocked until receipt contract, writer, invoker, storage and no-spawn prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract_hash');
        $handoffReady = class_exists(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class, 'preparePostStartDispatchExecutorHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class, 'prepareCodexRealInvokerPostStartDispatchExecutorHandoff');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_dispatch_executor_handoff_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract_ready',
            'post_start_dispatch_executor_handoff_contract_hash_present' => $contractHash !== '',
            'post_start_signed_dispatch_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_service_ready',
            'generic_post_start_dispatch_executor_handoff_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_executor_handoff_status') === 'codex_real_invoker_post_start_dispatch_executor_handoff_contract_template_ready',
            'codex_real_invoker_post_start_dispatch_executor_handoff_ready' => $handoffReady,
            'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_ready' => $invokerReady,
            'canonical_post_start_dispatch_executor_handoff_method_ready' => data_get($contract, 'handoff_boundary.canonical_post_start_dispatch_executor_handoff_method') === 'preparePostStartDispatchExecutorHandoff',
            'contract_requires_post_start_signed_dispatch_authorization' => data_get($contract, 'handoff_boundary.post_start_signed_dispatch_authorization_required_before_handoff') === true,
            'contract_requires_liveness_alive' => data_get($contract, 'handoff_boundary.required_liveness_state') === 'alive',
            'contract_requires_executor_handoff_packet' => data_get($contract, 'handoff_boundary.executor_handoff_packet_hash_required') === true,
            'contract_requires_executor_workspace' => data_get($contract, 'handoff_boundary.executor_workspace_hash_required') === true,
            'contract_requires_executor_scope_lock' => data_get($contract, 'handoff_boundary.executor_scope_lock_hash_required') === true,
            'contract_preserves_future_dispatch_authorization' => data_get($contract, 'handoff_boundary.future_dispatch_authorized_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'handoff_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'handoff_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'handoff_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-EXECUTOR-HANDOFF-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_dispatch_executor_handoff_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_dispatch_executor_handoff_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_dispatch_executor_handoff',
                'require_codex_real_invoker_post_start_signed_dispatch_authorization_metadata',
                'require_liveness_alive',
                'require_executor_handoff_packet_workspace_and_scope_hashes',
                'preserve_dispatch_disabled_until_dispatch_receipt_use_executor',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_executor_handoff_call_allowed_here' => false,
                'post_start_dispatch_executor_handoff_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_executor_handoff_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff preflight is blocked until signed authorization, handoff, invoker and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationPreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_contract_hash');

        $preflightChecks = [
            'guarded_invocation_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_guarded_runtime_invocation_contract_ready',
            'guarded_invocation_contract_hash_present' => $contractHash !== '',
            'mutating_writer_service_exists' => class_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class),
            'mutating_writer_method_exists' => method_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class, 'executeOneShotSchedulerTickAfterReleasePreflight'),
            'writer_status_service_ready' => data_get($contract, 'source_mutating_writer_status') === 'one_shot_tick_mutating_writer_service_ready',
            'max_one_writer_invocation' => data_get($contract, 'invocation_boundary.max_writer_invocations_per_command') === 1,
            'input_contract_requires_release_receipt_hash' => in_array('release_receipt_hash', (array) data_get($contract, 'input_contract', []), true),
            'input_contract_requires_dispatch_receipt_hash' => in_array('receipt_hash', (array) data_get($contract, 'input_contract', []), true),
            'idempotency_is_receipt_hash' => data_get($contract, 'idempotency_policy.idempotency_key') === 'receipt_hash',
            'receipt_use_forbidden' => in_array('use_dispatch_receipt', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'provider_start_forbidden' => in_array('start_provider_process', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'adapter_invocation_forbidden' => in_array('invoke_provider_adapter', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'token_spend_forbidden' => in_array('spend_provider_tokens', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_invocation', []), true),
        ];
        $failedChecks = array_values(array_keys(array_filter(
            $preflightChecks,
            static fn (bool $passed): bool => ! $passed,
        )));
        $blockingReasons = array_values(array_unique($failedChecks));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_guarded_runtime_invocation_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-GUARDED-RUNTIME-INVOCATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_guarded_invocation_contract_hash' => $contractHash,
            'preflight_checks' => $preflightChecks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'failed_preflight_checks' => $failedChecks,
            'implementation_requirements' => [
                'create_guarded_invocation_service_or_command_boundary',
                'call_mutating_writer_once_only',
                'return_writer_result_without_using_dispatch_receipt',
                'record_no_provider_start_policy_in_output',
                'keep_provider_start_adapter_invocation_token_spend_and_self_programming_forbidden',
            ],
            'writer_policy' => [
                'preflight_is_read_only' => true,
                'runtime_invocation_allowed_here' => false,
                'claim_allowed_here' => false,
                'dispatch_receipt_write_allowed_here' => false,
                'dispatch_receipt_use_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_call_mutating_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick guarded runtime invocation preflight is ready; the next slice may generate a scoped implementation packet without invoking the writer.'
                : 'Automatic dispatch scheduler one-shot tick guarded runtime invocation preflight is blocked until the writer, contract and no-provider guard conditions are ready.',
        ];
    }
}
