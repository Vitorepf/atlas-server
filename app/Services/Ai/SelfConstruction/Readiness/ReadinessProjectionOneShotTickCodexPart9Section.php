<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart9Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-IMPLEMENTATION-BOUNDARY-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_implementation_boundary_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start implementation boundary gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates implementation boundary input and delegates to AgentCodexRealInvokerPostStartImplementationBoundaryGate.'],
                ['id' => 'T2', 'title' => 'Preserve executor-plan boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares implementation boundary while executor plan, process start, provider calls, adapter execution, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start implementation boundary gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover boundary preparation, idempotent retry, invalid implementation plan hash, missing signed release metadata, forbidden dispatch and missing provider signed release run.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start implementation boundary gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next executor plan gate without preparing boundary in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_implementation_boundary_gate_invoker_prepares_boundary_without_real_invoker_execution',
                'codex_real_invoker_post_start_implementation_boundary_gate_invoker_requires_signed_release_metadata',
                'codex_real_invoker_post_start_implementation_boundary_gate_invoker_requires_operator_implementation_boundary_receipt_hash',
                'codex_real_invoker_post_start_implementation_boundary_gate_invoker_keeps_actual_process_start_disabled',
                'codex_real_invoker_post_start_implementation_boundary_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_implementation_boundary_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_implementation_boundary_allowed_by_future_invoker' => true,
                'post_start_implementation_boundary_recorded_after_future_invoker' => true,
                'executor_plan_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_implementation_boundary_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate implementation packet is ready; it scopes boundary preparation before executor plan.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-PLAN-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_executor_plan_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_executor_plan_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start executor plan gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates executor plan input and delegates to AgentCodexRealInvokerPostStartExecutorPlanGate.'],
                ['id' => 'T2', 'title' => 'Preserve executor fresh-release boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares executor plan while executor enablement, process start, provider calls, adapter execution, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start executor plan gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover executor plan preparation, idempotent retry, invalid executor hash, missing implementation boundary metadata, forbidden dispatch and missing provider boundary run.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start executor plan gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next executor fresh release gate without preparing executor plan in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_executor_plan_gate_invoker_prepares_plan_without_enabling_executor',
                'codex_real_invoker_post_start_executor_plan_gate_invoker_requires_implementation_boundary_metadata',
                'codex_real_invoker_post_start_executor_plan_gate_invoker_requires_operator_executor_plan_receipt_hash',
                'codex_real_invoker_post_start_executor_plan_gate_invoker_keeps_actual_process_start_disabled',
                'codex_real_invoker_post_start_executor_plan_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_executor_plan_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_executor_plan_allowed_by_future_invoker' => true,
                'post_start_executor_plan_recorded_after_future_invoker' => true,
                'executor_fresh_release_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'executor_enabled_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_plan_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate implementation packet is ready; it scopes executor-plan preparation before fresh release.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-FRESH-RELEASE-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start executor fresh release gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates fresh release input and delegates to AgentCodexRealInvokerPostStartExecutorFreshReleaseGate.'],
                ['id' => 'T2', 'title' => 'Preserve executor enablement boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker authorizes executor fresh release while executor enablement, process start, provider calls, adapter execution, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start executor fresh release gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover fresh release authorization, idempotent retry, invalid revalidation hash, missing executor plan metadata, forbidden executor enablement and missing provider plan run.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start executor fresh release gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next executor enablement gate without authorizing fresh release in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_authorizes_fresh_release_without_enabling_executor',
                'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_requires_executor_plan_metadata',
                'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_requires_operator_fresh_release_receipt_hash',
                'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_keeps_actual_process_start_disabled',
                'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_executor_fresh_release_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_executor_fresh_release_gate_allowed_by_future_invoker' => true,
                'post_start_executor_fresh_release_recorded_after_future_invoker' => true,
                'executor_enablement_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'executor_enabled_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_fresh_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate implementation packet is ready; it scopes fresh release before executor enablement.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-ENABLEMENT-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_executor_enablement_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_executor_enablement_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start executor enablement gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates enablement input and delegates to AgentCodexRealInvokerPostStartExecutorEnablementGate.'],
                ['id' => 'T2', 'title' => 'Preserve process-start boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker enables executor metadata while process start, provider calls, adapter execution, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start executor enablement gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover executor enablement, idempotent retry, invalid disable switch hash, missing fresh release metadata, forbidden dispatch and missing provider run.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start executor enablement gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next supervised start activation gate without enabling executor in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_executor_enablement_gate_invoker_enables_executor_without_starting_codex',
                'codex_real_invoker_post_start_executor_enablement_gate_invoker_requires_executor_fresh_release_metadata',
                'codex_real_invoker_post_start_executor_enablement_gate_invoker_requires_operator_enablement_receipt_hash',
                'codex_real_invoker_post_start_executor_enablement_gate_invoker_keeps_actual_process_start_disabled',
                'codex_real_invoker_post_start_executor_enablement_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_executor_enablement_gate_call_allowed_by_future_invoker' => true,
                'codex_real_invoker_executor_enablement_gate_allowed_by_future_invoker' => true,
                'post_start_executor_enablement_recorded_after_future_invoker' => true,
                'executor_enabled_after_future_invoker' => true,
                'supervised_start_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_executor_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate implementation packet is ready; it scopes executor enablement before supervised start activation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateContract(array $options = []): array
    {
        $startExecutionStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateStatus($options);
        $startExecutionStatus = (array) data_get($startExecutionStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status', []);
        $postStartReadinessPayload = $this->parent->agentCodexRealInvokerPostStartProcessStarterReadinessGateContractTemplate($options);
        $readinessPayload = $this->parent->agentCodexRealInvokerProcessStarterReadinessGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-STARTER-READINESS-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_start_execution_gate_status' => data_get($startExecutionStatus, 'status'),
            'source_codex_real_invoker_post_start_start_execution_gate_status_hash' => data_get($startExecutionStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate_status_hash'),
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_contract_status' => data_get($postStartReadinessPayload, 'status'),
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_contract_hash' => data_get($postStartReadinessPayload, 'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_hash'),
            'source_codex_real_invoker_process_starter_readiness_gate_contract_status' => data_get($readinessPayload, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_contract_hash' => data_get($readinessPayload, 'codex_real_invoker_process_starter_readiness_gate_contract_template_hash'),
            'process_starter_readiness_gate' => [
                'canonical_post_start_process_starter_readiness_gate' => AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class,
                'canonical_post_start_process_starter_readiness_gate_method' => 'preparePostStartProcessStarterReadiness',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartProcessStarterReadinessGate',
                'generic_process_starter_readiness_gate' => AgentCodexRealInvokerProcessStarterReadinessGate::class,
                'generic_process_starter_readiness_gate_method' => 'prepareProcessStarter',
                'post_start_start_execution_required_before_readiness' => true,
                'post_start_evidence_acceptance_bridge_required_before_readiness' => true,
                'gate_delegates_to_codex_real_invoker_process_starter_readiness_gate' => true,
                'process_starter_ready_by_contract' => true,
                'readiness_is_not_actual_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'process_started_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'self_programming_allowed_by_contract' => false,
                'idempotency_key' => 'real_invoker_process_starter_readiness_gate_id',
            ],
            'forbidden_true_input_flags' => [
                'actual_process_start_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed',
                'adapter_execution_allowed', 'token_spend_allowed', 'dispatch_allowed', 'self_programming_allowed',
                'external_process_started', 'provider_started', 'process_started',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_process_starter_readiness_metadata_on_provider_start_run',
                'append_codex_real_invoker_process_starter_readiness_evidence_event',
                'record_codex_real_invoker_post_start_process_starter_readiness_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process', 'spawn_shell_or_subprocess', 'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex', 'enable_adapter_execution', 'spend_provider_tokens',
                'start_actual_process_without_separate_manual_start_executor_receipt', 'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'executor_enabled' => false,
            'process_start_armed' => false,
            'process_started' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate contract is ready; manual start executor receipt remains separate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class, 'preparePostStartProcessStarterReadiness');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker::class, 'prepareCodexRealInvokerPostStartProcessStarterReadinessGate');
        $genericReady = class_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class, 'prepareProcessStarter');
        $startExecutionReady = class_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class, 'authorizePostStartStartExecution');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_starter_readiness_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract_ready',
            'post_start_process_starter_readiness_gate_contract_hash_present' => $contractHash !== '',
            'post_start_start_execution_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_start_execution_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_start_execution_gate_service_ready',
            'generic_post_start_process_starter_readiness_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_starter_readiness_gate_contract_status') === 'codex_real_invoker_post_start_process_starter_readiness_gate_contract_template_ready',
            'generic_process_starter_readiness_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_process_starter_readiness_gate_contract_status') === 'codex_real_invoker_process_starter_readiness_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_starter_readiness_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_starter_readiness_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_process_starter_readiness_gate_ready' => $genericReady,
            'codex_real_invoker_post_start_start_execution_gate_ready' => $startExecutionReady,
            'contract_delegates_to_codex_real_invoker_process_starter_readiness_gate' => data_get($contract, 'process_starter_readiness_gate.gate_delegates_to_codex_real_invoker_process_starter_readiness_gate') === true,
            'contract_declares_readiness_is_not_actual_process_start' => data_get($contract, 'process_starter_readiness_gate.readiness_is_not_actual_process_start') === true,
            'contract_requires_manual_start_executor_receipt' => in_array('start_actual_process_without_separate_manual_start_executor_receipt', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('process_started', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-STARTER-READINESS-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_starter_readiness_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'process_starter_ready_after_future_invoker' => true,
                'manual_start_executor_receipt_required_after_readiness' => true,
                'actual_process_start_allowed_here' => false,
                'process_started_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate preflight is ready; manual start executor receipt remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate preflight is blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGatePreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-STARTER-READINESS-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose scheduler invoker for post-start process starter readiness', 'type' => 'service', 'acceptance' => 'Invoker validates input and delegates to AgentCodexRealInvokerPostStartProcessStarterReadinessGate.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects forbidden true flags before delegation.'],
                ['id' => 'T3', 'title' => 'Add dedicated tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotency, hash/bridge rejections, ledger single write and rollback.'],
                ['id' => 'T4', 'title' => 'Expose readiness status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports status without invoking the gate.'],
            ],
            'task_count' => 4,
            'implementation_policy' => [
                'process_starter_ready_after_future_invoker' => true,
                'manual_start_executor_receipt_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'process_started_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate implementation packet is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class, 'preparePostStartProcessStarterReadiness');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker::class, 'prepareCodexRealInvokerPostStartProcessStarterReadinessGate');
        $genericReady = class_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class, 'prepareProcessStarter');
        $startExecutionReady = class_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerPostStartStartExecutionGate::class, 'authorizePostStartStartExecution');

        $observedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_process_starter_readiness_gate->real_invoker_process_starter_readiness_gate_id')
            : null;
        $providerRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_process_starter_readiness_gate->real_invoker_process_starter_readiness_gate_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $gateReady && $invokerReady && $genericReady && $startExecutionReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartProcessStarterReadinessGate',
            'generic_post_start_process_starter_readiness_gate_service' => AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class,
            'generic_post_start_process_starter_readiness_gate_service_ready' => $gateReady,
            'codex_real_invoker_process_starter_readiness_gate_service' => AgentCodexRealInvokerProcessStarterReadinessGate::class,
            'codex_real_invoker_process_starter_readiness_gate_ready' => $genericReady,
            'post_start_start_execution_gate_service' => AgentCodexRealInvokerPostStartStartExecutionGate::class,
            'post_start_start_execution_gate_ready' => $startExecutionReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_process_starter_readiness_gate_recorded_run_count' => $observedRunsQuery === null ? null : (clone $observedRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_process_starter_readiness_gate_count' => $providerRunsQuery === null ? null : (clone $providerRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'manual_start_executor_receipt_required_before_actual_process' => true,
                'actual_process_start_allowed_here' => false,
                'process_started_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status_does_not_dispatch_work',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate service is ready and inspectable.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process starter readiness gate service is blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptContract(array $options = []): array
    {
        $readinessStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateStatus($options);
        $readinessStatus = (array) data_get($readinessStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status', []);
        $postStartReceiptPayload = $this->parent->agentCodexRealInvokerPostStartManualStartExecutorReceiptWriterContractTemplate($options);
        $receiptPayload = $this->parent->agentCodexRealInvokerManualStartExecutorReceiptWriterContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-MANUAL-START-EXECUTOR-RECEIPT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_status' => data_get($readinessStatus, 'status'),
            'source_codex_real_invoker_post_start_process_starter_readiness_gate_status_hash' => data_get($readinessStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_status_hash'),
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_contract_status' => data_get($postStartReceiptPayload, 'status'),
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_contract_hash' => data_get($postStartReceiptPayload, 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_hash'),
            'source_codex_real_invoker_manual_start_executor_receipt_contract_status' => data_get($receiptPayload, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_contract_hash' => data_get($receiptPayload, 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_hash'),
            'manual_start_executor_receipt' => [
                'canonical_post_start_manual_start_executor_receipt_writer' => AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class,
                'canonical_post_start_manual_start_executor_receipt_writer_method' => 'writePostStartManualStartExecutorReceipt',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartManualStartExecutorReceipt',
                'generic_manual_start_executor_receipt_writer' => AgentCodexRealInvokerManualStartExecutorReceiptWriter::class,
                'generic_manual_start_executor_receipt_writer_method' => 'writeManualStartExecutorReceipt',
                'post_start_process_starter_readiness_required_before_receipt' => true,
                'post_start_evidence_acceptance_bridge_required_before_receipt' => true,
                'gate_delegates_to_codex_real_invoker_manual_start_executor_receipt_writer' => true,
                'manual_start_executor_receipt_written_by_contract' => true,
                'receipt_is_not_actual_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'process_started_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'self_programming_allowed_by_contract' => false,
                'idempotency_key' => 'manual_start_executor_receipt_id',
            ],
            'forbidden_true_input_flags' => [
                'actual_process_start_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed',
                'adapter_execution_allowed', 'token_spend_allowed', 'dispatch_allowed', 'self_programming_allowed',
                'external_process_started', 'provider_started', 'process_started',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_manual_start_executor_receipt_metadata_on_provider_start_run',
                'append_codex_real_invoker_manual_start_executor_receipt_evidence_event',
                'record_codex_real_invoker_post_start_manual_start_executor_receipt_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process', 'spawn_shell_or_subprocess', 'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex', 'enable_adapter_execution', 'spend_provider_tokens',
                'start_actual_process_without_separate_operator_start_handoff', 'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract_does_not_dispatch_work',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt contract is ready; operator start handoff remains separate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract_hash');
        $writerReady = class_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class, 'writePostStartManualStartExecutorReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker::class, 'prepareCodexRealInvokerPostStartManualStartExecutorReceipt');
        $genericReady = class_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class, 'writeManualStartExecutorReceipt');
        $readinessReady = class_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class, 'preparePostStartProcessStarterReadiness');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_manual_start_executor_receipt_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract_ready',
            'post_start_manual_start_executor_receipt_contract_hash_present' => $contractHash !== '',
            'post_start_process_starter_readiness_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_starter_readiness_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate_service_ready',
            'generic_post_start_manual_start_executor_receipt_writer_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_manual_start_executor_receipt_contract_status') === 'codex_real_invoker_post_start_manual_start_executor_receipt_writer_contract_template_ready',
            'generic_manual_start_executor_receipt_writer_template_ready' => data_get($contract, 'source_codex_real_invoker_manual_start_executor_receipt_contract_status') === 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_ready',
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_ready' => $writerReady,
            'codex_real_invoker_post_start_manual_start_executor_receipt_invoker_ready' => $invokerReady,
            'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $genericReady,
            'codex_real_invoker_post_start_process_starter_readiness_gate_ready' => $readinessReady,
            'contract_delegates_to_codex_real_invoker_manual_start_executor_receipt_writer' => data_get($contract, 'manual_start_executor_receipt.gate_delegates_to_codex_real_invoker_manual_start_executor_receipt_writer') === true,
            'contract_declares_receipt_is_not_actual_process_start' => data_get($contract, 'manual_start_executor_receipt.receipt_is_not_actual_process_start') === true,
            'contract_requires_operator_start_handoff' => in_array('start_actual_process_without_separate_operator_start_handoff', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('process_started', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-MANUAL-START-EXECUTOR-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_manual_start_executor_receipt_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'manual_start_executor_receipt_written_after_future_invoker' => true,
                'operator_start_handoff_required_after_receipt' => true,
                'actual_process_start_allowed_here' => false,
                'process_started_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt preflight is blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptPreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-MANUAL-START-EXECUTOR-RECEIPT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose scheduler invoker for post-start manual start executor receipt', 'type' => 'service', 'acceptance' => 'Invoker validates input and delegates to AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects forbidden true flags before delegation.'],
                ['id' => 'T3', 'title' => 'Add dedicated tests', 'type' => 'test', 'acceptance' => 'Tests cover write, idempotency, hash/bridge rejections, ledger single write and rollback.'],
                ['id' => 'T4', 'title' => 'Expose receipt status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports status without invoking the writer.'],
            ],
            'task_count' => 4,
            'implementation_policy' => [
                'manual_start_executor_receipt_written_after_future_invoker' => true,
                'operator_start_handoff_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'process_started_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt implementation packet is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $writerReady = class_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class, 'writePostStartManualStartExecutorReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker::class, 'prepareCodexRealInvokerPostStartManualStartExecutorReceipt');
        $genericReady = class_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class, 'writeManualStartExecutorReceipt');
        $readinessReady = class_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class, 'preparePostStartProcessStarterReadiness');

        $observedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_manual_start_executor_receipt->manual_start_executor_receipt_id')
            : null;
        $providerRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_manual_start_executor_receipt->manual_start_executor_receipt_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $writerReady && $invokerReady && $genericReady && $readinessReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartManualStartExecutorReceipt',
            'generic_post_start_manual_start_executor_receipt_writer_service' => AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class,
            'generic_post_start_manual_start_executor_receipt_writer_service_ready' => $writerReady,
            'codex_real_invoker_manual_start_executor_receipt_writer_service' => AgentCodexRealInvokerManualStartExecutorReceiptWriter::class,
            'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $genericReady,
            'post_start_process_starter_readiness_gate_service' => AgentCodexRealInvokerPostStartProcessStarterReadinessGate::class,
            'post_start_process_starter_readiness_gate_ready' => $readinessReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_manual_start_executor_receipt_recorded_run_count' => $observedRunsQuery === null ? null : (clone $observedRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_manual_start_executor_receipt_count' => $providerRunsQuery === null ? null : (clone $providerRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'operator_start_handoff_required_before_actual_process' => true,
                'actual_process_start_allowed_here' => false,
                'process_started_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status_does_not_dispatch_work',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt service is ready and inspectable.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start manual start executor receipt service is blocked.',
        ];
    }
}
