<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStartEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStarterReadinessGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart5Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_process_start_envelope_builder_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-START-ENVELOPE-BUILDER-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_process_start_envelope_builder_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker process start envelope invoker', 'type' => 'service', 'acceptance' => 'Invoker validates envelope input and delegates to AgentCodexRealInvokerProcessStartEnvelopeBuilder.'],
                ['id' => 'T2', 'title' => 'Preserve actual start boundary after envelope build', 'type' => 'service_logic', 'acceptance' => 'Invoker records process start envelope metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker process start envelope invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful envelope build, idempotent retry, invalid start command hash rejection, missing rehearsal metadata and duplicate envelope rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker process start envelope status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next start execution gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_process_start_envelope_builder_invoker_records_envelope_without_starting_codex',
                'codex_real_invoker_process_start_envelope_builder_invoker_requires_actual_process_start_rehearsal_metadata',
                'codex_real_invoker_process_start_envelope_builder_invoker_requires_start_command_hash',
                'codex_real_invoker_process_start_envelope_builder_invoker_is_idempotent_by_envelope_id',
                'codex_real_invoker_process_start_envelope_builder_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_process_start_envelope_builder_call_allowed_by_future_invoker' => true,
                'process_start_envelope_metadata_allowed_by_future_invoker' => true,
                'start_envelope_ready_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_start_envelope_builder_allowed' => false,
            'start_envelope_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_build_start_envelope',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope implementation packet is ready; it scopes envelope metadata and still stops before start execution gate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $envelopeReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class)
            && method_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class, 'buildStartEnvelope');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class, 'buildCodexRealInvokerProcessStartEnvelope');

        $envelopeRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_process_start_envelope->real_invoker_process_start_envelope_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $envelopeReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_process_start_envelope_builder_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'buildCodexRealInvokerProcessStartEnvelope',
            'generic_process_start_envelope_builder_service' => AgentCodexRealInvokerProcessStartEnvelopeBuilder::class,
            'generic_process_start_envelope_builder_service_ready' => $envelopeReady,
            'generic_process_start_envelope_builder_canonical_method' => 'buildStartEnvelope',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_process_start_envelope_built_run_count' => $envelopeRunsQuery === null ? null : (clone $envelopeRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_process_start_envelope_when_called_with_rehearsal_input' => true,
                'process_start_envelope_is_not_actual_process_invocation' => true,
                'process_start_envelope_keeps_external_process_stopped' => true,
                'codex_real_invoker_start_execution_gate_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_builder_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_start_envelope_builder_allowed' => false,
            'start_envelope_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_does_not_call_process_start_envelope_builder',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope service is ready and inspectable; status remains read-only and start execution gate is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope service is blocked until invoker, generic envelope builder and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_hash');
        $startGateReady = class_exists(AgentCodexRealInvokerStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerStartExecutionGate::class, 'authorizeStartExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class, 'authorizeCodexRealInvokerStartExecution');
        $envelopeReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class)
            && method_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class, 'buildStartEnvelope');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'start_execution_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_start_execution_gate_contract_ready',
            'start_execution_gate_contract_hash_present' => $contractHash !== '',
            'process_start_envelope_builder_status_ready' => data_get($contract, 'source_codex_real_invoker_process_start_envelope_builder_status') === 'one_shot_tick_codex_real_invoker_process_start_envelope_builder_service_ready',
            'generic_start_execution_gate_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_start_execution_gate_contract_status') === 'codex_real_invoker_start_execution_gate_contract_template_ready',
            'codex_real_invoker_start_execution_gate_ready' => $startGateReady,
            'codex_real_invoker_start_execution_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeReady,
            'canonical_start_execution_gate_method_ready' => data_get($contract, 'release_boundary.canonical_start_execution_gate_method') === 'authorizeStartExecution',
            'gate_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_gate') === false,
            'gate_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_gate') === false,
            'gate_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_gate') === false,
            'gate_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_gate') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_start_execution_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-START-EXECUTION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_start_execution_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_start_execution_gate_invoker',
                'delegate_to_agent_codex_real_invoker_start_execution_gate',
                'require_codex_real_invoker_process_start_envelope_metadata',
                'require_start_execution_hashes',
                'authorize_start_execution_gate_without_process_start',
                'preserve_actual_process_start_disabled_until_process_starter_readiness_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_start_execution_gate_call_allowed_here' => false,
                'start_execution_gate_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_allowed' => false,
            'start_execution_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight_does_not_authorize_start_execution',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate preflight is blocked until envelope, start gate, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_start_execution_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-START-EXECUTION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_start_execution_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_start_execution_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker start execution gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates start execution input and delegates to AgentCodexRealInvokerStartExecutionGate.'],
                ['id' => 'T2', 'title' => 'Preserve actual process start boundary after start execution authorization', 'type' => 'service_logic', 'acceptance' => 'Invoker records start execution gate metadata while actual process start, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker start execution gate invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful authorization, idempotent retry, invalid execution policy hash rejection, missing envelope metadata and duplicate gate rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker start execution gate status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next process starter readiness slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_start_execution_gate_invoker_records_authorization_without_starting_codex',
                'codex_real_invoker_start_execution_gate_invoker_requires_process_start_envelope_metadata',
                'codex_real_invoker_start_execution_gate_invoker_requires_execution_gate_policy_hash',
                'codex_real_invoker_start_execution_gate_invoker_is_idempotent_by_gate_id',
                'codex_real_invoker_start_execution_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_start_execution_gate_call_allowed_by_future_invoker' => true,
                'start_execution_gate_metadata_allowed_by_future_invoker' => true,
                'start_execution_authorized_by_packet' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_allowed' => false,
            'start_execution_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet_does_not_authorize_start_execution',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate implementation packet is ready; it scopes gate metadata and still stops before process starter readiness.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $startGateReady = class_exists(AgentCodexRealInvokerStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerStartExecutionGate::class, 'authorizeStartExecution');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class, 'authorizeCodexRealInvokerStartExecution');

        $startGateRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_start_execution_gate->real_invoker_start_execution_gate_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $startGateReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_start_execution_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerStartExecution',
            'generic_start_execution_gate_service' => AgentCodexRealInvokerStartExecutionGate::class,
            'generic_start_execution_gate_service_ready' => $startGateReady,
            'generic_start_execution_gate_canonical_method' => 'authorizeStartExecution',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_start_execution_gate_authorized_run_count' => $startGateRunsQuery === null ? null : (clone $startGateRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_start_execution_gate_when_called_with_envelope_input' => true,
                'start_execution_gate_is_not_actual_process_invocation' => true,
                'start_execution_gate_keeps_external_process_stopped' => true,
                'codex_real_invoker_process_starter_readiness_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_start_execution_gate_allowed' => false,
            'start_execution_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_does_not_call_start_execution_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate service is ready and inspectable; status remains read-only and process starter readiness is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate service is blocked until invoker, generic start execution gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_hash');
        $readinessReady = class_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class, 'prepareProcessStarter');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class, 'prepareCodexRealInvokerProcessStarter');
        $startGateReady = class_exists(AgentCodexRealInvokerStartExecutionGate::class)
            && method_exists(AgentCodexRealInvokerStartExecutionGate::class, 'authorizeStartExecution');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'process_starter_readiness_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_process_starter_readiness_gate_contract_ready',
            'process_starter_readiness_gate_contract_hash_present' => $contractHash !== '',
            'start_execution_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_start_execution_gate_status') === 'one_shot_tick_codex_real_invoker_start_execution_gate_service_ready',
            'generic_process_starter_readiness_gate_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_process_starter_readiness_gate_contract_status') === 'codex_real_invoker_process_starter_readiness_gate_contract_template_ready',
            'codex_real_invoker_process_starter_readiness_gate_ready' => $readinessReady,
            'codex_real_invoker_process_starter_readiness_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_start_execution_gate_ready' => $startGateReady,
            'canonical_process_starter_readiness_gate_method_ready' => data_get($contract, 'release_boundary.canonical_process_starter_readiness_gate_method') === 'prepareProcessStarter',
            'gate_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_gate') === false,
            'gate_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_gate') === false,
            'gate_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_gate') === false,
            'gate_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_gate') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-STARTER-READINESS-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_process_starter_readiness_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_process_starter_readiness_gate_invoker',
                'delegate_to_agent_codex_real_invoker_process_starter_readiness_gate',
                'require_codex_real_invoker_start_execution_gate_metadata',
                'require_process_starter_manifest_and_supervisor_hashes',
                'prepare_process_starter_without_process_start',
                'preserve_actual_process_start_disabled_until_manual_start_executor_receipt',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_process_starter_readiness_gate_call_allowed_here' => false,
                'process_starter_readiness_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_allowed' => false,
            'process_starter_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_prepare_process_starter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness gate preflight is blocked until start execution, process starter readiness, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-STARTER-READINESS-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_process_starter_readiness_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_process_starter_readiness_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker process starter readiness gate invoker', 'type' => 'service', 'acceptance' => 'Invoker validates process starter readiness input and delegates to AgentCodexRealInvokerProcessStarterReadinessGate.'],
                ['id' => 'T2', 'title' => 'Preserve actual process start boundary after process starter readiness', 'type' => 'service_logic', 'acceptance' => 'Invoker records process starter readiness metadata while actual process start, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker process starter readiness invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful preparation, idempotent retry, invalid supervisor binding hash rejection, missing start execution metadata and duplicate readiness rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker process starter readiness status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next manual start executor receipt slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_process_starter_readiness_gate_invoker_records_readiness_without_starting_codex',
                'codex_real_invoker_process_starter_readiness_gate_invoker_requires_start_execution_gate_metadata',
                'codex_real_invoker_process_starter_readiness_gate_invoker_requires_supervisor_binding_hash',
                'codex_real_invoker_process_starter_readiness_gate_invoker_is_idempotent_by_readiness_id',
                'codex_real_invoker_process_starter_readiness_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_process_starter_readiness_gate_call_allowed_by_future_invoker' => true,
                'process_starter_readiness_metadata_allowed_by_future_invoker' => true,
                'process_starter_ready_by_packet' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_allowed' => false,
            'process_starter_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_prepare_process_starter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness implementation packet is ready; it scopes starter readiness metadata and still stops before manual start executor receipt.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $readinessReady = class_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class, 'prepareProcessStarter');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class, 'prepareCodexRealInvokerProcessStarter');

        $readinessRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_process_starter_readiness_gate->real_invoker_process_starter_readiness_gate_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $readinessReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_process_starter_readiness_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStarterReadinessGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerProcessStarter',
            'generic_process_starter_readiness_gate_service' => AgentCodexRealInvokerProcessStarterReadinessGate::class,
            'generic_process_starter_readiness_gate_service_ready' => $readinessReady,
            'generic_process_starter_readiness_gate_canonical_method' => 'prepareProcessStarter',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_process_starter_readiness_gate_prepared_run_count' => $readinessRunsQuery === null ? null : (clone $readinessRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_process_starter_readiness_gate_when_called_with_start_execution_input' => true,
                'process_starter_readiness_gate_is_not_actual_process_invocation' => true,
                'process_starter_readiness_gate_keeps_external_process_stopped' => true,
                'codex_real_invoker_manual_start_executor_receipt_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_process_starter_readiness_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_process_starter_readiness_gate_allowed' => false,
            'process_starter_ready' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_does_not_call_process_starter_readiness_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_starter_readiness_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness service is ready and inspectable; status remains read-only and manual start executor receipt is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker process starter readiness service is blocked until invoker, generic process starter readiness gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_hash');
        $writerReady = class_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class, 'writeManualStartExecutorReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class, 'writeCodexRealInvokerManualStartExecutorReceipt');
        $starterReady = class_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class)
            && method_exists(AgentCodexRealInvokerProcessStarterReadinessGate::class, 'prepareProcessStarter');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'manual_start_executor_receipt_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_manual_start_executor_receipt_contract_ready',
            'manual_start_executor_receipt_contract_hash_present' => $contractHash !== '',
            'process_starter_readiness_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_process_starter_readiness_gate_status') === 'one_shot_tick_codex_real_invoker_process_starter_readiness_gate_service_ready',
            'generic_manual_start_executor_receipt_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_manual_start_executor_receipt_contract_status') === 'codex_real_invoker_manual_start_executor_receipt_writer_contract_template_ready',
            'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $writerReady,
            'codex_real_invoker_manual_start_executor_receipt_invoker_ready' => $invokerReady,
            'codex_real_invoker_process_starter_readiness_gate_ready' => $starterReady,
            'canonical_manual_start_executor_receipt_writer_method_ready' => data_get($contract, 'release_boundary.canonical_manual_start_executor_receipt_writer_method') === 'writeManualStartExecutorReceipt',
            'receipt_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_receipt') === false,
            'receipt_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_receipt') === false,
            'receipt_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_receipt') === false,
            'receipt_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_receipt') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-MANUAL-START-EXECUTOR-RECEIPT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_manual_start_executor_receipt_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_manual_start_executor_receipt_invoker',
                'delegate_to_agent_codex_real_invoker_manual_start_executor_receipt_writer',
                'require_codex_real_invoker_process_starter_readiness_metadata',
                'require_operator_presence_and_no_autostart_hashes',
                'write_manual_start_executor_receipt_without_process_start',
                'preserve_actual_process_start_disabled_until_operator_start_handoff',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_manual_start_executor_receipt_call_allowed_here' => false,
                'manual_start_executor_receipt_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_does_not_write_manual_receipt',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt preflight is blocked until process starter readiness, manual receipt writer, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-MANUAL-START-EXECUTOR-RECEIPT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_manual_start_executor_receipt_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_manual_start_executor_receipt_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker manual start executor receipt invoker', 'type' => 'service', 'acceptance' => 'Invoker validates manual start receipt input and delegates to AgentCodexRealInvokerManualStartExecutorReceiptWriter.'],
                ['id' => 'T2', 'title' => 'Preserve actual process start boundary after manual receipt', 'type' => 'service_logic', 'acceptance' => 'Invoker records manual start receipt metadata while actual process start, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker manual start receipt invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful receipt, idempotent retry, invalid operator presence hash rejection, missing process starter readiness metadata and duplicate receipt rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker manual start receipt status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next operator start handoff slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_manual_start_executor_receipt_invoker_records_receipt_without_starting_codex',
                'codex_real_invoker_manual_start_executor_receipt_invoker_requires_process_starter_readiness_metadata',
                'codex_real_invoker_manual_start_executor_receipt_invoker_requires_operator_presence_hash',
                'codex_real_invoker_manual_start_executor_receipt_invoker_is_idempotent_by_receipt_id',
                'codex_real_invoker_manual_start_executor_receipt_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_manual_start_executor_receipt_call_allowed_by_future_invoker' => true,
                'manual_start_executor_receipt_metadata_allowed_by_future_invoker' => true,
                'manual_operator_start_required_by_packet' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet_does_not_write_manual_receipt',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt implementation packet is ready; it scopes manual receipt metadata and still stops before operator start handoff.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $writerReady = class_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class, 'writeManualStartExecutorReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class, 'writeCodexRealInvokerManualStartExecutorReceipt');

        $manualReceiptRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_manual_start_executor_receipt->manual_start_executor_receipt_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $writerReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_manual_start_executor_receipt_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerManualStartExecutorReceiptInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'writeCodexRealInvokerManualStartExecutorReceipt',
            'generic_manual_start_executor_receipt_writer_service' => AgentCodexRealInvokerManualStartExecutorReceiptWriter::class,
            'generic_manual_start_executor_receipt_writer_service_ready' => $writerReady,
            'generic_manual_start_executor_receipt_writer_canonical_method' => 'writeManualStartExecutorReceipt',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_manual_start_executor_receipt_written_run_count' => $manualReceiptRunsQuery === null ? null : (clone $manualReceiptRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_manual_start_executor_receipt_when_called_with_process_starter_readiness_input' => true,
                'manual_start_executor_receipt_is_not_actual_process_invocation' => true,
                'manual_start_executor_receipt_keeps_external_process_stopped' => true,
                'operator_start_handoff_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_manual_start_executor_receipt_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_manual_start_executor_receipt_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_does_not_call_manual_receipt_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_manual_start_executor_receipt_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt service is ready and inspectable; status remains read-only and operator start handoff is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker manual start executor receipt service is blocked until invoker, generic manual receipt writer and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_contract_hash');
        $builderReady = class_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class, 'buildOperatorStartHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class, 'buildCodexRealInvokerOperatorStartHandoff');
        $manualReceiptReady = class_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerManualStartExecutorReceiptWriter::class, 'writeManualStartExecutorReceipt');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'operator_start_handoff_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_operator_start_handoff_contract_ready',
            'operator_start_handoff_contract_hash_present' => $contractHash !== '',
            'manual_start_executor_receipt_status_ready' => data_get($contract, 'source_codex_real_invoker_manual_start_executor_receipt_status') === 'one_shot_tick_codex_real_invoker_manual_start_executor_receipt_service_ready',
            'generic_operator_start_handoff_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_operator_start_handoff_contract_status') === 'codex_real_invoker_operator_start_handoff_builder_contract_template_ready',
            'codex_real_invoker_operator_start_handoff_builder_ready' => $builderReady,
            'codex_real_invoker_operator_start_handoff_invoker_ready' => $invokerReady,
            'codex_real_invoker_manual_start_executor_receipt_writer_ready' => $manualReceiptReady,
            'canonical_operator_start_handoff_builder_method_ready' => data_get($contract, 'release_boundary.canonical_operator_start_handoff_builder_method') === 'buildOperatorStartHandoff',
            'handoff_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_handoff') === false,
            'handoff_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_handoff') === false,
            'handoff_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_handoff') === false,
            'handoff_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_handoff') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-OPERATOR-START-HANDOFF-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_operator_start_handoff_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_operator_start_handoff_invoker',
                'delegate_to_agent_codex_real_invoker_operator_start_handoff_builder',
                'require_codex_real_invoker_manual_start_executor_receipt_metadata',
                'require_handoff_packet_and_operator_runbook_hashes',
                'build_operator_start_handoff_without_process_start',
                'preserve_actual_process_start_disabled_until_post_start_receipt_contract',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_operator_start_handoff_call_allowed_here' => false,
                'operator_start_handoff_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_operator_start_handoff_allowed' => false,
            'manual_operator_start_required' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_does_not_build_handoff',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff preflight is blocked until manual receipt, operator handoff, storage and no-runtime prerequisites are ready.',
        ];
    }
}
