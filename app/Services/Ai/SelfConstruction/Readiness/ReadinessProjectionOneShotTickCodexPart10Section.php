<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart10Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffContract(array $options = []): array
    {
        $receiptStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptStatus($options);
        $receiptStatus = (array) data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status', []);
        $postStartHandoffPayload = $this->parent->agentCodexRealInvokerPostStartOperatorStartHandoffBuilderContractTemplate($options);
        $handoffPayload = $this->parent->agentCodexRealInvokerOperatorStartHandoffBuilderContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-OPERATOR-START-HANDOFF-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_status' => data_get($receiptStatus, 'status'),
            'source_codex_real_invoker_post_start_manual_start_executor_receipt_status_hash' => data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_status_hash'),
            'source_codex_real_invoker_post_start_operator_start_handoff_contract_status' => data_get($postStartHandoffPayload, 'status'),
            'source_codex_real_invoker_post_start_operator_start_handoff_contract_hash' => data_get($postStartHandoffPayload, 'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_hash'),
            'source_codex_real_invoker_operator_start_handoff_contract_status' => data_get($handoffPayload, 'status'),
            'source_codex_real_invoker_operator_start_handoff_contract_hash' => data_get($handoffPayload, 'codex_real_invoker_operator_start_handoff_builder_contract_template_hash'),
            'operator_start_handoff' => [
                'canonical_post_start_operator_start_handoff_builder' => AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class,
                'canonical_post_start_operator_start_handoff_builder_method' => 'buildPostStartOperatorStartHandoff',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerPostStartOperatorStartHandoff',
                'generic_operator_start_handoff_builder' => AgentCodexRealInvokerOperatorStartHandoffBuilder::class,
                'generic_operator_start_handoff_builder_method' => 'buildOperatorStartHandoff',
                'post_start_manual_start_executor_receipt_required_before_handoff' => true,
                'post_start_evidence_acceptance_bridge_required_before_handoff' => true,
                'gate_delegates_to_codex_real_invoker_operator_start_handoff_builder' => true,
                'operator_start_handoff_ready_by_contract' => true,
                'handoff_is_not_actual_process_start' => true,
                'actual_process_start_allowed_by_contract' => false,
                'process_started_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'self_programming_allowed_by_contract' => false,
                'idempotency_key' => 'operator_start_handoff_id',
            ],
            'forbidden_true_input_flags' => [
                'actual_process_start_allowed', 'provider_process_call_allowed', 'adapter_invocation_allowed',
                'adapter_execution_allowed', 'token_spend_allowed', 'dispatch_allowed', 'self_programming_allowed',
                'external_process_started', 'provider_started', 'process_started',
            ],
            'allowed_future_mutations' => [
                'record_codex_real_invoker_operator_start_handoff_metadata_on_provider_start_run',
                'append_codex_real_invoker_operator_start_handoff_evidence_event',
                'record_codex_real_invoker_post_start_operator_start_handoff_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'invoke_codex_process', 'spawn_shell_or_subprocess', 'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex', 'enable_adapter_execution', 'spend_provider_tokens',
                'start_actual_process_without_separate_post_start_receipt_contract', 'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract_does_not_dispatch_work',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff contract is ready; post-start receipt contract remains separate.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract_hash');
        $builderReady = class_exists(AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class, 'buildPostStartOperatorStartHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker::class, 'prepareCodexRealInvokerPostStartOperatorStartHandoff');
        $genericReady = class_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class, 'buildOperatorStartHandoff');
        $receiptReady = class_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class, 'writePostStartManualStartExecutorReceipt');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_operator_start_handoff_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_contract_ready',
            'post_start_operator_start_handoff_contract_hash_present' => $contractHash !== '',
            'post_start_manual_start_executor_receipt_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_manual_start_executor_receipt_status') === 'one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt_service_ready',
            'generic_post_start_operator_start_handoff_builder_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_operator_start_handoff_contract_status') === 'codex_real_invoker_post_start_operator_start_handoff_builder_contract_template_ready',
            'generic_operator_start_handoff_builder_template_ready' => data_get($contract, 'source_codex_real_invoker_operator_start_handoff_contract_status') === 'codex_real_invoker_operator_start_handoff_builder_contract_template_ready',
            'codex_real_invoker_post_start_operator_start_handoff_builder_ready' => $builderReady,
            'codex_real_invoker_post_start_operator_start_handoff_invoker_ready' => $invokerReady,
            'codex_real_invoker_operator_start_handoff_builder_ready' => $genericReady,
            'codex_real_invoker_post_start_manual_start_executor_receipt_writer_ready' => $receiptReady,
            'contract_delegates_to_codex_real_invoker_operator_start_handoff_builder' => data_get($contract, 'operator_start_handoff.gate_delegates_to_codex_real_invoker_operator_start_handoff_builder') === true,
            'contract_declares_handoff_is_not_actual_process_start' => data_get($contract, 'operator_start_handoff.handoff_is_not_actual_process_start') === true,
            'contract_requires_post_start_receipt_contract' => in_array('start_actual_process_without_separate_post_start_receipt_contract', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
            'contract_lists_runtime_enabling_flags_as_forbidden' => in_array('actual_process_start_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('process_started', (array) data_get($contract, 'forbidden_true_input_flags', []), true)
                && in_array('self_programming_allowed', (array) data_get($contract, 'forbidden_true_input_flags', []), true),
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-OPERATOR-START-HANDOFF-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_operator_start_handoff_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'operator_start_handoff_ready_after_future_invoker' => true,
                'post_start_receipt_contract_required_after_handoff' => true,
                'actual_process_start_allowed_here' => false,
                'process_started_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff preflight is blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffPreflight($options);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-OPERATOR-START-HANDOFF-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_operator_start_handoff_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose scheduler invoker for post-start operator start handoff', 'type' => 'service', 'acceptance' => 'Invoker validates input and delegates to AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder.'],
                ['id' => 'T2', 'title' => 'Reject runtime-enabling input flags', 'type' => 'service_logic', 'acceptance' => 'Invoker rejects forbidden true flags before delegation.'],
                ['id' => 'T3', 'title' => 'Add dedicated tests', 'type' => 'test', 'acceptance' => 'Tests cover build, idempotency, hash/bridge rejections, ledger single write and rollback.'],
                ['id' => 'T4', 'title' => 'Expose handoff status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports status without invoking the builder.'],
            ],
            'task_count' => 4,
            'implementation_policy' => [
                'operator_start_handoff_ready_after_future_invoker' => true,
                'post_start_receipt_contract_required_after_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'process_started_by_packet' => false,
                'dispatch_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_implementation_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff implementation packet is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $builderReady = class_exists(AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class, 'buildPostStartOperatorStartHandoff');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker::class, 'prepareCodexRealInvokerPostStartOperatorStartHandoff');
        $genericReady = class_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class)
            && method_exists(AgentCodexRealInvokerOperatorStartHandoffBuilder::class, 'buildOperatorStartHandoff');
        $receiptReady = class_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class)
            && method_exists(AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class, 'writePostStartManualStartExecutorReceipt');

        $observedRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_operator_start_handoff->operator_start_handoff_id')
            : null;
        $providerRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_real_invoker_operator_start_handoff->operator_start_handoff_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $builderReady && $invokerReady && $genericReady && $receiptReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartOperatorStartHandoff',
            'generic_post_start_operator_start_handoff_builder_service' => AgentCodexRealInvokerPostStartOperatorStartHandoffBuilder::class,
            'generic_post_start_operator_start_handoff_builder_service_ready' => $builderReady,
            'codex_real_invoker_operator_start_handoff_builder_service' => AgentCodexRealInvokerOperatorStartHandoffBuilder::class,
            'codex_real_invoker_operator_start_handoff_builder_ready' => $genericReady,
            'post_start_manual_start_executor_receipt_writer_service' => AgentCodexRealInvokerPostStartManualStartExecutorReceiptWriter::class,
            'post_start_manual_start_executor_receipt_writer_ready' => $receiptReady,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_operator_start_handoff_recorded_run_count' => $observedRunsQuery === null ? null : (clone $observedRunsQuery)->count(),
            'provider_start_runs_with_codex_real_invoker_operator_start_handoff_count' => $providerRunsQuery === null ? null : (clone $providerRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'post_start_receipt_contract_required_before_actual_process' => true,
                'actual_process_start_allowed_here' => false,
                'process_started_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'actual_process_start_allowed' => false,
            'process_started' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff_status_does_not_dispatch_work',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff service is ready and inspectable.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start operator start handoff service is blocked.',
        ];
    }
}
