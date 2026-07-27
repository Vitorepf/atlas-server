<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\AutomaticDispatchBatch2;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorAdapterInvocationBoundary;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentDispatchExecutorReceiptUseWriter;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexExternalProcessInvokerDryRun;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerImplementationBoundary;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartEvidenceReceiptWriter;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartExternalProcessRuntimeGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartImplementationBoundaryGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate;
use App\Services\Ai\SelfConstruction\Support\AgentCodexRealInvokerProcessStartEnvelopeBuilder;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section;

/**
 * AUTOMATIC DISPATCH BATCH2 projection sub-section 01 of 05, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentAutomaticDispatchBatch2Section}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the
 * only rewrite is that sibling pipeline calls and mother back-calls route
 * through the injected Section facade (`$this->section->*`), whose __call
 * re-dispatches to the owning sub-section or forwards to the mother verbatim.
 *
 * Stage range: agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight
 *           .. agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus
 */
final class DispatchBatch2Part01SubSection
{
    public function __construct(
        private readonly ReadinessProjectionAgentAutomaticDispatchBatch2Section $section,
    ) {}

    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker::class, 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate');
        $authorizationGateReady = class_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexExternalProcessInvocationAuthorizationGate::class, 'authorizeExternalProcessInvocation');
        $externalRuntimeGateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessRuntimeGate::class, 'preparePostStartExternalRuntime');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_process_invocation_authorization_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract_ready',
            'post_start_process_invocation_authorization_gate_contract_hash_present' => $contractHash !== '',
            'post_start_external_process_runtime_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_runtime_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate_service_ready',
            'generic_post_start_process_invocation_authorization_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status') === 'codex_real_invoker_post_start_process_invocation_authorization_gate_contract_template_ready',
            'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_invoker_ready' => $invokerReady,
            'codex_external_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'codex_real_invoker_post_start_external_process_runtime_gate_ready' => $externalRuntimeGateReady,
            'canonical_post_start_process_invocation_authorization_gate_method_ready' => data_get($contract, 'process_invocation_authorization.canonical_post_start_process_invocation_authorization_gate_method') === 'authorizePostStartProcessInvocation',
            'scheduler_invoker_method_ready' => data_get($contract, 'process_invocation_authorization.scheduler_invoker_method') === 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
            'contract_requires_external_runtime' => data_get($contract, 'process_invocation_authorization.post_start_external_process_runtime_required_before_authorization') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'process_invocation_authorization.post_start_evidence_acceptance_bridge_required_before_authorization') === true,
            'contract_delegates_to_codex_external_process_invocation_authorization_gate' => data_get($contract, 'process_invocation_authorization.gate_delegates_to_codex_external_process_invocation_authorization_gate') === true,
            'contract_declares_authorization_is_not_process_invocation' => data_get($contract, 'process_invocation_authorization.process_invocation_authorization_is_not_process_invocation') === true,
            'contract_requires_external_process_invoker_dry_run_after_authorization' => data_get($contract, 'process_invocation_authorization.external_process_invoker_dry_run_required_after_authorization') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'process_invocation_authorization.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'process_invocation_authorization.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'process_invocation_authorization.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'process_invocation_authorization.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-PROCESS-INVOCATION-AUTHORIZATION-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_process_invocation_authorization_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_process_invocation_authorization_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_process_invocation_authorization_gate',
                'require_codex_real_invoker_post_start_external_process_runtime_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'record_process_invocation_authorization_without_invoking_external_process',
                'preserve_actual_process_start_disabled_until_external_process_invoker_dry_run',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_process_invocation_authorization_gate_call_allowed_here' => false,
                'post_start_process_invocation_authorization_allowed_by_future_invoker' => true,
                'codex_external_process_invocation_authorization_gate_allowed_by_future_invoker' => true,
                'process_invocation_authorization_is_not_process_invocation' => true,
                'external_process_invoker_dry_run_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_process_invocation_authorization_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start process invocation authorization gate preflight is blocked until external runtime, authorization and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class)
            && method_exists(AgentCodexRealInvokerPostStartExternalProcessInvokerDryRunGate::class, 'preparePostStartExternalProcessInvokerDryRun');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker::class, 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate');
        $dryRunReady = class_exists(AgentCodexExternalProcessInvokerDryRun::class)
            && method_exists(AgentCodexExternalProcessInvokerDryRun::class, 'prepareDryRun');
        $authorizationGateReady = class_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class)
            && method_exists(AgentCodexRealInvokerPostStartProcessInvocationAuthorizationGate::class, 'authorizePostStartProcessInvocation');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_external_process_invoker_dry_run_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_ready',
            'post_start_external_process_invoker_dry_run_gate_contract_hash_present' => $contractHash !== '',
            'post_start_process_invocation_authorization_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_process_invocation_authorization_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_service_ready',
            'generic_post_start_external_process_invoker_dry_run_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_status') === 'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract_template_ready',
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_external_process_invoker_dry_run_gate_invoker_ready' => $invokerReady,
            'codex_external_process_invoker_dry_run_service_ready' => $dryRunReady,
            'codex_real_invoker_post_start_process_invocation_authorization_gate_ready' => $authorizationGateReady,
            'canonical_post_start_external_process_invoker_dry_run_gate_method_ready' => data_get($contract, 'external_process_invoker_dry_run.canonical_post_start_external_process_invoker_dry_run_gate_method') === 'preparePostStartExternalProcessInvokerDryRun',
            'scheduler_invoker_method_ready' => data_get($contract, 'external_process_invoker_dry_run.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
            'contract_requires_process_invocation_authorization' => data_get($contract, 'external_process_invoker_dry_run.post_start_process_invocation_authorization_required_before_dry_run') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'external_process_invoker_dry_run.post_start_evidence_acceptance_bridge_required_before_dry_run') === true,
            'contract_delegates_to_codex_external_process_invoker_dry_run' => data_get($contract, 'external_process_invoker_dry_run.gate_delegates_to_codex_external_process_invoker_dry_run') === true,
            'contract_declares_dry_run_is_not_real_invoker_execution' => data_get($contract, 'external_process_invoker_dry_run.external_process_invoker_dry_run_is_not_real_invoker_execution') === true,
            'contract_requires_real_invoker_release_preflight_after_dry_run' => data_get($contract, 'external_process_invoker_dry_run.real_invoker_release_preflight_required_after_dry_run') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'external_process_invoker_dry_run.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'external_process_invoker_dry_run.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'external_process_invoker_dry_run.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'external_process_invoker_dry_run.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EXTERNAL-PROCESS-INVOKER-DRY-RUN-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_external_process_invoker_dry_run_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
                'require_codex_real_invoker_post_start_process_invocation_authorization_metadata',
                'require_post_start_evidence_acceptance_bridge_metadata',
                'prepare_external_process_invoker_dry_run_without_running_real_invoker',
                'preserve_actual_process_start_disabled_until_real_invoker_release_preflight',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_external_process_invoker_dry_run_gate_call_allowed_here' => false,
                'post_start_external_process_invoker_dry_run_allowed_by_future_invoker' => true,
                'codex_external_process_invoker_dry_run_allowed_by_future_invoker' => true,
                'external_process_invoker_dry_run_is_not_real_invoker_execution' => true,
                'real_invoker_release_preflight_required_after_future_invoker' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_external_process_invoker_dry_run_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'provider_process_call_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate preflight is ready; implementation remains scoped to dry-run preparation only.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start external process invoker dry-run gate preflight is blocked until authorization, dry-run and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderContract(array $options = []): array
    {
        $rehearsalStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerActualProcessStartRehearsalExecutorStatus($options);
        $rehearsalStatus = (array) data_get($rehearsalStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status', []);
        $envelopePayload = $this->section->agentCodexRealInvokerProcessStartEnvelopeBuilderContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-PROCESS-START-ENVELOPE-BUILDER-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status' => data_get($rehearsalStatus, 'status'),
            'source_codex_real_invoker_actual_process_start_rehearsal_executor_status_hash' => data_get($rehearsalStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_actual_process_start_rehearsal_executor_status_hash'),
            'source_codex_real_invoker_process_start_envelope_builder_contract_status' => data_get($envelopePayload, 'status'),
            'source_codex_real_invoker_process_start_envelope_builder_contract_hash' => data_get($envelopePayload, 'codex_real_invoker_process_start_envelope_builder_contract_template_hash'),
            'release_boundary' => [
                'canonical_process_start_envelope_builder' => AgentCodexRealInvokerProcessStartEnvelopeBuilder::class,
                'canonical_process_start_envelope_builder_method' => 'buildStartEnvelope',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerProcessStartEnvelopeBuilderInvoker::class,
                'scheduler_invoker_method' => 'buildCodexRealInvokerProcessStartEnvelope',
                'envelope_effect' => 'record_process_start_envelope_without_starting_process',
                'actual_process_start_rehearsal_required_before_envelope' => true,
                'process_start_envelope_built_by_builder' => true,
                'start_envelope_ready_by_builder' => true,
                'actual_process_start_allowed_by_builder' => false,
                'external_process_started_by_builder' => false,
                'provider_started_by_builder' => false,
                'adapter_execution_allowed_by_builder' => false,
                'token_spend_allowed_by_builder' => false,
                'idempotency_key' => 'real_invoker_process_start_envelope_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_final_process_start_authorization_id',
                'real_invoker_actual_process_start_rehearsal_id',
                'real_invoker_process_start_envelope_id',
                'process_start_rehearsal_hash',
                'command_resolution_hash',
                'environment_resolution_hash',
                'cwd_verification_hash',
                'supervisor_dry_run_hash',
                'liveness_probe_rehearsal_hash',
                'operator_final_start_receipt_hash',
                'final_start_signature_hash',
                'final_start_policy_hash',
                'final_start_window_hash',
                'final_start_replay_guard_hash',
                'final_start_kill_switch_hash',
                'process_start_envelope_hash',
                'start_command_hash',
                'start_environment_hash',
                'start_cwd_hash',
                'start_supervisor_hash',
                'start_liveness_contract_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_process_start_envelope_metadata_on_agent_run',
                'append_codex_real_invoker_process_start_envelope_evidence_event',
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
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_process_start_envelope_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_build_start_envelope',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_process_start_envelope_builder_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker process start envelope contract is ready; it can build the start envelope metadata later but still cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptContract(array $options = []): array
    {
        $receiptStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractStatus($options);
        $receiptStatus = (array) data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status', []);
        $writerPayload = $this->section->agentCodexRealInvokerPostStartEvidenceReceiptWriterContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-EVIDENCE-RECEIPT-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_receipt_contract_status' => data_get($receiptStatus, 'status'),
            'source_codex_real_invoker_post_start_receipt_contract_status_hash' => data_get($receiptStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract_status_hash'),
            'source_codex_real_invoker_post_start_evidence_receipt_writer_status' => data_get($writerPayload, 'status'),
            'source_codex_real_invoker_post_start_evidence_receipt_writer_hash' => data_get($writerPayload, 'codex_real_invoker_post_start_evidence_receipt_writer_contract_template_hash'),
            'release_boundary' => [
                'canonical_post_start_evidence_receipt_writer' => AgentCodexRealInvokerPostStartEvidenceReceiptWriter::class,
                'canonical_post_start_evidence_receipt_writer_method' => 'writePostStartEvidenceReceipt',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker::class,
                'scheduler_invoker_method' => 'writeCodexRealInvokerPostStartEvidenceReceipt',
                'gate_effect' => 'record_operator_external_start_evidence_without_atlas_owned_process_spawn',
                'post_start_receipt_contract_required_before_evidence_receipt' => true,
                'post_start_evidence_acceptance_bridge_required' => true,
                'operator_external_start_attestation_required' => true,
                'no_atlas_process_spawn_attestation_required' => true,
                'external_process_evidence_accepted_by_contract' => true,
                'actual_process_start_allowed_by_contract' => false,
                'atlas_process_spawned_by_contract' => false,
                'provider_marked_started_by_contract' => true,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'post_start_evidence_receipt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'codex_execution_id',
                'real_invoker_process_starter_readiness_gate_id',
                'real_invoker_start_execution_gate_id',
                'manual_start_executor_receipt_id',
                'operator_start_handoff_id',
                'post_start_evidence_acceptance_bridge_id',
                'post_start_receipt_contract_id',
                'post_start_evidence_receipt_id',
                'external_process_identity_contract_hash',
                'startup_evidence_contract_hash',
                'terminal_pid_capture_contract_hash',
                'post_start_cost_meter_contract_hash',
                'external_process_identity_evidence_hash',
                'startup_evidence_hash',
                'terminal_pid_capture_hash',
                'post_start_liveness_probe_hash',
                'post_start_cost_meter_evidence_hash',
                'operator_external_start_attestation_hash',
                'no_atlas_process_spawn_attestation_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_real_invoker_post_start_evidence_receipt_metadata_on_agent_run',
                'append_codex_real_invoker_post_start_evidence_receipt_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'spawn_shell_or_subprocess',
                'spend_provider_tokens',
                'mark_run_terminal',
                'dispatch_work_to_codex',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start evidence receipt contract is ready; it defines governed external-start evidence acceptance without Atlas spawning Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartAdapterInvocationBoundaryGate::class, 'preparePostStartAdapterInvocationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate');
        $boundaryReady = class_exists(AgentDispatchExecutorAdapterInvocationBoundary::class)
            && method_exists(AgentDispatchExecutorAdapterInvocationBoundary::class, 'prepareInvocation');
        $registryReady = class_exists(AgentProviderAdapterRegistry::class)
            && method_exists(AgentProviderAdapterRegistry::class, 'resolve');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $heartbeatsTableReady = Schema::hasTable('atlas_self_construction_agent_heartbeats');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_adapter_invocation_boundary_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_ready',
            'post_start_adapter_invocation_boundary_gate_contract_hash_present' => $contractHash !== '',
            'post_start_provider_start_driver_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_provider_start_driver_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate_service_ready',
            'generic_post_start_adapter_invocation_boundary_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_adapter_invocation_boundary_gate_status') === 'codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract_template_ready',
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_adapter_invocation_boundary_gate_invoker_ready' => $invokerReady,
            'dispatch_executor_adapter_invocation_boundary_ready' => $boundaryReady,
            'provider_adapter_registry_ready' => $registryReady,
            'canonical_post_start_adapter_invocation_boundary_gate_method_ready' => data_get($contract, 'adapter_invocation_boundary.canonical_post_start_adapter_invocation_boundary_gate_method') === 'preparePostStartAdapterInvocationBoundary',
            'contract_requires_provider_start_driver' => data_get($contract, 'adapter_invocation_boundary.post_start_provider_start_driver_required_before_boundary') === true,
            'contract_requires_pre_start_heartbeat' => data_get($contract, 'adapter_invocation_boundary.pre_start_heartbeat_required_before_boundary') === true,
            'contract_requires_context_pack_hash' => data_get($contract, 'adapter_invocation_boundary.context_pack_hash_required') === true,
            'contract_requires_continuation_summary_hash' => data_get($contract, 'adapter_invocation_boundary.continuation_summary_hash_required') === true,
            'contract_projects_adapter_descriptor_hash' => data_get($contract, 'adapter_invocation_boundary.adapter_descriptor_hash_projected_by_boundary') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'adapter_invocation_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'adapter_invocation_boundary.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'adapter_invocation_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'adapter_invocation_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'agent_heartbeats_table_ready' => $heartbeatsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-ADAPTER-INVOCATION-BOUNDARY-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_adapter_invocation_boundary_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'use_one_shot_scheduler_codex_real_invoker_post_start_adapter_invocation_boundary_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
                'require_codex_real_invoker_post_start_provider_start_driver_metadata',
                'require_pre_start_guard_heartbeat_and_provider_run',
                'prepare_adapter_invocation_metadata_without_calling_codex',
                'preserve_adapter_execution_disabled_until_adapter_execution_guard_gate',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_adapter_invocation_boundary_gate_call_allowed_here' => false,
                'adapter_invocation_metadata_allowed_by_future_invoker' => true,
                'provider_external_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_adapter_invocation_boundary_gate_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate preflight is ready.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start adapter invocation boundary gate preflight is blocked until provider-start, boundary, registry and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseStatus(array $options = []): array
    {
        $dispatchReceiptTable = 'atlas_self_construction_agent_dispatch_receipts';
        $ledgerTable = 'atlas_ledger_events';
        $dispatchReceiptTableReady = Schema::hasTable($dispatchReceiptTable);
        $ledgerTableReady = Schema::hasTable($ledgerTable);
        $writerReady = class_exists(AgentDispatchExecutorReceiptUseWriter::class)
            && method_exists(AgentDispatchExecutorReceiptUseWriter::class, 'markReceiptUsedAtomically');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class, 'markSignedDispatchReceiptUsed');
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = $receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $pendingQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'signed_pending_dispatch')
            : null;
        $usedQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'used_pending_provider_start')
                ->whereNotNull('used_at')
            : null;

        if ($receiptHash !== '' && $receiptHashValid) {
            $pendingQuery?->where('receipt_hash', $receiptHash);
            $usedQuery?->where('receipt_hash', $receiptHash);
        }

        $latestUsedReceipt = $usedQuery === null
            ? null
            : (clone $usedQuery)->latest('used_at')->first();
        $statusReady = $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $invokerReady && $receiptHashValid;
        $status = [
            'status' => $statusReady ? 'one_shot_tick_dispatch_receipt_use_service_ready' : 'blocked',
            'invoker_service' => AgentAutomaticDispatchSchedulerOneShotTickDispatchReceiptUseInvoker::class,
            'invoker_service_ready' => $invokerReady,
            'invoker_canonical_method' => 'markSignedDispatchReceiptUsed',
            'writer_service' => AgentDispatchExecutorReceiptUseWriter::class,
            'writer_service_ready' => $writerReady,
            'writer_canonical_method' => 'markReceiptUsedAtomically',
            'dispatch_receipts_table' => $dispatchReceiptTable,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'receipt_hash_filter' => $receiptHashValid && $receiptHash !== '' ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHashValid,
            'signed_pending_dispatch_receipt_count' => $pendingQuery === null ? null : (clone $pendingQuery)->count(),
            'used_pending_provider_start_receipt_count' => $usedQuery === null ? null : (clone $usedQuery)->count(),
            'latest_used_pending_provider_start_receipt' => $latestUsedReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $latestUsedReceipt->id,
                    'receipt_key' => $latestUsedReceipt->receipt_key,
                    'receipt_hash' => $latestUsedReceipt->receipt_hash,
                    'packet_id' => $latestUsedReceipt->packet_id,
                    'provider' => $latestUsedReceipt->provider,
                    'used_at' => $latestUsedReceipt->used_at?->toIso8601String(),
                    'provider_start_attempt_id' => data_get($latestUsedReceipt->payload, 'receipt_use.provider_start_attempt_id'),
                    'provider_start_side_effect_performed' => data_get($latestUsedReceipt->payload, 'receipt_use.provider_start_side_effect_performed'),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'invoker_may_mark_receipt_used_when_called_with_signed_input' => true,
                'receipt_use_is_not_provider_start' => true,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $statusReady
                ? 'activate_signed_one_shot_scheduler_tick_provider_start_driver_release_contract'
                : 'repair_one_shot_scheduler_tick_dispatch_receipt_use_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'dispatch_receipt_use_allowed' => false,
            'provider_start_allowed' => false,
            'adapter_invocation_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_mark_receipt_used',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_dispatch_receipt_use_status_does_not_enable_self_programming',
            ],
            'human_summary' => $statusReady
                ? 'Automatic dispatch scheduler one-shot tick dispatch receipt-use service is ready and inspectable; status remains read-only and provider start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick dispatch receipt-use service is blocked until invoker, writer, storage and receipt filter prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunContract(array $options = []): array
    {
        $authorizationStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexProcessInvocationAuthorizationStatus($options);
        $authorizationStatus = (array) data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status', []);
        $dryRunPayload = $this->section->agentCodexExternalProcessInvokerDryRunContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_external_process_invoker_dry_run_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-EXTERNAL-PROCESS-INVOKER-DRY-RUN-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_process_invocation_authorization_status' => data_get($authorizationStatus, 'status'),
            'source_codex_process_invocation_authorization_status_hash' => data_get($authorizationStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_process_invocation_authorization_status_hash'),
            'source_codex_external_process_invoker_dry_run_contract_status' => data_get($dryRunPayload, 'status'),
            'source_codex_external_process_invoker_dry_run_contract_hash' => data_get($dryRunPayload, 'codex_external_process_invoker_dry_run_contract_template_hash'),
            'release_boundary' => [
                'canonical_dry_run' => AgentCodexExternalProcessInvokerDryRun::class,
                'canonical_dry_run_method' => 'prepareDryRun',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexExternalProcessInvokerDryRunInvoker::class,
                'scheduler_invoker_method' => 'prepareCodexExternalProcessInvokerDryRun',
                'dry_run_effect' => 'prepare_codex_external_process_invoker_dry_run_metadata_without_real_process_invocation',
                'external_process_started_by_dry_run' => false,
                'provider_started_by_dry_run' => false,
                'adapter_execution_allowed_by_dry_run' => false,
                'token_spend_allowed_by_dry_run' => false,
                'required_authorization_status_before_dry_run' => 'authorized_pending_external_process_invoker',
                'prepared_status_after_dry_run' => 'dry_run_ready_pending_real_invoker_release',
                'idempotency_key' => 'dry_run_id',
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
                'operator_dry_run_receipt_hash',
                'invoker_contract_hash',
                'process_command_hash',
                'environment_contract_hash',
                'termination_policy_hash',
                'stdout_stderr_sink_hash',
                'liveness_probe_hash',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'write_codex_external_process_invoker_dry_run_metadata_on_agent_run',
                'append_codex_external_process_invoker_dry_run_prepared_evidence_event',
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
                'external_process_invoker_dry_run_is_rehearsal_not_invocation' => true,
                'real_invoker_release_requires_separate_signed_preflight' => true,
                'operator_dry_run_receipt_hash_required' => true,
                'invoker_contract_hash_required' => true,
                'stdout_stderr_sink_hash_required' => true,
                'liveness_probe_hash_required' => true,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_external_process_invoker_dry_run_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'codex_external_process_invoker_dry_run_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_call_dry_run',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_call_adapters',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_external_process_invoker_dry_run_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex external process invoker dry-run contract is ready; it rehearses invocation metadata but cannot start Codex.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorContract(array $options = []): array
    {
        $handoffStatusPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffStatus($options);
        $handoffStatus = (array) data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status', []);
        $receiptUsePayload = $this->section->agentCodexRealInvokerPostStartDispatchReceiptUseExecutorContractTemplate($options);

        $contract = [
            'status' => 'one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_ready',
            'contract_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-DISPATCH-RECEIPT-USE-EXECUTOR-CONTRACT-SELF-CONSTRUCTION-0001',
            'parent_program' => 'Atlas Self-Construction OS',
            'submodule' => 'Atlas Agent Control Plane',
            'provider' => 'codex',
            'adapter' => 'codex',
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_status' => data_get($handoffStatus, 'status'),
            'source_codex_real_invoker_post_start_dispatch_executor_handoff_status_hash' => data_get($handoffStatusPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff_status_hash'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_status' => data_get($receiptUsePayload, 'status'),
            'source_codex_real_invoker_post_start_dispatch_receipt_use_executor_hash' => data_get($receiptUsePayload, 'codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_template_hash'),
            'receipt_use_boundary' => [
                'canonical_post_start_dispatch_receipt_use_executor' => AgentCodexRealInvokerPostStartDispatchReceiptUseExecutor::class,
                'canonical_post_start_dispatch_receipt_use_executor_method' => 'executePostStartDispatchReceiptUse',
                'scheduler_invoker' => AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker::class,
                'scheduler_invoker_method' => 'executeCodexRealInvokerPostStartDispatchReceiptUse',
                'receipt_use_effect' => 'mark_signed_dispatch_receipt_used_after_dispatch_executor_handoff_without_starting_provider',
                'post_start_dispatch_executor_handoff_required_before_receipt_use' => true,
                'post_start_evidence_acceptance_bridge_required_before_receipt_use' => true,
                'signed_dispatch_authorization_required_before_receipt_use' => true,
                'signed_dispatch_receipt_hash_required' => true,
                'executor_contract_hash_required' => true,
                'executor_release_authorization_hash_required' => true,
                'executor_handoff_packet_hash_required' => true,
                'executor_workspace_hash_required' => true,
                'executor_scope_lock_hash_required' => true,
                'dispatch_receipt_used_by_contract' => true,
                'provider_start_allowed_after_mark_by_contract' => false,
                'actual_process_start_allowed_by_contract' => false,
                'provider_process_call_allowed_by_contract' => false,
                'adapter_execution_allowed_by_contract' => false,
                'token_spend_allowed_by_contract' => false,
                'dispatch_allowed_by_contract' => false,
                'idempotency_key' => 'provider_start_attempt_id',
            ],
            'required_input_fields_for_future_invoker' => [
                'run_key',
                'dispatch_executor_handoff_id',
                'signed_dispatch_authorization_id',
                'post_start_evidence_acceptance_bridge_id',
                'signed_dispatch_receipt_hash',
                'executor_contract_hash',
                'executor_release_authorization_hash',
                'executor_handoff_packet_hash',
                'executor_workspace_hash',
                'executor_scope_lock_hash',
                'provider_start_attempt_id',
                'actor',
                'session',
                'reason',
            ],
            'allowed_future_mutations' => [
                'mark_one_signed_dispatch_receipt_used_via_atomic_writer',
                'write_codex_real_invoker_post_start_dispatch_receipt_use_metadata_on_agent_run',
                'append_dispatch_receipt_use_evidence_event',
            ],
            'forbidden_even_after_contract' => [
                'spawn_codex_process',
                'call_codex_cli_or_codex_app',
                'dispatch_work_to_codex',
                'send_prompt_to_provider',
                'call_provider_process',
                'enable_adapter_invocation',
                'spend_provider_tokens',
                'mark_run_running_or_terminal',
                'enable_self_programming',
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_preflight',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract.v1',
            'status' => (string) $contract['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'post_start_dispatch_receipt_use_executor_allowed' => false,
            'actual_process_start_allowed' => false,
            'adapter_execution_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract' => $contract,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_hash' => $this->section->stableHash($contract),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract_does_not_enable_self_programming',
            ],
            'human_summary' => 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start dispatch receipt-use executor contract is ready; it marks a signed receipt used after executor handoff without starting providers.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGatePreflight(array $options = []): array
    {
        $contractPayload = $this->section->agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateContract($options);
        $contract = (array) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract', []);
        $contractHash = (string) data_get($contractPayload, 'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_hash');
        $gateReady = class_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class)
            && method_exists(AgentCodexRealInvokerPostStartImplementationBoundaryGate::class, 'preparePostStartImplementationBoundary');
        $invokerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class)
            && method_exists(AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker::class, 'prepareCodexRealInvokerPostStartImplementationBoundaryGate');
        $boundaryReady = class_exists(AgentCodexRealInvokerImplementationBoundary::class)
            && method_exists(AgentCodexRealInvokerImplementationBoundary::class, 'prepareBoundary');
        $signedReleaseReady = class_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class)
            && method_exists(AgentCodexRealInvokerPostStartSignedRealInvokerReleaseGate::class, 'authorizePostStartSignedRealInvokerRelease');
        $runsTableReady = Schema::hasTable('atlas_self_construction_agent_runs');
        $ledgerTableReady = Schema::hasTable('atlas_ledger_events');

        $checks = [
            'post_start_implementation_boundary_gate_contract_ready' => data_get($contractPayload, 'status') === 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract_ready',
            'post_start_implementation_boundary_gate_contract_hash_present' => $contractHash !== '',
            'post_start_signed_real_invoker_release_gate_status_ready' => data_get($contract, 'source_codex_real_invoker_post_start_signed_real_invoker_release_gate_status') === 'one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_service_ready',
            'generic_post_start_implementation_boundary_gate_template_ready' => data_get($contract, 'source_codex_real_invoker_post_start_implementation_boundary_gate_status') === 'codex_real_invoker_post_start_implementation_boundary_gate_contract_template_ready',
            'codex_real_invoker_post_start_implementation_boundary_gate_ready' => $gateReady,
            'codex_real_invoker_post_start_implementation_boundary_gate_invoker_ready' => $invokerReady,
            'codex_real_invoker_implementation_boundary_ready' => $boundaryReady,
            'codex_real_invoker_post_start_signed_real_invoker_release_gate_ready' => $signedReleaseReady,
            'canonical_post_start_implementation_boundary_gate_method_ready' => data_get($contract, 'implementation_boundary.canonical_post_start_implementation_boundary_gate_method') === 'preparePostStartImplementationBoundary',
            'scheduler_invoker_method_ready' => data_get($contract, 'implementation_boundary.scheduler_invoker_method') === 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
            'contract_requires_post_start_signed_release' => data_get($contract, 'implementation_boundary.post_start_signed_real_invoker_release_required_before_boundary') === true,
            'contract_requires_post_start_evidence_acceptance_bridge' => data_get($contract, 'implementation_boundary.post_start_evidence_acceptance_bridge_required_before_boundary') === true,
            'contract_delegates_to_codex_real_invoker_implementation_boundary' => data_get($contract, 'implementation_boundary.gate_delegates_to_codex_real_invoker_implementation_boundary') === true,
            'contract_declares_boundary_is_not_real_invoker_execution' => data_get($contract, 'implementation_boundary.implementation_boundary_is_not_real_invoker_execution') === true,
            'contract_requires_executor_plan_after_boundary' => data_get($contract, 'implementation_boundary.executor_plan_required_after_boundary') === true,
            'contract_keeps_actual_process_start_disabled' => data_get($contract, 'implementation_boundary.actual_process_start_allowed_by_contract') === false,
            'contract_keeps_adapter_execution_disabled' => data_get($contract, 'implementation_boundary.adapter_execution_allowed_by_contract') === false,
            'contract_does_not_dispatch_work' => data_get($contract, 'implementation_boundary.dispatch_allowed_by_contract') === false,
            'contract_does_not_spend_tokens' => data_get($contract, 'implementation_boundary.token_spend_allowed_by_contract') === false,
            'agent_runs_table_ready' => $runsTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'self_programming_forbidden' => in_array('enable_self_programming', (array) data_get($contract, 'forbidden_even_after_contract', []), true),
        ];
        $blockingReasons = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));

        $preflight = [
            'status' => $blockingReasons === [] ? 'one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_ready' : 'blocked',
            'preflight_id' => 'AGENT-AUTOMATIC-DISPATCH-SCHEDULER-ONE-SHOT-TICK-CODEX-REAL-INVOKER-POST-START-IMPLEMENTATION-BOUNDARY-GATE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'source_post_start_implementation_boundary_gate_contract_hash' => $contractHash,
            'preflight_checks' => $checks,
            'blocking_count' => count($blockingReasons),
            'blocking_reasons' => $blockingReasons,
            'implementation_requirements' => [
                'create_scheduler_specific_post_start_implementation_boundary_gate_invoker',
                'delegate_to_agent_codex_real_invoker_post_start_implementation_boundary_gate',
                'require_post_start_signed_real_invoker_release_metadata',
                'require_operator_implementation_boundary_receipt_hash',
                'preserve_executor_plan_after_boundary',
                'project_readiness_status_without_invoking_codex',
            ],
            'runtime_policy' => [
                'preflight_is_read_only' => true,
                'post_start_implementation_boundary_gate_call_allowed_by_future_invoker' => true,
                'executor_plan_required_after_boundary' => true,
                'actual_process_start_allowed_here' => false,
                'provider_process_call_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'adapter_execution_allowed_here' => false,
                'dispatch_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_implementation_packet',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight.v1',
            'status' => (string) $preflight['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight',
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
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight' => $preflight,
            'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_hash' => $this->section->stableHash($preflight),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_start_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_call_codex',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_execute_adapter',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_spend_tokens',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_dispatch_work',
                'agent_automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate_preflight_does_not_enable_self_programming',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate preflight is ready; implementation remains scoped to boundary preparation only.'
                : 'Automatic dispatch scheduler one-shot tick Codex real invoker post-start implementation boundary gate preflight is blocked until signed release, implementation boundary and storage prerequisites are ready.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null, actor?: string|null, session?: string|null, packet?: string|null, receipt_hash?: string|null}  $options
     * @return array<string, mixed>
     */
    public function agentAutomaticDispatchSchedulerOneShotTickMutatingWriterStatus(array $options = []): array
    {
        $wakeupTable = 'atlas_self_construction_agent_wakeup_items';
        $dispatchReceiptTable = 'atlas_self_construction_agent_dispatch_receipts';
        $ledgerTable = 'atlas_ledger_events';
        $wakeupTableReady = Schema::hasTable($wakeupTable);
        $dispatchReceiptTableReady = Schema::hasTable($dispatchReceiptTable);
        $ledgerTableReady = Schema::hasTable($ledgerTable);
        $writerReady = class_exists(AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class);
        $receiptHash = strtolower(trim((string) ($options['receipt_hash'] ?? '')));
        $receiptHashValid = $receiptHash === '' || preg_match('/^[a-f0-9]{64}$/', $receiptHash) === 1;

        $dispatchQuery = $dispatchReceiptTableReady
            ? AtlasSelfConstructionAgentDispatchReceipt::query()
                ->where('decision', 'approve_dispatch_once')
                ->where('status', 'signed_pending_dispatch')
            : null;

        if ($dispatchQuery !== null && $receiptHash !== '' && $receiptHashValid) {
            $dispatchQuery->where('receipt_hash', $receiptHash);
        }

        $latestDispatchReceipt = $dispatchQuery === null
            ? null
            : (clone $dispatchQuery)->latest('created_at')->first();

        $status = [
            'status' => $wakeupTableReady && $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $receiptHashValid
                ? 'one_shot_tick_mutating_writer_service_ready'
                : 'blocked',
            'writer_service' => AgentAutomaticDispatchSchedulerOneShotTickMutatingWriter::class,
            'writer_service_ready' => $writerReady,
            'wakeup_items_table' => $wakeupTable,
            'wakeup_items_table_ready' => $wakeupTableReady,
            'dispatch_receipts_table' => $dispatchReceiptTable,
            'dispatch_receipts_table_ready' => $dispatchReceiptTableReady,
            'ledger_table_ready' => $ledgerTableReady,
            'receipt_hash_filter' => $receiptHashValid && $receiptHash !== '' ? $receiptHash : null,
            'receipt_hash_filter_valid' => $receiptHashValid,
            'claimed_wakeup_count' => $wakeupTableReady
                ? AtlasSelfConstructionAgentWakeupItem::query()->where('status', 'claimed')->whereNotNull('claimed_at')->count()
                : null,
            'signed_pending_dispatch_receipt_count' => $dispatchQuery === null ? null : (clone $dispatchQuery)->count(),
            'latest_signed_pending_dispatch_receipt' => $latestDispatchReceipt instanceof AtlasSelfConstructionAgentDispatchReceipt
                ? [
                    'receipt_id' => $latestDispatchReceipt->id,
                    'receipt_key' => $latestDispatchReceipt->receipt_key,
                    'receipt_hash' => $latestDispatchReceipt->receipt_hash,
                    'wakeup_item_id' => $latestDispatchReceipt->wakeup_item_id,
                    'packet_id' => $latestDispatchReceipt->packet_id,
                    'provider' => $latestDispatchReceipt->provider,
                    'signed_by' => $latestDispatchReceipt->signed_by,
                    'signed_at' => $latestDispatchReceipt->signed_at?->toIso8601String(),
                    'expires_at' => $latestDispatchReceipt->expires_at?->toIso8601String(),
                    'created_at' => $latestDispatchReceipt->created_at?->toIso8601String(),
                ]
                : null,
            'runtime_policy' => [
                'status_projection_is_read_only' => true,
                'writer_service_may_claim_one_wakeup_after_signed_release' => true,
                'writer_service_may_write_one_signed_pending_dispatch_receipt_after_signed_release' => true,
                'dispatch_receipt_use_allowed_here' => false,
                'provider_start_allowed_here' => false,
                'adapter_invocation_allowed_here' => false,
                'token_spend_allowed_here' => false,
                'self_programming_allowed_here' => false,
            ],
            'next_required_slice' => $wakeupTableReady && $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $receiptHashValid
                ? 'activate_signed_one_shot_scheduler_tick_guarded_runtime_invocation_contract'
                : 'repair_one_shot_scheduler_tick_mutating_writer_service_blockers',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status.v1',
            'status' => (string) $status['status'],
            'mode' => 'read_only_agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'signature_acceptance_allowed' => false,
            'release_receipt_persistence_allowed' => false,
            'claim_allowed' => false,
            'dispatch_receipt_write_allowed' => false,
            'provider_start_allowed' => false,
            'token_spend_allowed' => false,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status' => $status,
            'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_hash' => $this->section->stableHash($status),
            'non_execution_guarantees' => [
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_claim_wakeup_items',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_write_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_use_dispatch_receipts',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_start_providers',
                'agent_automatic_dispatch_scheduler_one_shot_tick_mutating_writer_status_does_not_enable_self_programming',
            ],
            'human_summary' => $wakeupTableReady && $dispatchReceiptTableReady && $ledgerTableReady && $writerReady && $receiptHashValid
                ? 'Automatic dispatch scheduler one-shot tick mutating writer service is ready and inspectable; status remains read-only and provider start is still forbidden.'
                : 'Automatic dispatch scheduler one-shot tick mutating writer service is blocked until storage, ledger, writer and receipt filter prerequisites are ready.',
        ];
    }


}
