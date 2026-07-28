<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchExecutorHandoff;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartLivenessMonitor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart7Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $monitorReady = class_exists(AgentCodexRealInvokerPostStartLivenessMonitor::class)
            && method_exists(AgentCodexRealInvokerPostStartLivenessMonitor::class, 'recordPostStartLiveness');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class, 'recordCodexRealInvokerPostStartLiveness');

        $livenessRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_liveness_monitor->post_start_liveness_monitor_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $monitorReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_liveness_monitor_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'recordCodexRealInvokerPostStartLiveness',
            'generic_post_start_liveness_monitor_service' => AgentCodexRealInvokerPostStartLivenessMonitor::class,
            'generic_post_start_liveness_monitor_service_ready' => $monitorReady,
            'generic_post_start_liveness_monitor_canonical_method' => 'recordPostStartLiveness',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_liveness_monitor_recorded_run_count' => $livenessRunsQuery === null ? null : (clone $livenessRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_post_start_liveness_monitor_when_called_with_external_liveness_input' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status_does_not_call_liveness_monitor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor service is ready and inspectable; status remains read-only and dispatch release is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor service is blocked until invoker, generic monitor and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchReleaseGate::class, 'preparePostStartDispatchRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class, 'prepareCodexRealInvokerPostStartDispatchRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_dispatch_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_contract_ready',
            'post_start_dispatch_release_gate_contract_hash_present' => $contractHash !== '',
            'post_start_liveness_monitor_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_liveness_monitor_status') === 'one_shot_tick_codex_real_invoker_post_start_liveness_monitor_service_ready',
            'generic_post_start_dispatch_release_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_release_gate_status') === 'codex_real_invoker_post_start_dispatch_release_gate_contract_template_ready',
            'codex_real_invoker_post_start_dispatch_release_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_dispatch_release_gate_invoker_ready' => $invokerReady,
            'canonical_post_start_dispatch_release_gate_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_dispatch_release_gate_method') === 'preparePostStartDispatchRelease',
            'contract_requires_post_start_liveness_monitor' => data_get($contract, 'release_boundary.post_start_liveness_monitor_required_before_dispatch_release') === true,
            'contract_requires_liveness_alive' => data_get($contract, 'release_boundary.required_liveness_state') === 'alive',
            'contract_requires_signed_dispatch_policy_hash' => data_get($contract, 'release_boundary.signed_dispatch_policy_hash_required') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_dispatch_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_dispatch_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_dispatch_release_gate',
                'require_codex_real_invoker_post_start_liveness_monitor_metadata',
                'require_liveness_alive',
                'require_dispatch_scope_continuation_context_policy_and_no_direct_provider_call_hashes',
                'preserve_dispatch_disabled_until_signed_dispatch_authorization_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_dispatch_release_gate_call_allowed_here' => false,
                'post_start_dispatch_release_gate_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate preflight is blocked until liveness, dispatch release gate, invoker and storage prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RELEASE-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_release_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_dispatch_release_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start dispatch release gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates dispatch release input and delegates to AgentCodexRealInvokerPostStartDispatchReleaseGate.'],
                ['id' => 'T2', 'title' => 'Preserve no dispatch boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares future dispatch release candidate with no Codex call, no token spend and no dispatch.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start dispatch release gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful release candidate, idempotent retry, non-alive liveness rejection, missing liveness metadata and invalid signed policy hash.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start dispatch release gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next signed dispatch authorization gate without invoking dispatch in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_dispatch_release_gate_invoker_prepares_candidate_without_dispatching_codex',
                'codex_real_invoker_post_start_dispatch_release_gate_invoker_requires_post_start_liveness_monitor_metadata',
                'codex_real_invoker_post_start_dispatch_release_gate_invoker_requires_liveness_alive',
                'codex_real_invoker_post_start_dispatch_release_gate_invoker_requires_signed_dispatch_policy_hash',
                'codex_real_invoker_post_start_dispatch_release_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_dispatch_release_gate_call_allowed_by_future_invoker' => true,
                'post_start_dispatch_release_gate_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'atlas_process_spawn_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate implementation packet is ready; it scopes future dispatch release before signed authorization.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartDispatchReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchReleaseGate::class, 'preparePostStartDispatchRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class, 'prepareCodexRealInvokerPostStartDispatchRelease');

        $releaseRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_dispatch_release_gate->dispatch_release_gate_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $gateReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartDispatchRelease',
            'generic_post_start_dispatch_release_gate_service' => AgentCodexRealInvokerPostStartDispatchReleaseGate::class,
            'generic_post_start_dispatch_release_gate_service_ready' => $gateReady,
            'generic_post_start_dispatch_release_gate_canonical_method' => 'preparePostStartDispatchRelease',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_dispatch_release_gate_recorded_run_count' => $releaseRunsQuery === null ? null : (clone $releaseRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_dispatch_release_gate_when_called_with_alive_liveness_input' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_does_not_call_dispatch_release_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate service is ready and inspectable; signed authorization remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch release gate service is blocked until invoker, generic gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_hash');
        $authorizationReady = class_exists(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class, 'authorizePostStartSignedDispatch');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerPostStartSignedDispatch');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_signed_dispatch_authorization_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_ready',
            'post_start_signed_dispatch_authorization_gate_contract_hash_present' => $contractHash !== '',
            'post_start_dispatch_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate_service_ready',
            'generic_post_start_signed_dispatch_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status') === 'codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract_template_ready',
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_ready' => $authorizationReady,
            'codex_real_invoker_post_start_signed_dispatch_authorization_gate_invoker_ready' => $invokerReady,
            'canonical_post_start_signed_dispatch_authorization_gate_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_signed_dispatch_authorization_gate_method') === 'authorizePostStartSignedDispatch',
            'contract_requires_post_start_dispatch_release_gate' => data_get($contract, 'release_boundary.post_start_dispatch_release_gate_required_before_authorization') === true,
            'contract_requires_liveness_alive' => data_get($contract, 'release_boundary.required_liveness_state') === 'alive',
            'contract_requires_signed_receipt' => data_get($contract, 'release_boundary.signed_dispatch_receipt_hash_required') === true,
            'contract_requires_human_signature' => data_get($contract, 'release_boundary.human_dispatch_signature_hash_required') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SIGNED-DISPATCH-AUTHORIZATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_signed_dispatch_authorization_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_signed_dispatch_authorization_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
                'require_codex_real_invoker_post_start_dispatch_release_gate_metadata',
                'require_liveness_alive',
                'require_signed_dispatch_receipt_and_human_signature_hashes',
                'preserve_dispatch_disabled_until_dispatch_executor_handoff',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_signed_dispatch_authorization_gate_call_allowed_here' => false,
                'post_start_signed_dispatch_authorization_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_signed_dispatch_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate preflight is blocked until release, authorization, invoker and storage prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-SIGNED-DISPATCH-AUTHORIZATION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_signed_dispatch_authorization_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start signed dispatch authorization invoker', 'type' => 'service', 'acceptance' => 'Invoker validates signed dispatch authorization input and delegates to AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate.'],
                ['id' => 'T2', 'title' => 'Preserve no dispatch boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records future signed dispatch authorization with no Codex call, no token spend and no dispatch.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start signed dispatch authorization invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful authorization, idempotent retry, missing release metadata, non-alive liveness and invalid human signature hash.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start signed dispatch authorization status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next dispatch executor handoff without invoking dispatch in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_signed_dispatch_authorization_invoker_records_authorization_without_dispatching_codex',
                'codex_real_invoker_post_start_signed_dispatch_authorization_invoker_requires_dispatch_release_gate_metadata',
                'codex_real_invoker_post_start_signed_dispatch_authorization_invoker_requires_liveness_alive',
                'codex_real_invoker_post_start_signed_dispatch_authorization_invoker_requires_signature_and_receipt_hashes',
                'codex_real_invoker_post_start_signed_dispatch_authorization_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_signed_dispatch_authorization_gate_call_allowed_by_future_invoker' => true,
                'post_start_signed_dispatch_authorization_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'atlas_process_spawn_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_signed_dispatch_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate implementation packet is ready; it scopes signed authorization before dispatch executor handoff.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $authorizationReady = class_exists(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class, 'authorizePostStartSignedDispatch');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerPostStartSignedDispatch');

        $authorizationRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_signed_dispatch_authorization->signed_dispatch_authorization_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $authorizationReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerPostStartSignedDispatch',
            'generic_post_start_signed_dispatch_authorization_gate_service' => AgentCodexRealInvokerPostStartSignedDispatchAuthorizationGate::class,
            'generic_post_start_signed_dispatch_authorization_gate_service_ready' => $authorizationReady,
            'generic_post_start_signed_dispatch_authorization_gate_canonical_method' => 'authorizePostStartSignedDispatch',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_signed_dispatch_authorization_recorded_run_count' => $authorizationRunsQuery === null ? null : (clone $authorizationRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_post_start_signed_dispatch_authorization_when_called_with_signed_input' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_signed_dispatch_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status_does_not_call_signed_dispatch_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate service is ready and inspectable; dispatch executor handoff remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start signed dispatch authorization gate service is blocked until invoker, generic gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-EXECUTOR-HANDOFF-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start dispatch executor handoff invoker', 'type' => 'service', 'acceptance' => 'Invoker validates dispatch executor handoff input and delegates to AgentCodexRealInvokerPostStartDispatchExecutorHandoff.'],
                ['id' => 'T2', 'title' => 'Preserve no dispatch boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares executor handoff metadata with no Codex call, no token spend, no receipt use and no dispatch.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start dispatch executor handoff invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful handoff, idempotent retry, missing signed authorization metadata, non-alive liveness and invalid executor workspace hash.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start dispatch executor handoff status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next dispatch receipt-use executor without invoking dispatch in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_prepares_handoff_without_dispatching_codex',
                'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_requires_signed_dispatch_authorization_metadata',
                'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_requires_liveness_alive',
                'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_requires_executor_packet_workspace_and_scope_hashes',
                'codex_real_invoker_post_start_dispatch_executor_handoff_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_dispatch_executor_handoff_call_allowed_by_future_invoker' => true,
                'post_start_dispatch_executor_handoff_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'atlas_process_spawn_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_executor_handoff_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff implementation packet is ready; it scopes executor handoff before receipt-use execution.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $handoffReady = class_exists(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class, 'preparePostStartDispatchExecutorHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class, 'prepareCodexRealInvokerPostStartDispatchExecutorHandoff');

        $handoffRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_dispatch_executor_handoff->dispatch_executor_handoff_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $handoffReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartDispatchExecutorHandoff',
            'generic_post_start_dispatch_executor_handoff_service' => AgentCodexRealInvokerPostStartDispatchExecutorHandoff::class,
            'generic_post_start_dispatch_executor_handoff_service_ready' => $handoffReady,
            'generic_post_start_dispatch_executor_handoff_canonical_method' => 'preparePostStartDispatchExecutorHandoff',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_dispatch_executor_handoff_prepared_run_count' => $handoffRunsQuery === null ? null : (clone $handoffRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_prepare_codex_real_invoker_post_start_dispatch_executor_handoff_when_called_with_signed_input' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_executor_handoff_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_does_not_call_dispatch_executor_handoff',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff service is ready and inspectable; dispatch receipt-use remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch executor handoff service is blocked until invoker, generic handoff and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RECEIPT-USE-EXECUTOR-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start dispatch receipt-use executor invoker', 'type' => 'service', 'acceptance' => 'Invoker validates receipt-use input and delegates to AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor.'],
                ['id' => 'T2', 'title' => 'Preserve no provider-start boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker marks the signed dispatch receipt used while provider start, adapter invocation, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start dispatch receipt-use executor invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful receipt use, idempotent retry, missing handoff metadata, dispatch-allowed rejection and invalid executor contract hash.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start dispatch receipt-use executor status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next provider start driver gate without marking receipts in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_marks_receipt_used_without_starting_provider',
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_requires_dispatch_executor_handoff_metadata',
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_requires_receipt_and_executor_hashes',
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_keeps_provider_start_disabled',
                'codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_dispatch_receipt_use_executor_call_allowed_by_future_invoker' => true,
                'post_start_dispatch_receipt_use_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_receipt_use_executor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor implementation packet is ready; it scopes receipt use before provider start driver gate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $executorReady = class_exists(AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class)
            && method_exists(AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class, 'executePostStartDispatchReceiptUse');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class, 'executeCodexRealInvokerPostStartDispatchReceiptUse');
        $writerReady = class_exists(AgentDispatchExecutorReceiptUseWriter::class)
            && method_exists(AgentDispatchExecutorReceiptUseWriter::class, 'markReceiptUsedAtomically');

        $receiptUseRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_dispatch_receipt_use->provider_start_attempt_id')
            : null;
        $usedReceiptsQuery = $dispatchReceiptsTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('status', 'used_pending_provider_start')
                ->whereNotNull('used_at')
            : null;

        $statusReady = $runsTableReady && $dispatchReceiptsTableReady && $ledgerTableReady && $executorReady && $invokerReady && $writerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'executeCodexRealInvokerPostStartDispatchReceiptUse',
            'generic_post_start_dispatch_receipt_use_executor_service' => AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class,
            'generic_post_start_dispatch_receipt_use_executor_service_ready' => $executorReady,
            'generic_post_start_dispatch_receipt_use_executor_canonical_method' => 'executePostStartDispatchReceiptUse',
            'dispatch_receipt_use_writer_service' => AgentDispatchExecutorReceiptUseWriter::class,
            'dispatch_receipt_use_writer_ready' => $writerReady,
            'agent_runs_table_ready' => $runsTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_dispatch_receipt_use_recorded_run_count' => $receiptUseRunsQuery === null ? null : (clone $receiptUseRunsQuery)->count(),
            'used_pending_provider_start_receipt_count' => $usedReceiptsQuery === null ? null : (clone $usedReceiptsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_mark_post_start_dispatch_receipt_used_when_called_with_signed_input' => true,
                'receipt_use_is_not_provider_start' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_receipt_use_executor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_does_not_call_dispatch_receipt_use_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor service is ready and inspectable; provider start driver remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor service is blocked until invoker, generic executor, writer and storage are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_provider_start_driver_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start provider start driver gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates provider start driver input and delegates to AgentCodexRealInvokerPostStartProviderStartDriverGate.'],
                ['id' => 'T2', 'title' => 'Preserve no process/adapter boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares the pre-start guarded projection while process start, provider calls, adapter invocation, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start provider start driver gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful bridge preparation, idempotent retry, missing receipt-use metadata, dispatch-allowed rejection, missing sandbox binding and invalid hash rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start provider start driver gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next adapter invocation boundary without preparing provider start in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_provider_start_driver_gate_invoker_prepares_pre_start_guarded_projection_without_starting_codex',
                'codex_real_invoker_post_start_provider_start_driver_gate_invoker_requires_dispatch_receipt_use_metadata',
                'codex_real_invoker_post_start_provider_start_driver_gate_invoker_requires_sandbox_binding_and_release_authorization',
                'codex_real_invoker_post_start_provider_start_driver_gate_invoker_keeps_adapter_invocation_disabled',
                'codex_real_invoker_post_start_provider_start_driver_gate_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'post_start_provider_start_driver_gate_call_allowed_by_future_invoker' => true,
                'pre_start_guarded_run_write_allowed_by_future_invoker' => true,
                'post_start_provider_start_driver_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'provider_process_call_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'adapter_execution_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_start_driver_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate implementation packet is ready; it scopes the provider-start bridge before adapter invocation boundary.',
        ];
    }
}
