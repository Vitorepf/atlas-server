<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\AutomaticDispatchBatch1;

use App\Models\AtlasSelfConstructionAgentRun;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorProviderStartDriver;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterExecutionGuard;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexProviderExecutionDriver;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerExecutorPlan;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterExecutionGuardGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorFreshReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExecutorPlanGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartImplementationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessSpawnEnablementGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessStartReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderExecutionContractGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProviderStartDriverGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSupervisedStartExecutorGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerReleasePreflight;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerStartExecutionGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section;

/**
 * DISPATCH BATCH 1 projection sub-section 05 of 5, sub-split from the god
 * {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch1Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling/back calls route through the injected Section
 * facade ($this->section->*), which re-dispatches to whichever sub-section
 * owns the target method or forwards to the bound mother via the facade
 * __call.
 */
final class DispatchBatch1Part05SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch1Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessSpawnEnablementGate::class, 'enablePostStartProcessSpawn');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate');
        $spawnEnablementReady = class_exists(AgentCodexProcessSpawnEnablementGate::class)
            && method_exists(AgentCodexProcessSpawnEnablementGate::class, 'enableCodexProcessSpawn');
        $supervisedGateReady = class_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSupervisedStartExecutorGate::class, 'preparePostStartSupervisedStart');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_spawn_enablement_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract_ready',
            'post_start_process_spawn_enablement_gate_contract_hash_present' => $contractHash !== '',
            'post_start_supervised_start_executor_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_supervised_start_executor_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate_service_ready',
            'generic_post_start_process_spawn_enablement_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_spawn_enablement_gate_status') === 'codex_real_invoker_post_start_process_spawn_enablement_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_spawn_enablement_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_spawn_enablement_gate_invoker_ready' => $invokerReady,
            'codex_process_spawn_enablement_gate_ready' => $spawnEnablementReady,
            'codex_real_invoker_post_start_supervised_start_executor_gate_ready' => $supervisedGateReady,
            'canonical_post_start_process_spawn_enablement_gate_method_ready' => data_get($contract, 'process_spawn_enablement.canonical_post_start_process_spawn_enablement_gate_method') === 'enablePostStartProcessSpawn',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_spawn_enablement.scheduler_invoker_method') === 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
            'contract_requires_supervised_start' => data_get($contract, 'process_spawn_enablement.post_start_supervised_start_required_before_enablement') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_spawn_enablement.post_start_evidence_acceptance_bridge_required_before_enablement') === true,
            'contract_requires_provider_start_run_supervised_start' => data_get($contract, 'process_spawn_enablement.provider_start_run_with_codex_supervised_start_required_before_enablement') === true,
            'contract_requires_operator_spawn_receipt_hash' => data_get($contract, 'process_spawn_enablement.operator_spawn_receipt_hash_required') === true,
            'contract_requires_supervised_start_contract_hash' => data_get($contract, 'process_spawn_enablement.supervised_start_contract_hash_required') === true,
            'contract_delegates_to_codex_process_spawn_enablement_gate' => data_get($contract, 'process_spawn_enablement.gate_delegates_to_codex_process_spawn_enablement_gate') === true,
            'contract_requires_final_process_spawn_executor_after_enablement' => data_get($contract, 'process_spawn_enablement.final_process_spawn_executor_required_after_enablement') === true,
            'contract_declares_enablement_is_not_process_spawn' => data_get($contract, 'process_spawn_enablement.process_spawn_enablement_is_not_process_spawn') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_spawn_enablement.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_spawn_enablement.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_spawn_enablement.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_spawn_enablement.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-SPAWN-ENABLEMENT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_spawn_enablement_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_process_spawn_enablement_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_spawn_enablement_gate',
                'require_codex_real_invoker_post_start_supervised_start_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_process_spawn_enablement_without_spawning_codex',
                'preserve_actual_process_start_disabled_until_final_process_spawn_executor_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_spawn_enablement_gate_call_allowed_here' => false,
                'post_start_process_spawn_enablement_recording_allowed_by_future_invoker' => true,
                'codex_process_spawn_enablement_gate_allowed_by_future_invoker' => true,
                'process_spawn_enablement_is_not_process_spawn' => true,
                'final_process_spawn_executor_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_spawn_enablement_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process spawn enablement gate preflight is blocked until supervised start, spawn enablement gate and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateContract(array $options = []): array
    {
        $releasePreflightStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightStatus($options);
        $releasePreflightStatus = (array) data_get($releasePreflightStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status', []);
        $signedGatePayload = $this->section->agentCodexSignedRealInvokerReleaseGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_signed_real_invoker_release_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-SIGNED-REAL-INVOKER-RELEASE-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_release_preflight_status' => data_get($releasePreflightStatus, 'status'),
            'source_codex_real_invoker_release_preflight_status_hash' => data_get($releasePreflightStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_status_hash'),
            'source_codex_signed_real_invoker_release_gate_contract_status' => data_get($signedGatePayload, 'status'),
            'source_codex_signed_real_invoker_release_gate_contract_hash' => data_get($signedGatePayload, 'codex_signed_real_invoker_release_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_signed_gate' => AgentCodexSignedRealInvokerReleaseGate::class,
                'canonical_signed_gate_method' => 'authorizeSignedRelease',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexSignedRealInvokerReleaseGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexSignedRealInvokerRelease',
                'signed_gate_effect' => 'authorize_codex_signed_real_invoker_release_metadata_without_real_process_invocation',
                'external_process_started_by_signed_gate' => false,
                'provider_started_by_signed_gate' => false,
                'adapter_execution_allowed_by_signed_gate' => false,
                'token_spend_allowed_by_signed_gate' => false,
                'required_release_preflight_status_before_signed_gate' => 'real_invoker_release_preflight_passed_pending_signed_release',
                'prepared_status_after_signed_gate' => 'signed_real_invoker_release_authorized_pending_invoker_implementation',
                'idempotency_key' => 'signed_real_invoker_release_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'signed_real_invoker_release_id',
                'operator_signed_release_receipt_hash',
                'signature_verification_report_hash',
                'real_invoker_contract_hash',
                'release_policy_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_signed_real_invoker_release_metadata_on_agent_run',
                'append_codex_signed_real_invoker_release_authorized_evidence_event',
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
            'handoff_policy' => [
                'signed_gate_is_authorization_not_invocation' => true,
                'real_invoker_implementation_boundary_requires_separate_gate' => true,
                'operator_signed_release_receipt_hash_required' => true,
                'signature_verification_report_hash_required' => true,
                'release_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_signed_real_invoker_release_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_signed_real_invoker_release_gate_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_call_signed_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_signed_real_invoker_release_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex signed real invoker release gate contract is ready; it authorizes a future boundary but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateContract(array $options = []): array
    {
        $envelopeStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderStatus($options);
        $envelopeStatus = (array) data_get($envelopeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status', []);
        $startGatePayload = $this->section->agentCodexRealInvokerStartExecutionGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_start_execution_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-START-EXECUTION-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_process_start_envelope_builder_status' => data_get($envelopeStatus, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_status_hash' => data_get($envelopeStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_status_hash'),
            'source_codex_real_invoker_start_execution_gate_contract_status' => data_get($startGatePayload, 'status'),
            'source_codex_real_invoker_start_execution_gate_contract_hash' => data_get($startGatePayload, 'codex_real_invoker_start_execution_gate_contract_template_hash'),
            'release_boundary' => [
                'canonical_start_execution_gate' => AgentCodexRealInvokerStartExecutionGate::class,
                'canonical_start_execution_gate_method' => 'authorizeStartExecution',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerStartExecutionGateInvoker::class,
                'scheduler_invoker_method' => 'authorizeCodexRealInvokerStartExecution',
                'gate_effect' => 'record_start_execution_authorization_without_starting_process',
                'process_start_envelope_required_before_gate' => true,
                'start_execution_authorized_by_gate' => true,
                'start_envelope_ready_by_gate' => true,
                'actual_process_start_allowed_by_gate' => false,
                'external_process_started_by_gate' => false,
                'provider_started_by_gate' => false,
                'adapter_execution_allowed_by_gate' => false,
                'token_spend_allowed_by_gate' => false,
                'dispatch_allowed_by_gate' => false,
                'idempotency_key' => 'real_invoker_start_execution_gate_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_executor_plan_id',
                'real_invoker_executor_fresh_release_id',
                'real_invoker_executor_enablement_id',
                'real_invoker_supervised_start_activation_id',
                'real_invoker_guarded_process_start_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'real_invoker_start_execution_gate_id',
                'process_start_envelope_hash',
                'start_command_hash',
                'start_environment_hash',
                'start_cwd_hash',
                'start_supervisor_hash',
                'start_liveness_contract_hash',
                'operator_execution_gate_receipt_hash',
                'execution_gate_policy_hash',
                'execution_window_hash',
                'preflight_snapshot_hash',
                'rollback_readiness_hash',
                'human_start_signature_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_start_execution_gate_metadata_on_agent_run',
                'append_codex_real_invoker_start_execution_gate_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'mark_packet_completed',
                'merge_work_products',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_start_execution_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_authorize_start_execution',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_start_execution_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker start execution gate contract is ready; it authorizes the gate only and still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderStartDriverGate::class, 'preparePostStartProviderStartDriver');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderStartDriverGate');
        $driverReady = class_exists(AgentDispatchExecutorProviderStartDriver::class)
            && method_exists(AgentDispatchExecutorProviderStartDriver::class, 'startProviderOnce');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $dispatchReceiptsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_receipts');
        $releaseAuthorizationsTableReady = Schema::hasTable('atlas_self_construction_agent_dispatch_authorizations');
        $sandboxBindingsTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_provider_start_driver_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract_ready',
            'post_start_provider_start_driver_gate_contract_hash_present' => $contractHash !== '',
            'post_start_dispatch_receipt_use_executor_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status') === 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_service_ready',
            'generic_post_start_provider_start_driver_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_start_driver_gate_status') === 'codex_real_invoker_post_start_provider_start_driver_gate_contract_template_ready',
            'codex_real_invoker_post_start_provider_start_driver_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_provider_start_driver_gate_invoker_ready' => $invokerReady,
            'dispatch_executor_provider_start_driver_ready' => $driverReady,
            'canonical_post_start_provider_start_driver_gate_method_ready' => data_get($contract, 'provider_start_driver_boundary.canonical_post_start_provider_start_driver_gate_method') === 'preparePostStartProviderStartDriver',
            'contract_requires_dispatch_receipt_use' => data_get($contract, 'provider_start_driver_boundary.post_start_dispatch_receipt_use_required_before_provider_start_driver') === true,
            'contract_requires_sandbox_binding' => data_get($contract, 'provider_start_driver_boundary.sandbox_binding_required_before_provider_start_driver') === true,
            'contract_allows_pre_start_guarded_run_projection' => data_get($contract, 'provider_start_driver_boundary.pre_start_guarded_run_may_be_created_by_driver') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'provider_start_driver_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_driver_provider_start_disabled' => data_get($contract, 'provider_start_driver_boundary.driver_provider_started_by_contract') === false,
            'contract_keeps_adapter_invocation_disabled' => data_get($contract, 'provider_start_driver_boundary.adapter_invocation_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'provider_start_driver_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'provider_start_driver_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'dispatch_receipts_table_ready' => $dispatchReceiptsTableReady,
            'dispatch_executor_release_authorizations_table_ready' => $releaseAuthorizationsTableReady,
            'sandbox_bindings_table_ready' => $sandboxBindingsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROVIDER-START-DRIVER-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_provider_start_driver_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_provider_start_driver_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_provider_start_driver_gate',
                'require_codex_real_invoker_post_start_dispatch_receipt_use_metadata',
                'require_active_sandbox_binding_and_release_authorization',
                'record_pre_start_guarded_run_without_spawning_codex',
                'preserve_adapter_invocation_disabled_until_adapter_invocation_boundary_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_provider_start_driver_gate_call_allowed_here' => false,
                'post_start_provider_start_driver_metadata_allowed_by_future_invoker' => true,
                'pre_start_guarded_run_write_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_enable_adapter_invocation',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider start driver gate preflight is blocked until receipt-use, sandbox, driver and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class, 'preparePostStartExecutorPlan');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker::class, 'prepareCodexRealInvokerPostStartExecutorPlanGate');
        $executorPlanReady = class_exists(AgentCodexRealInvokerExecutorPlan::class)
            && method_exists(AgentCodexRealInvokerExecutorPlan::class, 'prepareExecutorPlan');
        $implementationBoundaryReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_executor_plan_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_contract_ready',
            'post_start_executor_plan_gate_contract_hash_present' => $contractHash !== '',
            'post_start_implementation_boundary_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_implementation_boundary_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_service_ready',
            'generic_post_start_executor_plan_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_plan_gate_status') === 'codex_real_invoker_post_start_executor_plan_gate_contract_template_ready',
            'codex_real_invoker_post_start_executor_plan_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_executor_plan_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_plan_ready' => $executorPlanReady,
            'codex_real_invoker_post_start_implementation_boundary_gate_ready' => $implementationBoundaryReady,
            'canonical_post_start_executor_plan_gate_method_ready' => data_get($contract, 'executor_plan.canonical_post_start_executor_plan_gate_method') === 'preparePostStartExecutorPlan',
            'scheduler_invoker_method_ready' => data_get($contract, 'executor_plan.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartExecutorPlanGate',
            'contract_requires_post_start_implementation_boundary' => data_get($contract, 'executor_plan.post_start_implementation_boundary_required_before_plan') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'executor_plan.post_start_evidence_acceptance_bridge_required_before_plan') === true,
            'contract_delegates_to_codex_real_invoker_executor_plan' => data_get($contract, 'executor_plan.gate_delegates_to_codex_real_invoker_executor_plan') === true,
            'contract_declares_executor_plan_is_not_executor_enablement' => data_get($contract, 'executor_plan.executor_plan_is_not_executor_enablement') === true,
            'contract_requires_executor_fresh_release_after_plan' => data_get($contract, 'executor_plan.executor_fresh_release_required_after_plan') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'executor_plan.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_executor_disabled' => data_get($contract, 'executor_plan.executor_enabled_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'executor_plan.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'executor_plan.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'executor_plan.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-PLAN-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_executor_plan_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_executor_plan_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_executor_plan_gate',
                'require_post_start_implementation_boundary_metadata',
                'require_operator_executor_plan_receipt_hash',
                'preserve_executor_fresh_release_after_plan',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_executor_plan_gate_call_allowed_by_future_invoker' => true,
                'executor_fresh_release_required_after_plan' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate preflight is ready; executor fresh release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor plan gate preflight is blocked until boundary, executor plan and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerExecutorFreshReleaseGate::class, 'authorizeFreshRelease');
        $executorPlanReady = class_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorPlanGate::class, 'preparePostStartExecutorPlan');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_executor_fresh_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract_ready',
            'post_start_executor_fresh_release_gate_contract_hash_present' => $contractHash !== '',
            'post_start_executor_plan_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_plan_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_executor_plan_gate_service_ready',
            'generic_post_start_executor_fresh_release_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_fresh_release_gate_status') === 'codex_real_invoker_post_start_executor_fresh_release_gate_contract_template_ready',
            'codex_real_invoker_post_start_executor_fresh_release_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_executor_fresh_release_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'codex_real_invoker_post_start_executor_plan_gate_ready' => $executorPlanReady,
            'canonical_post_start_executor_fresh_release_gate_method_ready' => data_get($contract, 'fresh_release.canonical_post_start_executor_fresh_release_gate_method') === 'authorizePostStartExecutorFreshRelease',
            'scheduler_invoker_method_ready' => data_get($contract, 'fresh_release.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate',
            'contract_requires_post_start_executor_plan' => data_get($contract, 'fresh_release.post_start_executor_plan_required_before_fresh_release') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'fresh_release.post_start_evidence_acceptance_bridge_required_before_fresh_release') === true,
            'contract_delegates_to_codex_real_invoker_executor_fresh_release_gate' => data_get($contract, 'fresh_release.gate_delegates_to_codex_real_invoker_executor_fresh_release_gate') === true,
            'contract_declares_fresh_release_is_not_executor_enablement' => data_get($contract, 'fresh_release.fresh_release_is_not_executor_enablement') === true,
            'contract_requires_executor_enablement_after_fresh_release' => data_get($contract, 'fresh_release.executor_enablement_required_after_fresh_release') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'fresh_release.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_executor_disabled' => data_get($contract, 'fresh_release.executor_enabled_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'fresh_release.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'fresh_release.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'fresh_release.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-FRESH-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_executor_fresh_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_executor_fresh_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_executor_fresh_release_gate',
                'require_post_start_executor_plan_metadata',
                'require_operator_fresh_release_receipt_hash',
                'preserve_executor_enablement_after_fresh_release',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_executor_fresh_release_gate_call_allowed_by_future_invoker' => true,
                'executor_enablement_required_after_fresh_release' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_enable_executor',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate preflight is ready; executor enablement remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor fresh release gate preflight is blocked until executor plan, fresh release and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorEnablementGate::class, 'enablePostStartExecutor');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker::class, 'enableCodexRealInvokerPostStartExecutorGate');
        $enablementReady = class_exists(AgentCodexRealInvokerExecutorEnablementGate::class)
            && method_exists(AgentCodexRealInvokerExecutorEnablementGate::class, 'enableExecutor');
        $freshReleaseReady = class_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExecutorFreshReleaseGate::class, 'authorizePostStartExecutorFreshRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_executor_enablement_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_contract_ready',
            'post_start_executor_enablement_gate_contract_hash_present' => $contractHash !== '',
            'post_start_executor_fresh_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_fresh_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate_service_ready',
            'generic_post_start_executor_enablement_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_executor_enablement_gate_status') === 'codex_real_invoker_post_start_executor_enablement_gate_contract_template_ready',
            'codex_real_invoker_post_start_executor_enablement_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_executor_enablement_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_executor_enablement_gate_ready' => $enablementReady,
            'codex_real_invoker_post_start_executor_fresh_release_gate_ready' => $freshReleaseReady,
            'canonical_post_start_executor_enablement_gate_method_ready' => data_get($contract, 'enablement.canonical_post_start_executor_enablement_gate_method') === 'enablePostStartExecutor',
            'scheduler_invoker_method_ready' => data_get($contract, 'enablement.scheduler_invoker_method') === 'enableCodexRealInvokerPostStartExecutorGate',
            'contract_requires_post_start_executor_fresh_release' => data_get($contract, 'enablement.post_start_executor_fresh_release_required_before_enablement') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'enablement.post_start_evidence_acceptance_bridge_required_before_enablement') === true,
            'contract_delegates_to_codex_real_invoker_executor_enablement_gate' => data_get($contract, 'enablement.gate_delegates_to_codex_real_invoker_executor_enablement_gate') === true,
            'contract_declares_enablement_is_not_process_start' => data_get($contract, 'enablement.enablement_is_not_process_start') === true,
            'contract_requires_supervised_start_after_enablement' => data_get($contract, 'enablement.supervised_start_required_after_enablement') === true,
            'contract_allows_executor_enabled_by_future_invoker' => data_get($contract, 'enablement.executor_enabled_by_contract') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'enablement.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'enablement.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'enablement.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'enablement.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXECUTOR-ENABLEMENT-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_executor_enablement_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_executor_enablement_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_executor_enablement_gate',
                'require_post_start_executor_fresh_release_metadata',
                'require_operator_enablement_receipt_hash',
                'preserve_supervised_start_after_enablement',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_executor_enablement_gate_call_allowed_by_future_invoker' => true,
                'executor_enabled_after_future_invoker' => true,
                'supervised_start_required_after_enablement' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'executor_enabled_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate preflight is ready; supervised start remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start executor enablement gate preflight is blocked until fresh release, enablement and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightContract(array $options = []): array
    {
        $dryRunStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunStatus($options);
        $dryRunStatus = (array) data_get($dryRunStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status', []);
        $releasePreflightPayload = $this->section->agentCodexRealInvokerReleasePreflightContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_release_preflight_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-RELEASE-PREFLIGHT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_external_process_invoker_dry_run_status' => data_get($dryRunStatus, 'status'),
            'source_codex_external_process_invoker_dry_run_status_hash' => data_get($dryRunStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_status_hash'),
            'source_codex_real_invoker_release_preflight_contract_status' => data_get($releasePreflightPayload, 'status'),
            'source_codex_real_invoker_release_preflight_contract_hash' => data_get($releasePreflightPayload, 'codex_real_invoker_release_preflight_contract_template_hash'),
            'release_boundary' => [
                'canonical_release_preflight' => AgentCodexRealInvokerReleasePreflight::class,
                'canonical_release_preflight_method' => 'recordPreflight',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerReleasePreflightInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexRealInvokerReleasePreflight',
                'release_preflight_effect' => 'record_codex_real_invoker_release_preflight_metadata_without_real_process_invocation',
                'external_process_started_by_release_preflight' => false,
                'provider_started_by_release_preflight' => false,
                'adapter_execution_allowed_by_release_preflight' => false,
                'token_spend_allowed_by_release_preflight' => false,
                'required_dry_run_status_before_release_preflight' => 'dry_run_ready_pending_real_invoker_release',
                'prepared_status_after_release_preflight' => 'real_invoker_release_preflight_passed_pending_signed_release',
                'idempotency_key' => 'real_invoker_release_preflight_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'process_start_release_id',
                'supervised_start_id',
                'spawn_enablement_id',
                'spawn_executor_id',
                'runtime_driver_id',
                'invocation_authorization_id',
                'dry_run_id',
                'real_invoker_release_preflight_id',
                'operator_release_preflight_receipt_hash',
                'real_invoker_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'rollback_plan_hash',
                'max_runtime_policy_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_release_preflight_metadata_on_agent_run',
                'append_codex_real_invoker_release_preflight_passed_evidence_event',
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
            'handoff_policy' => [
                'release_preflight_is_release_gate_not_invocation' => true,
                'signed_real_invoker_release_requires_separate_gate' => true,
                'operator_release_preflight_receipt_hash_required' => true,
                'real_invoker_contract_hash_required' => true,
                'rollback_plan_hash_required' => true,
                'max_runtime_policy_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_release_preflight_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_real_invoker_release_preflight_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_call_release_preflight',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_release_preflight_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker release preflight contract is ready; it can only prepare the signed-release preflight boundary, not start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateContract(array $options = []): array
    {
        $boundaryStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateStatus($options);
        $boundaryStatus = (array) data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status', []);
        $guardPayload = $this->section->agentCodexRealInvokerPostStartAdapterExecutionGuardGateContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-EXECUTION-GUARD-GATE-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status' => data_get($boundaryStatus, 'status'),
            'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_hash' => data_get($boundaryStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status_hash'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_status' => data_get($guardPayload, 'status'),
            'source_codex_real_invoker_post_start_adapter_execution_guard_gate_hash' => data_get($guardPayload, 'codex_real_invoker_post_start_adapter_execution_guard_gate_contract_template_hash'),
            'adapter_execution_guard' => [
                'canonical_post_start_adapter_execution_guard_gate' => AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class,
                'canonical_post_start_adapter_execution_guard_gate_method' => 'blockPostStartAdapterExecution',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker::class,
                'scheduler_invoker_method' => 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate',
                'provider_adapter_execution_guard' => AgentProviderAdapterExecutionGuard::class,
                'provider_adapter_execution_guard_method' => 'blockUntilProviderSpecificContract',
                'adapter_execution_guard_effect' => 'record_provider_adapter_execution_guard_block_without_calling_codex',
                'post_start_adapter_invocation_boundary_required_before_guard' => true,
                'post_start_evidence_acceptance_bridge_required_before_guard' => true,
                'provider_start_run_with_adapter_invocation_required_before_guard' => true,
                'provider_adapter_registry_required' => true,
                'provider_specific_execution_contract_required_before_any_later_execution' => true,
                'guard_delegates_to_provider_adapter_execution_guard' => true,
                'guard_records_blocking_metadata_on_provider_start_run' => true,
                'guard_records_bridge_metadata_on_observed_run' => true,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_invocation_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'execution_guard_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'adapter_execution_guard_gate_id',
                'execution_guard_id',
                'adapter_invocation_boundary_gate_id',
                'adapter_invocation_id',
                'provider_start_driver_gate_id',
                'provider_start_attempt_id',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'record_provider_adapter_execution_guard_metadata_on_provider_start_run',
                'append_provider_adapter_execution_guard_evidence_event',
                'record_codex_real_invoker_post_start_adapter_execution_guard_metadata_on_observed_run',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_execution',
                'create_provider_specific_execution_contract',
                'spend_provider_tokens',
                'mark_observed_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_adapter_execution_guard_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter execution guard gate contract is ready; it records the execution block without calling Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateStatus(array $options = []): array
    {
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $sandboxBindingTableReady = Schema::hasTable('atlas_self_construction_agent_sandbox_bindings');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class, 'prepareCodexRealInvokerPostStartProviderExecutionContractGate');
        $driverReady = class_exists(AgentCodexProviderExecutionDriver::class)
            && method_exists(AgentCodexProviderExecutionDriver::class, 'prepareCodexExecution');
        $guardGateReady = class_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class, 'blockPostStartAdapterExecution');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');

        $observedProviderExecutionRunsQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->whereNotNull('metadata->codex_real_invoker_post_start_provider_execution_contract->codex_execution_id')
            : null;
        $providerRunsWithCodexExecutionQuery = $runsTableReady
            ? AtlasSelfConstructionAgentRun::query()
                ->where('run_key', 'like', 'provider-start:%')
                ->where('status', 'adapter_invocation_prepared')
                ->whereNotNull('metadata->codex_provider_execution->codex_execution_id')
            : null;

        $statusReady = $runsTableReady
            && $sandboxBindingTableReady
            && $ledgerTableReady
            && $gateReady
            && $invokerReady
            && $driverReady
            && $guardGateReady
            && $registryReady;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_service_ready' : 'blocked',
            'provider' => 'codex',
            'adapter' => 'codex',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
            'generic_post_start_provider_execution_contract_gate_service' => AgentCodexRealInvokerPostStartProviderExecutionContractGate::class,
            'generic_post_start_provider_execution_contract_gate_service_ready' => $gateReady,
            'generic_post_start_provider_execution_contract_gate_canonical_method' => 'preparePostStartProviderExecutionContract',
            'codex_provider_execution_driver_service' => AgentCodexProviderExecutionDriver::class,
            'codex_provider_execution_driver_ready' => $driverReady,
            'post_start_adapter_execution_guard_gate_service' => AgentCodexRealInvokerPostStartAdapterExecutionGuardGate::class,
            'post_start_adapter_execution_guard_gate_ready' => $guardGateReady,
            'provider_adapter_registry_service' => AgentProviderAdapterRegistry::class,
            'provider_adapter_registry_ready' => $registryReady,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_sandbox_bindings_table_ready' => $sandboxBindingTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'codex_real_invoker_post_start_provider_execution_contract_recorded_run_count' => $observedProviderExecutionRunsQuery === null ? null : (clone $observedProviderExecutionRunsQuery)->count(),
            'provider_start_runs_with_codex_provider_execution_count' => $providerRunsWithCodexExecutionQuery === null ? null : (clone $providerRunsWithCodexExecutionQuery)->count(),
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_record_provider_execution_contract_when_called_with_signed_input' => true,
                'provider_execution_contract_is_not_process_start' => true,
                'process_start_release_required_before_process_start' => true,
                'atlas_process_spawn_allowed_here' => false,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract'
                : 'repair_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_provider_execution_contract_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_call_provider_execution_contract_gate',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate service is ready and inspectable; process start release remains separate.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start provider execution contract gate service is blocked until invoker, generic gate, driver, guard, registry and storage are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessStartReleaseGate::class, 'authorizePostStartProcessStartRelease');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate');
        $codexReleaseGateReady = class_exists(AgentCodexProcessStartReleaseGate::class)
            && method_exists(AgentCodexProcessStartReleaseGate::class, 'authorizeCodexProcessStart');
        $providerExecutionGateReady = class_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProviderExecutionContractGate::class, 'preparePostStartProviderExecutionContract');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_start_release_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_contract_ready',
            'post_start_process_start_release_gate_contract_hash_present' => $contractHash !== '',
            'post_start_provider_execution_contract_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_execution_contract_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate_service_ready',
            'generic_post_start_process_start_release_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_start_release_gate_status') === 'codex_real_invoker_post_start_process_start_release_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_start_release_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_start_release_gate_invoker_ready' => $invokerReady,
            'codex_process_start_release_gate_ready' => $codexReleaseGateReady,
            'codex_real_invoker_post_start_provider_execution_contract_gate_ready' => $providerExecutionGateReady,
            'canonical_post_start_process_start_release_gate_method_ready' => data_get($contract, 'process_start_release.canonical_post_start_process_start_release_gate_method') === 'authorizePostStartProcessStartRelease',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_start_release.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
            'contract_requires_provider_execution_contract' => data_get($contract, 'process_start_release.post_start_provider_execution_contract_required_before_release') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_start_release.post_start_evidence_acceptance_bridge_required_before_release') === true,
            'contract_delegates_to_codex_process_start_release_gate' => data_get($contract, 'process_start_release.gate_delegates_to_codex_process_start_release_gate') === true,
            'contract_requires_supervised_start_executor_after_release' => data_get($contract, 'process_start_release.supervised_start_executor_required_after_release') === true,
            'contract_declares_release_is_not_process_start' => data_get($contract, 'process_start_release.release_authorization_is_not_process_start') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_start_release.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_start_release.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_start_release.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_start_release.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-START-RELEASE-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_start_release_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_process_start_release_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_start_release_gate',
                'require_codex_real_invoker_post_start_provider_execution_contract_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_process_start_release_authorization_without_starting_codex',
                'preserve_actual_process_start_disabled_until_supervised_start_executor_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_start_release_gate_call_allowed_here' => false,
                'process_start_release_authorization_allowed_by_future_invoker' => true,
                'codex_process_start_release_gate_allowed_by_future_invoker' => true,
                'release_authorization_is_not_process_start' => true,
                'supervised_start_executor_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_start_release_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process start release gate preflight is blocked until provider execution, release gate and storage prerequisites are ready.',
        ];
    }
}
