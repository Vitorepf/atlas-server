<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartLivenessMonitor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartReceiptContractBuilder;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart6Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_operator_start_handoff_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-OPERATOR-START-HANDOFF-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_operator_start_handoff_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_operator_start_handoff_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker operator start handoff invoker', 'type' => 'service', 'acceptance' => 'Invoker validates operator start handoff input and delegates to AgentCodexRealInvokerOperatorStartHandoffBuilder.'],
                ['id' => 'T2', 'title' => 'Preserve actual process start boundary after operator handoff', 'type' => 'service_logic', 'acceptance' => 'Invoker records operator start handoff metadata while actual process start, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker operator handoff invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful handoff, idempotent retry, invalid handoff hash rejection, missing manual receipt metadata and duplicate handoff rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker operator handoff status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next post-start receipt contract slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_operator_start_handoff_invoker_records_handoff_without_starting_codex',
                'codex_real_invoker_operator_start_handoff_invoker_requires_manual_start_receipt_metadata',
                'codex_real_invoker_operator_start_handoff_invoker_requires_handoff_packet_hash',
                'codex_real_invoker_operator_start_handoff_invoker_is_idempotent_by_handoff_id',
                'codex_real_invoker_operator_start_handoff_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_operator_start_handoff_call_allowed_by_future_invoker' => true,
                'operator_start_handoff_metadata_allowed_by_future_invoker' => true,
                'manual_operator_start_required_by_packet' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet_does_not_build_handoff',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff implementation packet is ready; it scopes manual external-start handoff metadata and still stops before post-start receipt contract.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $builderReady = class_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class, 'buildOperatorStartHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class, 'buildCodexRealInvokerOperatorStartHandoff');

        $handoffRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_operator_start_handoff->operator_start_handoff_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $builderReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_operator_start_handoff_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerOperatorStartHandoffInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'buildCodexRealInvokerOperatorStartHandoff',
            'generic_operator_start_handoff_builder_service' => AgentCodexRealInvokerOperatorStartHandoffBuilder::class,
            'generic_operator_start_handoff_builder_service_ready' => $builderReady,
            'generic_operator_start_handoff_builder_canonical_method' => 'buildOperatorStartHandoff',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_operator_start_handoff_built_run_count' => $handoffRunsQuery === null ? null : (clone $handoffRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_operator_start_handoff_when_called_with_manual_receipt_input' => true,
                'operator_start_handoff_is_not_actual_process_invocation' => true,
                'operator_start_handoff_keeps_external_process_stopped' => true,
                'post_start_receipt_contract_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_operator_start_handoff_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status_does_not_call_handoff_builder',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_operator_start_handoff_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff service is ready and inspectable; status remains read-only and post-start receipt contract is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker operator start handoff service is blocked until invoker, generic handoff builder and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_hash');
        $builderReady = class_exists(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            && method_exists(AgentCodexRealInvokerPostStartReceiptContractBuilder::class, 'buildPostStartReceiptContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class, 'buildCodexRealInvokerPostStartReceiptContract');
        $operatorHandoffReady = class_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class, 'buildOperatorStartHandoff');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_receipt_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_receipt_contract_ready',
            'post_start_receipt_contract_hash_present' => $contractHash !== '',
            'operator_start_handoff_status_ready' => data_get($contract, 'source_codex_real_invoker_operator_start_handoff_status') === 'one_shot_tick_codex_real_invoker_operator_start_handoff_service_ready',
            'generic_post_start_receipt_contract_builder_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_receipt_contract_builder_status') === 'codex_real_invoker_post_start_receipt_contract_builder_contract_template_ready',
            'codex_real_invoker_post_start_receipt_contract_builder_ready' => $builderReady,
            'codex_real_invoker_post_start_receipt_contract_invoker_ready' => $invokerReady,
            'codex_real_invoker_operator_start_handoff_builder_ready' => $operatorHandoffReady,
            'canonical_post_start_receipt_contract_builder_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_receipt_contract_builder_method') === 'buildPostStartReceiptContract',
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_does_not_accept_external_process_evidence' => data_get($contract, 'release_boundary.external_process_evidence_accepted_by_contract') === false,
            'contract_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'release_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-RECEIPT-CONTRACT-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_receipt_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_receipt_contract_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_receipt_contract_builder',
                'require_codex_real_invoker_operator_start_handoff_metadata',
                'require_external_process_identity_startup_pid_and_cost_contract_hashes',
                'build_post_start_receipt_contract_without_accepting_external_process_evidence',
                'preserve_actual_process_start_disabled_until_post_start_evidence_receipt',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_post_start_receipt_contract_call_allowed_here' => false,
                'post_start_receipt_contract_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_does_not_accept_external_process_evidence',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract preflight is blocked until handoff, builder, invoker, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_receipt_contract_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-RECEIPT-CONTRACT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_receipt_contract_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_receipt_contract_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start receipt contract invoker', 'type' => 'service', 'acceptance' => 'Invoker validates post-start receipt contract input and delegates to AgentCodexRealInvokerPostStartReceiptContractBuilder.'],
                ['id' => 'T2', 'title' => 'Preserve external evidence boundary after receipt contract', 'type' => 'service_logic', 'acceptance' => 'Invoker records post-start receipt contract metadata while external process evidence acceptance, dispatch and token spend remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start receipt contract invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful contract creation, idempotent retry, invalid external process identity contract hash, missing operator handoff metadata and duplicate contract rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start receipt contract status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next post-start evidence receipt slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_receipt_contract_invoker_records_contract_without_starting_codex',
                'codex_real_invoker_post_start_receipt_contract_invoker_requires_operator_start_handoff_metadata',
                'codex_real_invoker_post_start_receipt_contract_invoker_requires_external_process_identity_contract_hash',
                'codex_real_invoker_post_start_receipt_contract_invoker_is_idempotent_by_contract_id',
                'codex_real_invoker_post_start_receipt_contract_invoker_never_accepts_external_process_evidence_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_post_start_receipt_contract_call_allowed_by_future_invoker' => true,
                'post_start_receipt_contract_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_by_packet' => false,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet_does_not_accept_external_process_evidence',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract implementation packet is ready; it scopes the evidence contract and still stops before accepting live process evidence.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $builderReady = class_exists(AgentCodexRealInvokerPostStartReceiptContractBuilder::class)
            && method_exists(AgentCodexRealInvokerPostStartReceiptContractBuilder::class, 'buildPostStartReceiptContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class, 'buildCodexRealInvokerPostStartReceiptContract');

        $receiptContractRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_receipt_contract->post_start_receipt_contract_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $builderReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_receipt_contract_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'buildCodexRealInvokerPostStartReceiptContract',
            'generic_post_start_receipt_contract_builder_service' => AgentCodexRealInvokerPostStartReceiptContractBuilder::class,
            'generic_post_start_receipt_contract_builder_service_ready' => $builderReady,
            'generic_post_start_receipt_contract_builder_canonical_method' => 'buildPostStartReceiptContract',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_receipt_contract_built_run_count' => $receiptContractRunsQuery === null ? null : (clone $receiptContractRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_post_start_receipt_contract_when_called_with_operator_handoff_input' => true,
                'post_start_receipt_contract_is_not_external_process_evidence_acceptance' => true,
                'post_start_receipt_contract_keeps_external_process_evidence_unaccepted' => true,
                'post_start_evidence_receipt_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_receipt_contract_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_does_not_call_receipt_contract_builder',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract service is ready and inspectable; status remains read-only and post-start evidence receipt is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start receipt contract service is blocked until invoker, generic contract builder and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_evidence_receipt_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_evidence_receipt_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start evidence receipt invoker', 'type' => 'service', 'acceptance' => 'Invoker validates evidence receipt input and delegates to AgentCodexRealInvokerPostStartEvidenceReceiptWriter.'],
                ['id' => 'T2', 'title' => 'Preserve no Atlas-owned process spawn boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records external-start evidence only with no Atlas process spawn, no token spend and no dispatch.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start evidence receipt invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful evidence receipt, idempotent retry, invalid evidence hash, missing receipt contract metadata and duplicate receipt rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start evidence receipt status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next liveness monitor slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_evidence_receipt_invoker_records_external_evidence_without_starting_codex',
                'codex_real_invoker_post_start_evidence_receipt_invoker_requires_post_start_receipt_contract_metadata',
                'codex_real_invoker_post_start_evidence_receipt_invoker_requires_no_atlas_process_spawn_attestation_hash',
                'codex_real_invoker_post_start_evidence_receipt_invoker_is_idempotent_by_evidence_receipt_id',
                'codex_real_invoker_post_start_evidence_receipt_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_post_start_evidence_receipt_call_allowed_by_future_invoker' => true,
                'post_start_evidence_receipt_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_by_packet' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'atlas_process_spawn_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt implementation packet is ready; it scopes external-start evidence acceptance and still stops before liveness monitoring.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $writerReady = class_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class, 'writePostStartEvidenceReceipt');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class, 'writeCodexRealInvokerPostStartEvidenceReceipt');

        $evidenceReceiptRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_evidence_receipt->post_start_evidence_receipt_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $writerReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'writeCodexRealInvokerPostStartEvidenceReceipt',
            'generic_post_start_evidence_receipt_writer_service' => AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class,
            'generic_post_start_evidence_receipt_writer_service_ready' => $writerReady,
            'generic_post_start_evidence_receipt_writer_canonical_method' => 'writePostStartEvidenceReceipt',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_evidence_receipt_recorded_run_count' => $evidenceReceiptRunsQuery === null ? null : (clone $evidenceReceiptRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_post_start_evidence_receipt_when_called_with_external_evidence_input' => true,
                'post_start_evidence_receipt_accepts_external_process_evidence_only_as_attested_evidence' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_does_not_call_evidence_receipt_writer',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt service is ready and inspectable; status remains read-only and liveness monitoring is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt service is blocked until invoker, generic evidence writer and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_hash');
        $bridgeReady = class_exists(AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class)
            && method_exists(AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class, 'acceptPostStartEvidence');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class, 'acceptCodexRealInvokerPostStartEvidence');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_evidence_acceptance_bridge_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract_ready',
            'post_start_evidence_acceptance_bridge_contract_hash_present' => $contractHash !== '',
            'post_start_evidence_receipt_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_receipt_status') === 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_service_ready',
            'generic_post_start_evidence_acceptance_bridge_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status') === 'codex_real_invoker_post_start_evidence_acceptance_bridge_contract_template_ready',
            'codex_real_invoker_post_start_evidence_acceptance_bridge_ready' => $bridgeReady,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_ready' => $invokerReady,
            'canonical_post_start_evidence_acceptance_bridge_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_evidence_acceptance_bridge_method') === 'acceptPostStartEvidence',
            'contract_requires_post_start_operator_handoff' => data_get($contract, 'release_boundary.post_start_operator_start_handoff_required_before_acceptance') === true,
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
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-ACCEPTANCE-BRIDGE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_evidence_acceptance_bridge_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_evidence_acceptance_bridge_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_evidence_acceptance_bridge',
                'require_codex_real_invoker_post_start_operator_start_handoff_metadata',
                'require_post_start_receipt_contract_and_evidence_receipt_outputs',
                'require_operator_external_start_attestation_hash',
                'require_no_atlas_process_spawn_attestation_hash',
                'preserve_dispatch_disabled_until_liveness_monitor_and_dispatch_release',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_post_start_evidence_acceptance_bridge_call_allowed_here' => false,
                'post_start_evidence_acceptance_bridge_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge preflight is blocked until receipt, bridge, invoker, storage and no-spawn prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-ACCEPTANCE-BRIDGE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_evidence_acceptance_bridge_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start evidence acceptance bridge invoker', 'type' => 'service', 'acceptance' => 'Invoker validates acceptance bridge input and delegates to AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge.'],
                ['id' => 'T2', 'title' => 'Preserve no Atlas-owned process spawn boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker accepts external-start evidence only with no Atlas process spawn, no token spend and no dispatch.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start evidence acceptance bridge invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful acceptance, idempotent retry, invalid no-spawn attestation, missing handoff metadata and duplicate bridge rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start evidence acceptance bridge status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next liveness monitor slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_records_acceptance_without_starting_codex',
                'codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_requires_post_start_operator_handoff_metadata',
                'codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_requires_no_atlas_process_spawn_attestation_hash',
                'codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_is_idempotent_by_bridge_id',
                'codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_post_start_evidence_acceptance_bridge_call_allowed_by_future_invoker' => true,
                'post_start_evidence_acceptance_bridge_metadata_allowed_by_future_invoker' => true,
                'external_process_evidence_acceptance_allowed_by_packet' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'atlas_process_spawn_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge implementation packet is ready; it scopes acceptance before liveness monitoring.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $bridgeReady = class_exists(AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class)
            && method_exists(AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class, 'acceptPostStartEvidence');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class, 'acceptCodexRealInvokerPostStartEvidence');

        $acceptanceRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_evidence_acceptance_bridge->post_start_evidence_acceptance_bridge_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $bridgeReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'acceptCodexRealInvokerPostStartEvidence',
            'generic_post_start_evidence_acceptance_bridge_service' => AgentCodexRealInvokerPostStartEvidenceAcceptanceBridge::class,
            'generic_post_start_evidence_acceptance_bridge_service_ready' => $bridgeReady,
            'generic_post_start_evidence_acceptance_bridge_canonical_method' => 'acceptPostStartEvidence',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_recorded_run_count' => $acceptanceRunsQuery === null ? null : (clone $acceptanceRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_post_start_evidence_acceptance_bridge_when_called_with_external_evidence_input' => true,
                'post_start_evidence_acceptance_bridge_accepts_external_process_evidence_only_as_attested_evidence' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_evidence_acceptance_bridge_allowed' => false,
            'external_process_evidence_acceptance_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status_does_not_call_evidence_acceptance_bridge',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge service is ready and inspectable; status remains read-only and liveness monitoring is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence acceptance bridge service is blocked until invoker, generic acceptance bridge and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_hash');
        $monitorReady = class_exists(AgentCodexRealInvokerPostStartLivenessMonitor::class)
            && method_exists(AgentCodexRealInvokerPostStartLivenessMonitor::class, 'recordPostStartLiveness');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker::class, 'recordCodexRealInvokerPostStartLiveness');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_liveness_monitor_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_liveness_monitor_contract_ready',
            'post_start_liveness_monitor_contract_hash_present' => $contractHash !== '',
            'post_start_evidence_acceptance_bridge_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_evidence_acceptance_bridge_status') === 'one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_service_ready',
            'generic_post_start_liveness_monitor_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_liveness_monitor_status') === 'codex_real_invoker_post_start_liveness_monitor_contract_template_ready',
            'codex_real_invoker_post_start_liveness_monitor_ready' => $monitorReady,
            'codex_real_invoker_post_start_liveness_monitor_invoker_ready' => $invokerReady,
            'canonical_post_start_liveness_monitor_method_ready' => data_get($contract, 'release_boundary.canonical_post_start_liveness_monitor_method') === 'recordPostStartLiveness',
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'release_boundary.post_start_evidence_acceptance_bridge_required_before_liveness') === true,
            'contract_requires_no_provider_call_attestation' => data_get($contract, 'release_boundary.no_provider_call_attestation_required') === true,
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
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-LIVENESS-MONITOR-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_liveness_monitor_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_liveness_monitor_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_liveness_monitor',
                'require_codex_real_invoker_post_start_evidence_acceptance_bridge_metadata',
                'require_codex_real_invoker_post_start_evidence_receipt_metadata',
                'require_liveness_heartbeat_progress_visibility_and_no_provider_call_hashes',
                'preserve_dispatch_disabled_until_dispatch_release_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_post_start_liveness_monitor_call_allowed_here' => false,
                'post_start_liveness_monitor_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'atlas_process_spawn_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor preflight is blocked until acceptance, monitor, invoker, storage and no-provider-call prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-LIVENESS-MONITOR-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_liveness_monitor_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_post_start_liveness_monitor_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker post-start liveness monitor invoker', 'type' => 'service', 'acceptance' => 'Invoker validates liveness input and delegates to AgentCodexRealInvokerPostStartLivenessMonitor.'],
                ['id' => 'T2', 'title' => 'Preserve no provider call boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker records external liveness only with no Codex call, no token spend and no dispatch.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker post-start liveness monitor invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful liveness, idempotent retry, invalid state, missing acceptance bridge metadata and invalid no-provider-call attestation.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker post-start liveness monitor status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next dispatch release gate without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_post_start_liveness_monitor_invoker_records_external_liveness_without_calling_codex',
                'codex_real_invoker_post_start_liveness_monitor_invoker_requires_post_start_evidence_acceptance_bridge_metadata',
                'codex_real_invoker_post_start_liveness_monitor_invoker_requires_no_provider_call_attestation_hash',
                'codex_real_invoker_post_start_liveness_monitor_invoker_is_idempotent_by_monitor_id',
                'codex_real_invoker_post_start_liveness_monitor_invoker_never_dispatches_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_post_start_liveness_monitor_call_allowed_by_future_invoker' => true,
                'post_start_liveness_monitor_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'atlas_process_spawn_allowed_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_post_start_liveness_monitor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start liveness monitor implementation packet is ready; it scopes external liveness before dispatch release.',
        ];
    }
}
