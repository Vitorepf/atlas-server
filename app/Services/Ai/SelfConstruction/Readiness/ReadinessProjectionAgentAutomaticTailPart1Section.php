<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionAgentAutomaticTailPart1Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseReleaseContract(array $options = []): array
    {
        $invocationStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickGuardedRuntimeInvocationStatus($options);
        $invocationStatus = (array) data_get($invocationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status', []);

        $contract = [
            'status' => 'one_shot_tick_dispatch_receipt_use_release_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-DISPATCH-RECEIPT-USE-RELEASE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
            'source_guarded_runtime_invocation_status' => data_get($invocationStatus, 'status'),
            'source_guarded_runtime_invocation_status_hash' => data_get($invocationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_guarded_runtime_invocation_status_hash'),
            'release_boundary' => [
                'canonical_writer' => AgentDispatchExecutorReceiptUseWriter::class,
                'canonical_writer_method' => 'markReceiptUsedAtomically',
                'allowed_receipt_decision' => 'approve_dispatch_once',
                'allowed_receipt_status_before_use' => 'signed_pending_dispatch',
                'required_receipt_status_after_use' => 'used_pending_provider_start',
                'idempotency_key' => 'provider_start_attempt_id',
                'requires_signed_dispatch_receipt_hash' => true,
                'requires_executor_contract_hash' => true,
                'requires_executor_release_authorization_hash' => true,
            ],
            'required_input_fields_for_future_use' => [
                'receipt_hash',
                'executor_contract_hash',
                'executor_release_authorization_hash',
                'provider_start_attempt_id',
                'actor',
                'session',
                'packet_id',
                'provider',
                'reason',
            ],
            'allowed_future_mutations' => [
                'mark_one_signed_pending_dispatch_receipt_used',
                'write_receipt_use_payload',
                'append_one_receipt_use_evidence_event',
            ],
            'forbidden_even_after_receipt_use' => [
                'start_provider_process',
                'invoke_provider_adapter',
                'spend_provider_tokens',
                'merge_work_products',
                'mark_packet_completed',
                'enable_self_programming',
            ],
            'handoff_policy' => [
                'receipt_use_is_not_provider_start' => true,
                'provider_start_requires_separate_driver_release' => true,
                'adapter_invocation_requires_separate_provider_specific_contract' => true,
                'post_start_evidence_bridge_required_before_liveness_trust' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_dispatch_receipt_use_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_does_not_mark_receipt_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick dispatch receipt-use release contract is ready; it defines the future receipt-use boundary while still forbidding provider start.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUsePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseReleaseContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_release_contract_hash');
        $dispatchReceiptTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $writerReady = class_exists(AgentDispatchExecutorReceiptUseWriter::class)
            && method_exists(AgentDispatchExecutorReceiptUseWriter::class, 'markReceiptUsedAtomically');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_dispatch_receipt_use_release_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'guarded_runtime_invocation_service_ready' => data_get($contract, 'source_guarded_runtime_invocation_status') === 'one_shot_tick_guarded_runtime_invocation_service_ready',
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'receipt_use_writer_ready' => $writerReady,
            'canonical_writer_method_ready' => data_get($contract, 'release_boundary.canonical_writer_method') === 'markReceiptUsedAtomically',
            'receipt_use_is_not_provider_start' => data_get($contract, 'handoff_policy.receipt_use_is_not_provider_start') === true,
            'provider_start_requires_separate_driver_release' => data_get($contract, 'handoff_policy.provider_start_requires_separate_driver_release') === true,
            'adapter_invocation_requires_separate_provider_specific_contract' => data_get($contract, 'handoff_policy.adapter_invocation_requires_separate_provider_specific_contract') === true,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_receipt_use', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_dispatch_receipt_use_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-DISPATCH-RECEIPT-USE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_one_shot_scheduler_dispatch_receipt_use_invoker',
                'delegate_to_agent_dispatch_executor_receipt_use_writer',
                'require_executor_contract_hash_and_release_authorization_hash',
                'preserve_no_provider_start_after_receipt_use',
                'return_receipt_use_result_without_calling_provider_adapter',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'receipt_use_mark_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_dispatch_receipt_use_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_does_not_mark_receipt_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick dispatch receipt-use preflight is ready; the next slice may generate the scoped implementation packet.'
                : 'Automatic dispatch scheduler one-shot tick dispatch receipt-use preflight is blocked until writer, storage and release contract prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUsePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_dispatch_receipt_use_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-DISPATCH-RECEIPT-USE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_dispatch_receipt_use_preflight_status' => data_get($preflight, 'status'),
            'source_dispatch_receipt_use_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Create one-shot scheduler dispatch receipt-use invoker', 'type' => 'service', 'acceptance' => 'Invoker validates signed receipt-use input and delegates exactly once to AgentDispatchExecutorReceiptUseWriter.'],
                ['id' => 'T2', 'title' => 'Preserve no-provider boundary after receipt use', 'type' => 'service_logic', 'acceptance' => 'Invoker returns receipt-use result while provider_start_allowed, adapter_invocation_allowed, token_spend_allowed and self_programming_allowed remain false.'],
                ['id' => 'T3', 'title' => 'Add dispatch receipt-use invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful receipt use, idempotent retry, invalid hash rejection, packet/provider mismatch rejection and proof that provider start is not performed.'],
                ['id' => 'T4', 'title' => 'Expose receipt-use invoker status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports service readiness and next provider start driver release slice without marking receipts used in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'receipt_use_invoker_marks_one_signed_pending_dispatch_receipt_used',
                'receipt_use_invoker_is_idempotent_by_provider_start_attempt_id',
                'receipt_use_invoker_never_starts_provider',
                'receipt_use_invoker_never_calls_adapter',
                'receipt_use_invoker_never_spends_tokens_or_enables_self_programming',
            ],
            'required_gates' => [
                'php_lint_receipt_use_invoker',
                'dedicated_receipt_use_invoker_feature_tests',
                'generic_receipt_use_writer_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'receipt_use_mark_allowed_by_future_invoker' => true,
                'provider_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_start_provider_or_call_adapter',
                'need_to_spend_provider_tokens',
                'need_to_mark_packet_completed',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_dispatch_receipt_use_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet_does_not_mark_receipt_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick dispatch receipt-use implementation packet is ready; it scopes the future invoker that may mark receipt use and still stop before provider start.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverReleaseContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_release_contract_hash');
        $driverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class)
            && method_exists(AgentDispatchExecutorProviderStartDriver::class, 'startProviderOnce');
        $dispatchReceiptTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $releaseAuthorizationTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_executor_release_authorizations');
        $sandboxBindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_provider_start_driver_release_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'receipt_use_service_ready' => data_get($contract, 'source_dispatch_receipt_use_status') === 'one_shot_tick_dispatch_receipt_use_service_ready',
            'generic_provider_start_driver_preflight_ready' => data_get($contract, 'source_provider_start_driver_preflight_status') === 'agent_dispatch_executor_provider_start_driver_ready',
            'provider_start_driver_ready' => $driverReady,
            'canonical_driver_method_ready' => data_get($contract, 'release_boundary.canonical_driver_method') === 'startProviderOnce',
            'driver_does_not_start_external_process' => data_get($contract, 'release_boundary.provider_external_process_started_by_driver') === false,
            'adapter_invocation_still_forbidden' => data_get($contract, 'release_boundary.adapter_invocation_allowed_by_driver') === false,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'release_authorizations_table_ready' => $releaseAuthorizationTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingTableReady,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_driver', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_provider_start_driver_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-START-DRIVER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_one_shot_scheduler_provider_start_driver_invoker',
                'delegate_to_agent_dispatch_executor_provider_start_driver',
                'require_used_pending_provider_start_receipt',
                'require_active_sandbox_binding_key',
                'preserve_no_adapter_invocation_after_pre_start_run',
                'return_provider_start_prepared_result_without_spawning_provider_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'provider_start_driver_call_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_start_driver_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_start_driver_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_does_not_call_provider_start_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick provider start driver preflight is ready; the next slice may generate the scoped invoker implementation packet.'
                : 'Automatic dispatch scheduler one-shot tick provider start driver preflight is blocked until receipt-use, driver, storage and no-provider-start prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_provider_start_driver_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-PROVIDER-START-DRIVER-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_provider_start_driver_preflight_status' => data_get($preflight, 'status'),
            'source_provider_start_driver_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Create one-shot scheduler provider start driver invoker', 'type' => 'service', 'acceptance' => 'Invoker validates used receipt, release authorization hashes, sandbox binding key and provider start attempt before delegating to AgentDispatchExecutorProviderStartDriver.'],
                ['id' => 'T2', 'title' => 'Preserve external no-start boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker returns provider-start-prepared result while external provider process start, adapter invocation, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add provider start driver invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful pre-start guarded run, idempotent retry, missing used receipt rejection, sandbox mismatch rejection and proof that adapters/providers are not invoked.'],
                ['id' => 'T4', 'title' => 'Expose provider start driver invoker status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next adapter invocation boundary release slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'provider_start_driver_invoker_creates_pre_start_guarded_run',
                'provider_start_driver_invoker_writes_pre_start_heartbeat',
                'provider_start_driver_invoker_is_idempotent_by_provider_start_attempt_id',
                'provider_start_driver_invoker_never_starts_external_provider_process',
                'provider_start_driver_invoker_never_calls_adapter_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_provider_start_driver_invoker',
                'dedicated_provider_start_driver_invoker_feature_tests',
                'generic_provider_start_driver_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'provider_start_driver_call_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_by_packet' => false,
                'adapter_invocation_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'stop_conditions' => [
                'need_to_spawn_provider_process',
                'need_to_call_codex_claude_gemini_local_or_http_adapter',
                'need_to_spend_provider_tokens',
                'need_to_mark_packet_completed',
                'need_to_enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_provider_start_driver_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_start_driver_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet_does_not_call_provider_start_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick provider start driver implementation packet is ready; it scopes the future invoker that may prepare pre-start runtime state and still stop before adapter invocation.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickProviderStartDriverStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $genericDriverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class)
            && method_exists(AgentDispatchExecutorProviderStartDriver::class, 'startProviderOnce');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class, 'prepareProviderStartDriver');

        $preStartRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'pre_start_guarded')
            : null;
        $latestPreStartRun = $preStartRunsQuery === null
            ? null
            : (clone $preStartRunsQuery)->latest('updated_at')->first();

        $statusReady = $runsTableReady && $heartbeatsTableReady && $ledgerTableReady && $genericDriverReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_provider_start_driver_service_ready' : 'blocked',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareProviderStartDriver',
            'generic_driver_service' => AgentDispatchExecutorProviderStartDriver::class,
            'generic_driver_service_ready' => $genericDriverReady,
            'generic_driver_canonical_method' => 'startProviderOnce',
            'agent_runs_table_ready' => $runsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'pre_start_guarded_run_count' => $preStartRunsQuery === null ? null : (clone $preStartRunsQuery)->count(),
            'latest_pre_start_guarded_run' => $latestPreStartRun instanceof AtlasSelfConstructionAgentRun
                ? [
                    'agent_run_id' => $latestPreStartRun->id,
                    'run_key' => $latestPreStartRun->run_key,
                    'packet_id' => $latestPreStartRun->packet_id,
                    'provider' => $latestPreStartRun->provider,
                    'status' => $latestPreStartRun->status,
                    'provider_start_attempt_id' => data_get($latestPreStartRun->metadata, 'provider_start_attempt_id'),
                    'provider_started' => data_get($latestPreStartRun->metadata, 'provider_started'),
                    'adapter_invocation_allowed' => data_get($latestPreStartRun->metadata, 'adapter_invocation_allowed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_call_provider_start_driver_when_called_with_signed_input' => true,
                'provider_start_driver_is_pre_start_guard_not_external_process_start' => true,
                'provider_external_process_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_release_contract'
                : 'repair_one_shot_scheduler_tick_provider_start_driver_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'provider_start_driver_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_does_not_call_provider_start_driver',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_provider_start_driver_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick provider start driver service is ready and inspectable; status remains read-only and external provider start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick provider start driver service is blocked until invoker, generic driver and observability storage are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryReleaseContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_release_contract_hash');
        $boundaryReady = class_exists(AgentDispatchExecutorAdapterInvocationBoundary::class)
            && method_exists(AgentDispatchExecutorAdapterInvocationBoundary::class, 'prepareInvocation');
        $invokerDependencyReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickProviderStartDriverInvoker::class);
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'release_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_adapter_invocation_boundary_release_contract_ready',
            'release_contract_hash_present' => $contractHash !== '',
            'provider_start_driver_service_ready' => data_get($contract, 'source_provider_start_driver_status') === 'one_shot_tick_provider_start_driver_service_ready',
            'generic_adapter_invocation_boundary_preflight_ready' => data_get($contract, 'source_adapter_invocation_boundary_preflight_status') === 'agent_dispatch_executor_adapter_invocation_boundary_ready',
            'adapter_invocation_boundary_ready' => $boundaryReady,
            'provider_start_invoker_dependency_ready' => $invokerDependencyReady,
            'canonical_boundary_method_ready' => data_get($contract, 'release_boundary.canonical_boundary_method') === 'prepareInvocation',
            'boundary_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_boundary') === false,
            'boundary_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_boundary') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_boundary', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_adapter_invocation_boundary_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-ADAPTER-INVOCATION-BOUNDARY-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_release_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_one_shot_scheduler_adapter_invocation_boundary_invoker',
                'delegate_to_agent_dispatch_executor_adapter_invocation_boundary',
                'require_pre_start_guarded_run_key',
                'require_context_pack_hash_and_continuation_summary_hash',
                'preserve_no_adapter_execution_after_boundary',
                'return_adapter_invocation_prepared_result_without_spawning_provider_process',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'adapter_invocation_boundary_call_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_invocation_boundary_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_does_not_call_adapter_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick adapter invocation boundary preflight is ready; the next slice may generate the scoped boundary invoker implementation packet.'
                : 'Automatic dispatch scheduler one-shot tick adapter invocation boundary preflight is blocked until provider start, boundary, storage and no-adapter-execution prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_adapter_invocation_boundary_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-ADAPTER-INVOCATION-BOUNDARY-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'source_adapter_invocation_boundary_preflight_status' => data_get($preflight, 'status'),
            'source_adapter_invocation_boundary_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/AgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickAdapterInvocationBoundaryInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Create one-shot scheduler adapter invocation boundary invoker', 'type' => 'service', 'acceptance' => 'Invoker validates pre-start run, adapter invocation id, provider start attempt and context hashes before delegating to AgentDispatchExecutorAdapterInvocationBoundary.'],
                ['id' => 'T2', 'title' => 'Preserve no-adapter-execution boundary', 'type' => 'service_logic', 'acceptance' => 'Invoker returns adapter-invocation-prepared result while provider process start, adapter execution, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add adapter invocation boundary invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful boundary preparation, idempotent retry, invalid context hash rejection, non-pre-start run rejection and missing heartbeat rejection.'],
                ['id' => 'T4', 'title' => 'Expose adapter invocation boundary status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next provider adapter execution guard release slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'adapter_invocation_boundary_invoker_transitions_run_to_adapter_invocation_prepared',
                'adapter_invocation_boundary_invoker_records_adapter_descriptor_hash',
                'adapter_invocation_boundary_invoker_is_idempotent_by_adapter_invocation_id',
                'adapter_invocation_boundary_invoker_never_executes_adapter',
                'adapter_invocation_boundary_invoker_never_starts_provider_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_adapter_invocation_boundary_invoker',
                'dedicated_adapter_invocation_boundary_invoker_feature_tests',
                'generic_adapter_invocation_boundary_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'adapter_invocation_boundary_call_allowed_by_future_invoker' => true,
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_adapter_invocation_boundary_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'adapter_invocation_boundary_allowed' => false,
            'adapter_invocation_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet_does_not_call_adapter_boundary',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_adapter_invocation_boundary_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick adapter invocation boundary implementation packet is ready; it scopes the future invoker that may prepare adapter metadata and still stop before adapter execution.',
        ];
    }
}
