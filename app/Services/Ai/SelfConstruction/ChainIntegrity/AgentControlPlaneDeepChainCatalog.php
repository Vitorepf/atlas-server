<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ChainIntegrity;

/**
 * Canonical deep-chain catalog and normalization for the Agent Control Plane
 * chain integrity audit.
 *
 * Extracted from AgentControlPlaneChainIntegrityAuditService to reduce the
 * god-class. All methods are pure — no instance state.
 */
final class AgentControlPlaneDeepChainCatalog
{
    /**
     * @return list<array<string, string>>
     */
    public static function canonicalDeepChain(): array
    {
        return [
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
                docBullet: 'post-start signed real invoker release gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
                docBullet: 'post-start implementation boundary gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartExecutorPlanGate',
                docBullet: 'post-start executor plan gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate',
                docBullet: 'post-start executor fresh release gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker',
                prepareMethod: 'enableCodexRealInvokerPostStartExecutorGate',
                docBullet: 'post-start executor enablement gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate',
                docBullet: 'post-start supervised start activation gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
                docBullet: 'post-start guarded process start executor gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
                docBullet: 'post-start final process start authorization gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                docBullet: 'post-start actual process start rehearsal gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate',
                docBullet: 'post-start process start envelope gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartStartExecutionGate',
                docBullet: 'post-start start execution gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProcessStarterReadinessGate',
                docBullet: 'post-start process starter readiness gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceipt',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartManualStartExecutorReceipt',
                docBullet: 'post-start manual start executor receipt',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoff',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartOperatorStartHandoff',
                docBullet: 'post-start operator start handoff',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker',
                prepareMethod: 'buildCodexRealInvokerPostStartReceiptContract',
                docBullet: 'post-start receipt contract',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceipt',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker',
                prepareMethod: 'writeCodexRealInvokerPostStartEvidenceReceipt',
                docBullet: 'post-start evidence receipt',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridge',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker',
                prepareMethod: 'acceptCodexRealInvokerPostStartEvidence',
                docBullet: 'post-start evidence acceptance bridge',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitor',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker',
                prepareMethod: 'recordCodexRealInvokerPostStartLiveness',
                docBullet: 'post-start liveness monitor',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartDispatchRelease',
                docBullet: 'post-start dispatch release gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartSignedDispatch',
                docBullet: 'post-start signed dispatch authorization gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoff',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartDispatchExecutorHandoff',
                docBullet: 'post-start dispatch executor handoff',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutor',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker',
                prepareMethod: 'executeCodexRealInvokerPostStartDispatchReceiptUse',
                docBullet: 'post-start dispatch receipt-use executor',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProviderStartDriverGate',
                docBullet: 'post-start provider start driver gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                docBullet: 'post-start adapter invocation boundary gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker',
                prepareMethod: 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate',
                docBullet: 'post-start adapter execution guard gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
                docBullet: 'post-start provider execution contract gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
                docBullet: 'post-start process start release gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
                docBullet: 'post-start supervised start executor gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker',
                prepareMethod: 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
                docBullet: 'post-start process spawn enablement gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
                docBullet: 'post-start final process spawn executor gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
                docBullet: 'post-start external process runtime gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
                docBullet: 'post-start process invocation authorization gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
                docBullet: 'post-start external process invoker dry-run gate',
            ),
            self::deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartRealInvokerReleasePreflightGateInvoker',
                prepareMethod: 'recordCodexRealInvokerPostStartRealInvokerReleasePreflightGate',
                docBullet: 'post-start real invoker release preflight gate',
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function deepChainEntry(string $sliceKey, string $methodPrefix, string $invokerClass, string $prepareMethod, string $docBullet): array
    {
        $endsInContract = str_ends_with($sliceKey, '_contract');
        $contractSuffix = $endsInContract ? '' : '_contract';

        return [
            'slice_key' => $sliceKey,
            'method_prefix' => $methodPrefix,
            'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\'.$invokerClass,
            'prepare_method' => $prepareMethod,
            'doc_bullet' => $docBullet,
            'activate_key' => 'activate_signed_one_shot_scheduler_tick_'.preg_replace('/^automatic_dispatch_scheduler_one_shot_tick_/', '', $sliceKey).$contractSuffix,
            'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_'.self::stripDispatchPrefix($sliceKey).$contractSuffix.'_runtime',
            'contract_capability_key' => $sliceKey.$contractSuffix,
            'preflight_capability_key' => $sliceKey.'_preflight',
            'implementation_packet_capability_key' => $sliceKey.'_implementation_packet',
            'invoker_service_capability_key' => $sliceKey.'_invoker_service',
            'status_projection_capability_key' => $sliceKey.'_status_projection',
        ];
    }

    public static function stripDispatchPrefix(string $sliceKey): string
    {
        return preg_replace('/^automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_/', '', $sliceKey);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawOverride
     * @return list<array<string, string>>
     */
    public static function normalizeOverrideChain(array $rawOverride): array
    {
        $normalized = [];
        foreach ($rawOverride as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $sliceKey = (string) ($entry['slice_key'] ?? '');
            if ($sliceKey === '') {
                continue;
            }
            $normalized[] = [
                'slice_key' => $sliceKey,
                'method_prefix' => (string) ($entry['method_prefix'] ?? ''),
                'invoker_class' => (string) ($entry['invoker_class'] ?? ''),
                'prepare_method' => (string) ($entry['prepare_method'] ?? ''),
                'doc_bullet' => (string) ($entry['doc_bullet'] ?? ''),
                'activate_key' => (string) ($entry['activate_key'] ?? ''),
                'runtime_key' => (string) ($entry['runtime_key'] ?? ''),
            ];
        }

        return $normalized;
    }

    public static function deriveActivateKeyFromRuntime(string $runtimeKey): string
    {
        if ($runtimeKey === '') {
            return '';
        }
        $body = preg_replace('/^automatic_dispatch_scheduler_/', '', $runtimeKey);
        $body = preg_replace('/_runtime$/', '', $body);

        return 'activate_signed_one_shot_scheduler_tick_'.$body;
    }
}
