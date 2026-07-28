<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerActualProcessStartRehearsalExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerFinalProcessStartAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerGuardedProcessStartExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStartEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerSupervisedStartActivationGate;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionOneShotTickCodexPart4Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_hash');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker::class, 'prepareCodexRealInvokerSupervisedStartActivation');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'supervised_start_activation_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_supervised_start_activation_gate_contract_ready',
            'supervised_start_activation_gate_contract_hash_present' => $contractHash !== '',
            'executor_enablement_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_executor_enablement_gate_status') === 'one_shot_tick_codex_real_invoker_executor_enablement_gate_service_ready',
            'generic_supervised_start_activation_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_supervised_start_activation_gate_contract_status') === 'codex_real_invoker_supervised_start_activation_gate_contract_template_ready',
            'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
            'codex_real_invoker_supervised_start_activation_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
            'canonical_supervised_start_activation_gate_method_ready' => data_get($contract, 'release_boundary.canonical_supervised_start_activation_gate_method') === 'prepareActivation',
            'activation_arms_process_start_metadata' => data_get($contract, 'release_boundary.process_start_armed_by_activation') === true,
            'activation_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_activation') === false,
            'activation_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_activation') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-SUPERVISED-START-ACTIVATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_supervised_start_activation_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_supervised_start_activation_gate_invoker',
                'delegate_to_agent_codex_real_invoker_supervised_start_activation_gate',
                'require_codex_real_invoker_executor_enablement_metadata',
                'require_operator_start_activation_receipt_hash',
                'require_process_start_guard_hash',
                'preserve_process_start_disabled_until_guarded_process_start_executor',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_supervised_start_activation_gate_call_allowed_here' => false,
                'process_start_armed_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_arm_process_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate preflight is blocked until enablement, activation, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-SUPERVISED-START-ACTIVATION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_supervised_start_activation_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_supervised_start_activation_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerSupervisedStartActivationGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker supervised start activation invoker', 'type' => 'service', 'acceptance' => 'Invoker validates activation input and delegates to AgentCodexRealInvokerSupervisedStartActivationGate.'],
                ['id' => 'T2', 'title' => 'Preserve process-start boundary after activation', 'type' => 'service_logic', 'acceptance' => 'Invoker arms process-start metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker supervised start activation invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful activation, idempotent retry, invalid process guard hash rejection, missing enablement metadata and duplicate activation rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker supervised start activation status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next guarded process start executor without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_supervised_start_activation_gate_invoker_arms_process_start_without_starting_codex',
                'codex_real_invoker_supervised_start_activation_gate_invoker_requires_executor_enablement_metadata',
                'codex_real_invoker_supervised_start_activation_gate_invoker_requires_process_start_guard_hash',
                'codex_real_invoker_supervised_start_activation_gate_invoker_is_idempotent_by_activation_id',
                'codex_real_invoker_supervised_start_activation_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_supervised_start_activation_gate_invoker',
                'dedicated_codex_real_invoker_supervised_start_activation_gate_invoker_feature_tests',
                'generic_codex_real_invoker_supervised_start_activation_gate_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_supervised_start_activation_gate_call_allowed_by_future_invoker' => true,
                'process_start_armed_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_supervised_start_activation_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_arm_process_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_supervised_start_activation_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker supervised start activation gate implementation packet is ready; it scopes start-armed metadata and still stops before guarded process start.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_hash');
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            && method_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class, 'prepareGuardedStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker::class, 'prepareCodexRealInvokerGuardedProcessStart');
        $activationReady = class_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class)
            && method_exists(AgentCodexRealInvokerSupervisedStartActivationGate::class, 'prepareActivation');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'guarded_process_start_executor_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_guarded_process_start_executor_contract_ready',
            'guarded_process_start_executor_contract_hash_present' => $contractHash !== '',
            'supervised_start_activation_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_supervised_start_activation_gate_status') === 'one_shot_tick_codex_real_invoker_supervised_start_activation_gate_service_ready',
            'generic_guarded_process_start_executor_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_guarded_process_start_executor_contract_status') === 'codex_real_invoker_guarded_process_start_executor_contract_template_ready',
            'codex_real_invoker_guarded_process_start_executor_ready' => $guardedReady,
            'codex_real_invoker_guarded_process_start_executor_invoker_ready' => $invokerReady,
            'codex_real_invoker_supervised_start_activation_gate_ready' => $activationReady,
            'canonical_guarded_process_start_executor_method_ready' => data_get($contract, 'release_boundary.canonical_guarded_process_start_executor_method') === 'prepareGuardedStart',
            'guarded_start_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_guarded_start') === false,
            'guarded_start_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_guarded_start') === false,
            'guarded_start_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_guarded_start') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-GUARDED-PROCESS-START-EXECUTOR-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_guarded_process_start_executor_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_guarded_process_start_executor_invoker',
                'delegate_to_agent_codex_real_invoker_guarded_process_start_executor',
                'require_codex_real_invoker_supervised_start_activation_metadata',
                'require_operator_guarded_start_receipt_hash',
                'require_dry_run_rehearsal_hash',
                'preserve_actual_process_start_disabled_until_final_process_start_authorization',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_guarded_process_start_executor_call_allowed_here' => false,
                'guarded_process_start_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_does_not_prepare_guarded_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor preflight is blocked until activation, guarded executor, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_guarded_process_start_executor_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-GUARDED-PROCESS-START-EXECUTOR-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_guarded_process_start_executor_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_guarded_process_start_executor_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker guarded process start invoker', 'type' => 'service', 'acceptance' => 'Invoker validates guarded start input and delegates to AgentCodexRealInvokerGuardedProcessStartExecutor.'],
                ['id' => 'T2', 'title' => 'Preserve final start boundary after guarded start', 'type' => 'service_logic', 'acceptance' => 'Invoker prepares guarded start metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker guarded process start invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful guarded start, idempotent retry, invalid rehearsal hash rejection, missing activation metadata and duplicate guarded start rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker guarded process start status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next final authorization slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_guarded_process_start_executor_invoker_prepares_disabled_guarded_start_without_starting_codex',
                'codex_real_invoker_guarded_process_start_executor_invoker_requires_supervised_activation_metadata',
                'codex_real_invoker_guarded_process_start_executor_invoker_requires_dry_run_rehearsal_hash',
                'codex_real_invoker_guarded_process_start_executor_invoker_is_idempotent_by_guarded_start_id',
                'codex_real_invoker_guarded_process_start_executor_invoker_never_starts_codex_or_spends_tokens',
            ],
            'required_gates' => [
                'php_lint_codex_real_invoker_guarded_process_start_executor_invoker',
                'dedicated_codex_real_invoker_guarded_process_start_executor_invoker_feature_tests',
                'generic_codex_real_invoker_guarded_process_start_executor_feature_tests',
                'focused_self_construction_command_tests',
                'architecture_validate',
                'docs_health',
                'git_diff_check',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_guarded_process_start_executor_call_allowed_by_future_invoker' => true,
                'guarded_process_start_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_guarded_process_start_executor_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_prepare_guarded_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker guarded process start executor implementation packet is ready; it scopes disabled guarded start metadata and still stops before final process start authorization.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateContract(array $options = []): array
    {
        $guardedStatusPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerGuardedProcessStartExecutorStatus($options);
        $guardedStatus = (array) data_get($guardedStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status', []);
        $authorizationPayload = $this->parent->agentCodexRealInvokerFinalProcessStartAuthorizationGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-FINAL-PROCESS-START-AUTHORIZATION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_guarded_process_start_executor_status' => data_get($guardedStatus, 'status'),
            'source_codex_real_invoker_guarded_process_start_executor_status_hash' => data_get($guardedStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_guarded_process_start_executor_status_hash'),
            'source_codex_real_invoker_final_process_start_authorization_gate_contract_status' => data_get($authorizationPayload, 'status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_contract_hash' => data_get($authorizationPayload, 'codex_real_invoker_final_process_start_authorization_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_final_process_start_authorization_gate' => AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class,
                'canonical_final_process_start_authorization_gate_method' => 'authorizeFinalStart',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerFinalProcessStart',
                'authorization_effect' => 'record_final_start_authorization_without_starting_process',
                'guarded_process_start_required_before_final_authorization' => true,
                'final_process_start_authorized_by_gate' => true,
                'actual_process_start_allowed_by_gate' => false,
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'idempotency_key' => 'real_invoker_final_process_start_authorization_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'operator_final_start_receipt_hash',
                'final_start_signature_hash',
                'final_start_policy_hash',
                'final_start_window_hash',
                'final_start_replay_guard_hash',
                'final_start_kill_switch_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_final_process_start_authorization_metadata_on_agent_run',
                'append_codex_real_invoker_final_process_start_authorization_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_final_process_start_authorization_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_allowed' => false,
            'final_process_start_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_hash' => ReadinessHash::stable($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_does_not_authorize_final_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker final process start authorization contract is ready; it records authorization semantics but still cannot start Codex.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_hash');
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class, 'authorizeFinalStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerFinalProcessStart');
        $guardedReady = class_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class)
            && method_exists(AgentCodexRealInvokerGuardedProcessStartExecutor::class, 'prepareGuardedStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'final_process_start_authorization_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_contract_ready',
            'final_process_start_authorization_gate_contract_hash_present' => $contractHash !== '',
            'guarded_process_start_executor_status_ready' => data_get($contract, 'source_codex_real_invoker_guarded_process_start_executor_status') === 'one_shot_tick_codex_real_invoker_guarded_process_start_executor_service_ready',
            'generic_final_process_start_authorization_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_final_process_start_authorization_gate_contract_status') === 'codex_real_invoker_final_process_start_authorization_gate_contract_template_ready',
            'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
            'codex_real_invoker_final_process_start_authorization_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_guarded_process_start_executor_ready' => $guardedReady,
            'canonical_final_process_start_authorization_gate_method_ready' => data_get($contract, 'release_boundary.canonical_final_process_start_authorization_gate_method') === 'authorizeFinalStart',
            'authorization_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_gate') === false,
            'authorization_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_gate') === false,
            'authorization_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_gate') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-FINAL-PROCESS-START-AUTHORIZATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_final_process_start_authorization_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_final_process_start_authorization_gate_invoker',
                'delegate_to_agent_codex_real_invoker_final_process_start_authorization_gate',
                'require_codex_real_invoker_guarded_process_start_metadata',
                'require_operator_final_start_receipt_hash',
                'require_final_start_signature_hash',
                'preserve_actual_process_start_disabled_until_rehearsal_executor',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_final_process_start_authorization_gate_call_allowed_here' => false,
                'final_process_start_authorization_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_allowed' => false,
            'final_process_start_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_authorize_final_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker final process start authorization preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker final process start authorization preflight is blocked until guarded start, authorization, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGatePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-FINAL-PROCESS-START-AUTHORIZATION-GATE-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_final_process_start_authorization_gate_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_final_process_start_authorization_gate_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker final process start authorization invoker', 'type' => 'service', 'acceptance' => 'Invoker validates final authorization input and delegates to AgentCodexRealInvokerFinalProcessStartAuthorizationGate.'],
                ['id' => 'T2', 'title' => 'Preserve actual start boundary after final authorization', 'type' => 'service_logic', 'acceptance' => 'Invoker records final authorization metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker final authorization invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful final authorization, idempotent retry, invalid final signature hash rejection, missing guarded metadata and duplicate authorization rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker final authorization status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next rehearsal slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_final_process_start_authorization_gate_invoker_records_authorization_without_starting_codex',
                'codex_real_invoker_final_process_start_authorization_gate_invoker_requires_guarded_start_metadata',
                'codex_real_invoker_final_process_start_authorization_gate_invoker_requires_final_start_signature_hash',
                'codex_real_invoker_final_process_start_authorization_gate_invoker_is_idempotent_by_authorization_id',
                'codex_real_invoker_final_process_start_authorization_gate_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_final_process_start_authorization_gate_call_allowed_by_future_invoker' => true,
                'final_process_start_authorization_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_final_process_start_authorization_gate_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_allowed' => false,
            'final_process_start_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_authorize_final_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker final process start authorization implementation packet is ready; it scopes authorization metadata and still stops before actual process start rehearsal.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class, 'authorizeFinalStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerFinalProcessStart');

        $authorizationRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_final_process_start_authorization->real_invoker_final_process_start_authorization_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $authorizationReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerFinalProcessStartAuthorizationGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'authorizeCodexRealInvokerFinalProcessStart',
            'generic_final_process_start_authorization_gate_service' => AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class,
            'generic_final_process_start_authorization_gate_service_ready' => $authorizationReady,
            'generic_final_process_start_authorization_gate_canonical_method' => 'authorizeFinalStart',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_final_process_start_authorization_prepared_run_count' => $authorizationRunsQuery === null ? null : (clone $authorizationRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_final_process_start_authorization_when_called_with_guarded_start_input' => true,
                'final_process_start_authorization_is_not_actual_process_invocation' => true,
                'final_process_start_authorization_keeps_external_process_stopped' => true,
                'codex_real_invoker_actual_process_start_rehearsal_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_actual_process_start_rehearsal_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_final_process_start_authorization_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_final_process_start_authorization_gate_allowed' => false,
            'final_process_start_authorized' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status_does_not_call_final_authorization_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker final process start authorization service is ready and inspectable; status remains read-only and actual process start rehearsal is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker final process start authorization service is blocked until invoker, generic authorization gate and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_hash');
        $rehearsalReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class)
            && method_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class, 'rehearseActualStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker::class, 'rehearseCodexRealInvokerActualProcessStart');
        $authorizationReady = class_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerFinalProcessStartAuthorizationGate::class, 'authorizeFinalStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'actual_process_start_rehearsal_executor_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_contract_ready',
            'actual_process_start_rehearsal_executor_contract_hash_present' => $contractHash !== '',
            'final_process_start_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_final_process_start_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_final_process_start_authorization_gate_service_ready',
            'generic_actual_process_start_rehearsal_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_actual_process_start_rehearsal_executor_contract_status') === 'codex_real_invoker_actual_process_start_rehearsal_executor_contract_template_ready',
            'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalReady,
            'codex_real_invoker_actual_process_start_rehearsal_executor_invoker_ready' => $invokerReady,
            'codex_real_invoker_final_process_start_authorization_gate_ready' => $authorizationReady,
            'canonical_actual_process_start_rehearsal_executor_method_ready' => data_get($contract, 'release_boundary.canonical_actual_process_start_rehearsal_executor_method') === 'rehearseActualStart',
            'rehearsal_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_rehearsal') === false,
            'rehearsal_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_rehearsal') === false,
            'rehearsal_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_rehearsal') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-ACTUAL-PROCESS-START-REHEARSAL-EXECUTOR-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_actual_process_start_rehearsal_executor_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_actual_process_start_rehearsal_executor_invoker',
                'delegate_to_agent_codex_real_invoker_actual_process_start_rehearsal_executor',
                'require_codex_real_invoker_final_process_start_authorization_metadata',
                'require_process_start_rehearsal_hash',
                'require_command_environment_cwd_supervisor_and_liveness_rehearsal_hashes',
                'preserve_actual_process_start_disabled_until_process_start_envelope',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_actual_process_start_rehearsal_executor_call_allowed_here' => false,
                'actual_process_start_rehearsal_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_actual_process_start_rehearsal_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_allowed' => false,
            'process_start_rehearsed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_rehearse_actual_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal preflight is blocked until final authorization, rehearsal, storage and no-runtime prerequisites are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorPreflight($options);
        $preflight = (array) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight', []);
        $preflightHash = (string) data_get($preflightPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash');

        $packet = [
            'status' => 'ready_for_scoped_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_invoker_implementation',
            'packet_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-ACTUAL-PROCESS-START-REHEARSAL-EXECUTOR-IMPLEMENTATION-PACKET-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_status' => data_get($preflight, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_preflight_hash' => $preflightHash,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/ControlPlane/AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionAgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvokerTest.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            ],
            'tasks' => [
                ['id' => 'T1', 'title' => 'Expose one-shot scheduler Codex real invoker actual process start rehearsal invoker', 'type' => 'service', 'acceptance' => 'Invoker validates rehearsal input and delegates to AgentCodexRealInvokerActualProcessStartRehearsalExecutor.'],
                ['id' => 'T2', 'title' => 'Preserve actual start boundary after rehearsal', 'type' => 'service_logic', 'acceptance' => 'Invoker records rehearsal metadata while actual process start, token spend and self-programming remain false.'],
                ['id' => 'T3', 'title' => 'Add Codex real invoker actual process start rehearsal invoker tests', 'type' => 'test', 'acceptance' => 'Tests cover successful rehearsal, idempotent retry, invalid command hash rejection, missing final authorization metadata and duplicate rehearsal rejection.'],
                ['id' => 'T4', 'title' => 'Expose Codex real invoker actual process start rehearsal status', 'type' => 'command_surface', 'acceptance' => 'Command/readiness reports invoker readiness and next process start envelope slice without calling the invoker in status mode.'],
            ],
            'task_count' => 4,
            'acceptance_criteria' => [
                'codex_real_invoker_actual_process_start_rehearsal_executor_invoker_records_rehearsal_without_starting_codex',
                'codex_real_invoker_actual_process_start_rehearsal_executor_invoker_requires_final_authorization_metadata',
                'codex_real_invoker_actual_process_start_rehearsal_executor_invoker_requires_command_resolution_hash',
                'codex_real_invoker_actual_process_start_rehearsal_executor_invoker_is_idempotent_by_rehearsal_id',
                'codex_real_invoker_actual_process_start_rehearsal_executor_invoker_never_starts_codex_or_spends_tokens',
            ],
            'implementation_policy' => [
                'implementation_allowed_by_packet' => true,
                'codex_real_invoker_actual_process_start_rehearsal_executor_call_allowed_by_future_invoker' => true,
                'actual_process_start_rehearsal_metadata_allowed_by_future_invoker' => true,
                'actual_process_start_allowed_by_packet' => false,
                'provider_external_process_start_allowed_by_packet' => false,
                'token_spend_allowed_by_packet' => false,
                'self_programming_allowed_by_packet' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_actual_process_start_rehearsal_invoker_service',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet.v1',
            'status' => (string) $packet['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_allowed' => false,
            'process_start_rehearsed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet' => $packet,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_rehearse_actual_start',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_implementation_packet_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal implementation packet is ready; it scopes rehearsal metadata and still stops before process start envelope.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $rehearsalReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class)
            && method_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class, 'rehearseActualStart');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker::class, 'rehearseCodexRealInvokerActualProcessStart');

        $rehearsalRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_actual_process_start_rehearsal->real_invoker_actual_process_start_rehearsal_id')
            : null;

        $statusReady = $runsTableReady && $ledgerTableReady && $rehearsalReady && $invokerReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'rehearseCodexRealInvokerActualProcessStart',
            'generic_actual_process_start_rehearsal_executor_service' => AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class,
            'generic_actual_process_start_rehearsal_executor_service_ready' => $rehearsalReady,
            'generic_actual_process_start_rehearsal_executor_canonical_method' => 'rehearseActualStart',
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_actual_process_start_rehearsal_prepared_run_count' => $rehearsalRunsQuery === null ? null : (clone $rehearsalRunsQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_codex_real_invoker_actual_process_start_rehearsal_when_called_with_final_authorization_input' => true,
                'actual_process_start_rehearsal_is_not_actual_process_invocation' => true,
                'actual_process_start_rehearsal_keeps_external_process_stopped' => true,
                'codex_real_invoker_process_start_envelope_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_actual_process_start_rehearsal_executor_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_actual_process_start_rehearsal_executor_allowed' => false,
            'process_start_rehearsed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_hash' => ReadinessHash::stable($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_does_not_call_actual_process_start_rehearsal_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal service is ready and inspectable; status remains read-only and process start envelope is still separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker actual process start rehearsal service is blocked until invoker, generic rehearsal executor and ledger are ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderPreflight(array $options = []): array
    {
        $contractPayload = $this->parent->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_hash');
        $envelopeReady = class_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class)
            && method_exists(AgentCodexRealInvokerProcessStartEnvelopeBuilder::class, 'buildStartEnvelope');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class, 'buildCodexRealInvokerProcessStartEnvelope');
        $rehearsalReady = class_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class)
            && method_exists(AgentCodexRealInvokerActualProcessStartRehearsalExecutor::class, 'rehearseActualStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'process_start_envelope_builder_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_ready',
            'process_start_envelope_builder_contract_hash_present' => $contractHash !== '',
            'actual_process_start_rehearsal_executor_status_ready' => data_get($contract, 'source_codex_real_invoker_actual_process_start_rehearsal_executor_status') === 'one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_service_ready',
            'generic_process_start_envelope_contract_template_ready' => data_get($contract, 'source_codex_real_invoker_process_start_envelope_builder_contract_status') === 'codex_real_invoker_process_start_envelope_builder_contract_template_ready',
            'codex_real_invoker_process_start_envelope_builder_ready' => $envelopeReady,
            'codex_real_invoker_process_start_envelope_builder_invoker_ready' => $invokerReady,
            'codex_real_invoker_actual_process_start_rehearsal_executor_ready' => $rehearsalReady,
            'canonical_process_start_envelope_builder_method_ready' => data_get($contract, 'release_boundary.canonical_process_start_envelope_builder_method') === 'buildStartEnvelope',
            'envelope_keeps_actual_process_start_disabled' => data_get($contract, 'release_boundary.actual_process_start_allowed_by_builder') === false,
            'envelope_does_not_start_external_process' => data_get($contract, 'release_boundary.external_process_started_by_builder') === false,
            'envelope_does_not_spend_tokens' => data_get($contract, 'release_boundary.token_spend_allowed_by_builder') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-START-ENVELOPE-BUILDER-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_process_start_envelope_builder_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_process_start_envelope_builder_invoker',
                'delegate_to_agent_codex_real_invoker_process_start_envelope_builder',
                'require_codex_real_invoker_actual_process_start_rehearsal_metadata',
                'require_process_start_envelope_hash',
                'require_start_command_environment_cwd_supervisor_and_liveness_hashes',
                'preserve_actual_process_start_disabled_until_start_execution_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'codex_real_invoker_process_start_envelope_builder_call_allowed_here' => false,
                'process_start_envelope_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_does_not_build_start_envelope',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope preflight is blocked until rehearsal, envelope builder, storage and no-runtime prerequisites are ready.',
        ];
    }
}
