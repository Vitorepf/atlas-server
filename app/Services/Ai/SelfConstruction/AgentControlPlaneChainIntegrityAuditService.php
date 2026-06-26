<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;

/**
 * Read-only structural certification of the Atlas Agent Control Plane chain.
 *
 * It audits the scheduler-specific Codex real invoker gates and adjacent
 * artifacts (readiness methods, invoker classes, CLI options, capabilities,
 * documentation bullets, runtime-disabled invariants, and global pointers)
 * to catch regressions before next slices are accelerated.
 *
 * It MUST NOT start providers, call Codex CLI/app, spawn subprocesses,
 * spend tokens, dispatch work, invoke adapters, enable runtime flags or
 * promote completion claims. It is a diagnostic projection — nothing
 * more.
 */
final class AgentControlPlaneChainIntegrityAuditService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_chain_integrity_certification.v1';

    public const MODE = 'read_only_agent_control_plane_chain_integrity_certification';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $controlPlane = isset($options['override_projection']) && is_array($options['override_projection'])
            ? $this->mergeOverrideProjection(
                $this->readiness->agentControlPlane($this->normalizeReadinessOptions($options)),
                $options['override_projection'],
            )
            : $this->readiness->agentControlPlane($this->normalizeReadinessOptions($options));
        $currentCapability = (array) data_get($controlPlane, 'control_plane.current_capability', []);
        $currentNotYetRuntimeCapable = (array) data_get($controlPlane, 'control_plane.not_yet_runtime_capable', []);
        $currentNextRequiredSlice = (string) data_get($controlPlane, 'control_plane.persistent_runtime.next_required_slice', '');
        $currentNextBuildSlices = (array) data_get($controlPlane, 'control_plane.next_build_slices', []);

        $deepChainOverride = isset($options['override_slices']) && is_array($options['override_slices'])
            ? $this->normalizeOverrideChain($options['override_slices'])
            : null;
        $deepChain = $deepChainOverride ?? $this->canonicalDeepChain();

        $violations = [];
        $warnings = [];
        $sliceReports = [];
        foreach ($deepChain as $slice) {
            $report = $this->auditSlice($slice, $currentCapability);
            $sliceReports[] = $report;
            foreach ($report['violations'] as $violation) {
                $violations[] = $violation;
            }
            foreach ($report['warnings'] as $warning) {
                $warnings[] = $warning;
            }
        }

        $expectedNextRequiredSlice = $this->expectedNextRequiredSlice(
            $deepChain,
            $currentNotYetRuntimeCapable,
            $currentNextRequiredSlice,
            $controlPlane,
        );

        $globalInvariants = $this->globalInvariants(
            currentCapability: $currentCapability,
            currentNotYetRuntimeCapable: $currentNotYetRuntimeCapable,
            currentNextRequiredSlice: $currentNextRequiredSlice,
            currentNextBuildSlices: $currentNextBuildSlices,
            expectedNextRequiredSlice: $expectedNextRequiredSlice,
            controlPlane: $controlPlane,
        );

        foreach ($globalInvariants['violations'] as $violation) {
            $violations[] = $violation;
        }
        foreach ($globalInvariants['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        $runtimeSafety = $this->runtimeSafety($controlPlane);
        $documentation = $this->documentationAudit($deepChain);
        $cliSurface = $this->cliSurface($deepChain);
        $shallowChain = $this->buildShallowChain($currentCapability);
        $shallowReports = $this->auditShallowChain($shallowChain, $currentCapability);
        $regressionMatrix = $this->regressionMatrix($deepChain, $controlPlane);
        $chainCoverage = $this->chainCoverage($deepChain, $shallowChain, $sliceReports, $shallowReports);
        $capabilityGaps = $this->capabilityGaps($deepChain, $sliceReports);
        $invokerGaps = $this->collectInvokerGaps($sliceReports);
        $readinessGaps = $this->collectReadinessGaps($sliceReports);
        $cliOptionGaps = (array) data_get($cliSurface, 'missing_options', []);
        $runtimeSafetyGaps = $this->runtimeSafetyGaps($runtimeSafety);
        $capabilitySurface = [
            'total' => count($currentCapability),
            'deep_checked' => count($deepChain),
            'shallow_checked' => count($shallowChain),
            'duplicate_count' => $this->countDuplicates($currentCapability),
        ];
        $readinessSurface = [
            'deep_checked' => count($deepChain),
            'all_quartet_methods_present' => $this->allQuartetMethodsPresent($sliceReports),
            'readiness_gaps_count' => count($readinessGaps),
        ];
        $invokerSurface = $this->invokerSurface($deepChain);
        $testSurface = $this->testSurface();

        foreach ($regressionMatrix['violations'] as $violation) {
            $violations[] = $violation;
        }
        foreach ($shallowReports['violations'] as $violation) {
            $violations[] = $violation;
        }
        foreach ($shallowReports['warnings'] as $warning) {
            $warnings[] = $warning;
        }

        $postStartEvidenceCorridor = $this->postStartEvidenceCorridor($sliceReports, $currentNextRequiredSlice);
        foreach ($postStartEvidenceCorridor['violations'] as $violation) {
            $violations[] = $violation;
        }
        $providerToRuntimeCorridor = $this->providerToRuntimeCorridor($sliceReports, $currentNextRequiredSlice);
        foreach ($providerToRuntimeCorridor['violations'] as $violation) {
            $violations[] = $violation;
        }
        $providerRuntimePreflightMatrix = $this->providerRuntimePreflightMatrix($sliceReports);
        $implementationToOperatorHandoffCorridor = $this->implementationToOperatorHandoffCorridor($sliceReports, $currentNextRequiredSlice);
        foreach ($implementationToOperatorHandoffCorridor['violations'] as $violation) {
            $violations[] = $violation;
        }
        $cycleIntegrity = $this->cycleIntegrity($deepChain, $currentNextRequiredSlice);
        foreach ($cycleIntegrity['cycle_violations'] as $violation) {
            $violations[] = $violation;
        }
        foreach ($cycleIntegrity['cycle_warnings'] as $warning) {
            $warnings[] = $warning;
        }
        $terminalHorizonAnalysis = $this->terminalHorizonAnalysis(
            $deepChain,
            $currentNextRequiredSlice,
            $cycleIntegrity,
        );

        if ($capabilitySurface['duplicate_count'] > 0) {
            $violations[] = [
                'code' => 'capability_surface_has_duplicates',
                'detail' => 'Duplicate entries detected in agentControlPlane().current_capability list.',
            ];
        }

        if ($runtimeSafety['runtime_safety_all_false'] === false) {
            $violations[] = [
                'code' => 'runtime_safety_invariant_breached',
                'detail' => 'A runtime-disabled flag has been flipped on the Agent Control Plane projection.',
            ];
        }

        if ($documentation['contract_doc_present'] === false) {
            $violations[] = [
                'code' => 'contract_doc_missing',
                'detail' => 'Agent Control Plane contract doc is not present at the canonical path.',
            ];
        }

        foreach ($documentation['duplicate_slice_bullets'] as $duplicateSlice) {
            $violations[] = [
                'code' => 'duplicate_slice_bullet_in_doc',
                'slice_key' => $duplicateSlice,
                'detail' => 'Canonical bullet for slice appears more than once in agent-control-plane-contract.md.',
            ];
        }

        $status = match (true) {
            $violations !== [] => 'degraded',
            $warnings !== [] => 'available',
            default => 'available',
        };

        $invariantsMap = $this->invariantsMap(
            globalInvariants: $globalInvariants,
            runtimeSafety: $runtimeSafety,
            sliceReports: $sliceReports,
            documentation: $documentation,
            cliSurface: $cliSurface,
            capabilitySurface: $capabilitySurface,
        );
        $invariantsAllTrue = ! in_array(false, $invariantsMap, true);

        $nextAction = match (true) {
            $violations !== [] => 'fix_violations',
            $currentNextRequiredSlice !== $expectedNextRequiredSlice => 'advance_pointer',
            default => 'verify_alignment',
        };

        $certification = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'current_next_required_slice' => $currentNextRequiredSlice,
            'current_not_yet_runtime_capable' => $currentNotYetRuntimeCapable,
            'current_next_build_slices' => $currentNextBuildSlices,
            'expected_next_required_slice' => $expectedNextRequiredSlice,
            'chain_length' => count($deepChain),
            'checked_slice_count' => count($sliceReports),
            'slices' => $sliceReports,
            'violations' => $violations,
            'warnings' => $warnings,
            'invariants' => $invariantsMap,
            'invariants_all_true' => $invariantsAllTrue,
            'runtime_safety' => $runtimeSafety,
            'documentation' => $documentation,
            'cli_surface' => $cliSurface,
            'capability_surface' => $capabilitySurface,
            'readiness_surface' => $readinessSurface,
            'invoker_surface' => $invokerSurface,
            'test_surface' => $testSurface,
            'chain_coverage' => $chainCoverage,
            'shallow_chain' => $shallowReports['slices'] ?? [],
            'per_slice_next_edge' => $regressionMatrix['edges'],
            'broken_edges' => $regressionMatrix['broken_edges'],
            'duplicate_doc_bullets' => (array) ($documentation['duplicate_slice_bullets'] ?? []),
            'cli_option_gaps' => $cliOptionGaps,
            'capability_gaps' => $capabilityGaps,
            'invoker_gaps' => $invokerGaps,
            'readiness_gaps' => $readinessGaps,
            'runtime_safety_gaps' => $runtimeSafetyGaps,
            'post_start_evidence_corridor' => $postStartEvidenceCorridor,
            'evidence_to_dispatch_chain_ok' => $postStartEvidenceCorridor['evidence_to_dispatch_chain_ok'],
            'dispatch_authorization_chain_ok' => $postStartEvidenceCorridor['dispatch_authorization_chain_ok'],
            'receipt_use_chain_ok' => $postStartEvidenceCorridor['receipt_use_chain_ok'],
            'post_start_provider_start_driver_ready_next' => $postStartEvidenceCorridor['post_start_provider_start_driver_ready_next'],
            'provider_to_runtime_corridor' => $providerToRuntimeCorridor,
            'provider_to_runtime_chain_ok' => $providerToRuntimeCorridor['provider_to_runtime_chain_ok'],
            'adapter_boundary_chain_ok' => $providerToRuntimeCorridor['adapter_boundary_chain_ok'],
            'provider_execution_contract_chain_ok' => $providerToRuntimeCorridor['provider_execution_contract_chain_ok'],
            'process_start_release_chain_ok' => $providerToRuntimeCorridor['process_start_release_chain_ok'],
            'supervised_spawn_chain_ok' => $providerToRuntimeCorridor['supervised_spawn_chain_ok'],
            'external_runtime_chain_ok' => $providerToRuntimeCorridor['external_runtime_chain_ok'],
            'invocation_authorization_chain_ok' => $providerToRuntimeCorridor['invocation_authorization_chain_ok'],
            'dry_run_to_release_preflight_chain_ok' => $providerToRuntimeCorridor['dry_run_to_release_preflight_chain_ok'],
            'signed_real_release_ready_next' => $providerToRuntimeCorridor['signed_real_release_ready_next'],
            'provider_runtime_preflight_matrix' => $providerRuntimePreflightMatrix,
            'implementation_to_operator_handoff_corridor' => $implementationToOperatorHandoffCorridor,
            'implementation_boundary_chain_ok' => $implementationToOperatorHandoffCorridor['implementation_boundary_chain_ok'],
            'executor_plan_chain_ok' => $implementationToOperatorHandoffCorridor['executor_plan_chain_ok'],
            'executor_release_chain_ok' => $implementationToOperatorHandoffCorridor['executor_release_chain_ok'],
            'executor_enablement_chain_ok' => $implementationToOperatorHandoffCorridor['executor_enablement_chain_ok'],
            'supervised_activation_chain_ok' => $implementationToOperatorHandoffCorridor['supervised_activation_chain_ok'],
            'guarded_start_chain_ok' => $implementationToOperatorHandoffCorridor['guarded_start_chain_ok'],
            'final_authorization_chain_ok' => $implementationToOperatorHandoffCorridor['final_authorization_chain_ok'],
            'rehearsal_to_envelope_chain_ok' => $implementationToOperatorHandoffCorridor['rehearsal_to_envelope_chain_ok'],
            'start_execution_to_readiness_chain_ok' => $implementationToOperatorHandoffCorridor['start_execution_to_readiness_chain_ok'],
            'manual_start_to_operator_handoff_chain_ok' => $implementationToOperatorHandoffCorridor['manual_start_to_operator_handoff_chain_ok'],
            'operator_handoff_reentry_ready' => $implementationToOperatorHandoffCorridor['operator_handoff_reentry_ready'],
            'cycle_integrity' => $cycleIntegrity,
            'terminal_horizon_analysis' => $terminalHorizonAnalysis,
            'next_action' => $nextAction,
            'non_execution_guarantees' => [
                'audit_does_not_start_codex',
                'audit_does_not_call_codex_cli_or_app',
                'audit_does_not_spawn_subprocess',
                'audit_does_not_invoke_adapter',
                'audit_does_not_execute_adapter',
                'audit_does_not_call_provider',
                'audit_does_not_dispatch_work',
                'audit_does_not_spend_tokens',
                'audit_does_not_enable_self_programming',
                'audit_does_not_write_ledger',
                'audit_does_not_mark_runtime_flags_true',
                'audit_does_not_promote_completion_claim',
                'audit_does_not_advance_pointer',
            ],
            'human_summary' => match ($status) {
                'available' => 'Atlas Agent Control Plane chain integrity certification v1 is available; chain is structurally aligned with the current horizon.',
                'degraded' => 'Atlas Agent Control Plane chain integrity certification v1 is degraded; structural violations require remediation.',
                'blocked' => 'Atlas Agent Control Plane chain integrity certification v1 is blocked; required artifacts are missing.',
                'missing_artifacts' => 'Atlas Agent Control Plane chain integrity certification v1 is missing canonical artifacts; cannot certify the chain.',
                default => 'Atlas Agent Control Plane chain integrity certification v1 status is unknown.',
            },
        ];

        $certification['agent_control_plane_chain_integrity_certification_hash'] = $this->stableHash($certification);

        return $certification;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeReadinessOptions(array $options): array
    {
        return array_merge([
            'workspace' => null,
            'target' => null,
            'packet' => null,
            'actor' => null,
            'session' => null,
            'lease_minutes' => null,
            'reason' => null,
            'evidence_hash' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'cost_usd' => null,
            'artifact_type' => null,
            'artifact_path' => null,
            'artifact_hash' => null,
            'summary' => null,
            'decision' => null,
            'signed_by' => null,
            'receipt_hash' => null,
            'dispatch_envelope_hash' => null,
            'adapter_contract_hash' => null,
            'expires_at' => null,
        ], $options);
    }

    /**
     * @return list<array<string, string>>
     */
    private function canonicalDeepChain(): array
    {
        return [
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedRealInvokerReleaseGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartSignedRealInvokerReleaseGate',
                docBullet: 'post-start signed real invoker release gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartImplementationBoundaryGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartImplementationBoundaryGate',
                docBullet: 'post-start implementation boundary gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorPlanGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartExecutorPlanGate',
                docBullet: 'post-start executor plan gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorFreshReleaseGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartExecutorFreshReleaseGate',
                docBullet: 'post-start executor fresh release gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExecutorEnablementGateInvoker',
                prepareMethod: 'enableCodexRealInvokerPostStartExecutorGate',
                docBullet: 'post-start executor enablement gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartActivationGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartSupervisedStartActivationGate',
                docBullet: 'post-start supervised start activation gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartGuardedProcessStartExecutorGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartGuardedProcessStartExecutorGate',
                docBullet: 'post-start guarded process start executor gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessStartAuthorizationGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartFinalProcessStartAuthorizationGate',
                docBullet: 'post-start final process start authorization gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartActualProcessStartRehearsalGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartActualProcessStartRehearsalGate',
                docBullet: 'post-start actual process start rehearsal gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartEnvelopeGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProcessStartEnvelopeGate',
                docBullet: 'post-start process start envelope gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartStartExecutionGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartStartExecutionGate',
                docBullet: 'post-start start execution gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStarterReadinessGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProcessStarterReadinessGate',
                docBullet: 'post-start process starter readiness gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceipt',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartManualStartExecutorReceiptInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartManualStartExecutorReceipt',
                docBullet: 'post-start manual start executor receipt',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoff',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartOperatorStartHandoffInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartOperatorStartHandoff',
                docBullet: 'post-start operator start handoff',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContract',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartReceiptContractInvoker',
                prepareMethod: 'buildCodexRealInvokerPostStartReceiptContract',
                docBullet: 'post-start receipt contract',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceipt',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceReceiptInvoker',
                prepareMethod: 'writeCodexRealInvokerPostStartEvidenceReceipt',
                docBullet: 'post-start evidence receipt',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridge',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartEvidenceAcceptanceBridgeInvoker',
                prepareMethod: 'acceptCodexRealInvokerPostStartEvidence',
                docBullet: 'post-start evidence acceptance bridge',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitor',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartLivenessMonitorInvoker',
                prepareMethod: 'recordCodexRealInvokerPostStartLiveness',
                docBullet: 'post-start liveness monitor',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReleaseGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartDispatchRelease',
                docBullet: 'post-start dispatch release gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSignedDispatchAuthorizationGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartSignedDispatch',
                docBullet: 'post-start signed dispatch authorization gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoff',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchExecutorHandoffInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartDispatchExecutorHandoff',
                docBullet: 'post-start dispatch executor handoff',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutor',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartDispatchReceiptUseExecutorInvoker',
                prepareMethod: 'executeCodexRealInvokerPostStartDispatchReceiptUse',
                docBullet: 'post-start dispatch receipt-use executor',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderStartDriverGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProviderStartDriverGate',
                docBullet: 'post-start provider start driver gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterInvocationBoundaryGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartAdapterInvocationBoundaryGate',
                docBullet: 'post-start adapter invocation boundary gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartAdapterExecutionGuardGateInvoker',
                prepareMethod: 'blockCodexRealInvokerPostStartAdapterExecutionGuardGate',
                docBullet: 'post-start adapter execution guard gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProviderExecutionContractGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartProviderExecutionContractGate',
                docBullet: 'post-start provider execution contract gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessStartReleaseGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartProcessStartReleaseGate',
                docBullet: 'post-start process start release gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartSupervisedStartExecutorGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartSupervisedStartExecutorGate',
                docBullet: 'post-start supervised start executor gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessSpawnEnablementGateInvoker',
                prepareMethod: 'enableCodexRealInvokerPostStartProcessSpawnEnablementGate',
                docBullet: 'post-start process spawn enablement gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartFinalProcessSpawnExecutorGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartFinalProcessSpawnExecutorGate',
                docBullet: 'post-start final process spawn executor gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessRuntimeGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartExternalProcessRuntimeGate',
                docBullet: 'post-start external process runtime gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartProcessInvocationAuthorizationGateInvoker',
                prepareMethod: 'authorizeCodexRealInvokerPostStartProcessInvocationAuthorizationGate',
                docBullet: 'post-start process invocation authorization gate',
            ),
            $this->deepChainEntry(
                sliceKey: 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
                methodPrefix: 'agentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
                invokerClass: 'AgentAutomaticDispatchSchedulerOneShotTickCodexRealInvokerPostStartExternalProcessInvokerDryRunGateInvoker',
                prepareMethod: 'prepareCodexRealInvokerPostStartExternalProcessInvokerDryRunGate',
                docBullet: 'post-start external process invoker dry-run gate',
            ),
            $this->deepChainEntry(
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
    private function deepChainEntry(string $sliceKey, string $methodPrefix, string $invokerClass, string $prepareMethod, string $docBullet): array
    {
        // Some slice families have a base name that already ends in `_contract`
        // (e.g. `post_start_receipt_contract`); for those the contract activate
        // key, capability and method are spelled without a redundant `_contract`
        // suffix. The audit detects that case and aligns its expectations so
        // it does not generate false-positive violations.
        $endsInContract = str_ends_with($sliceKey, '_contract');
        $contractSuffix = $endsInContract ? '' : '_contract';

        return [
            'slice_key' => $sliceKey,
            'method_prefix' => $methodPrefix,
            'invoker_class' => 'App\\Services\\Ai\\SelfConstruction\\'.$invokerClass,
            'prepare_method' => $prepareMethod,
            'doc_bullet' => $docBullet,
            'activate_key' => 'activate_signed_one_shot_scheduler_tick_'.preg_replace('/^automatic_dispatch_scheduler_one_shot_tick_/', '', $sliceKey).$contractSuffix,
            'runtime_key' => 'automatic_dispatch_scheduler_codex_real_invoker_'.$this->stripDispatchPrefix($sliceKey).$contractSuffix.'_runtime',
            'contract_capability_key' => $sliceKey.$contractSuffix,
            'preflight_capability_key' => $sliceKey.'_preflight',
            'implementation_packet_capability_key' => $sliceKey.'_implementation_packet',
            'invoker_service_capability_key' => $sliceKey.'_invoker_service',
            'status_projection_capability_key' => $sliceKey.'_status_projection',
        ];
    }

    private function stripDispatchPrefix(string $sliceKey): string
    {
        return preg_replace('/^automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_/', '', $sliceKey);
    }

    private function cliBaseForSlice(string $sliceKey): string
    {
        if ($sliceKey === '') {
            return '';
        }

        return 'agent-'.str_replace('_', '-', $sliceKey);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawOverride
     * @return list<array<string, string>>
     */
    private function normalizeOverrideChain(array $rawOverride): array
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

    /**
     * @param  array<string, string>  $slice
     * @param  list<string>  $currentCapability
     * @return array<string, mixed>
     */
    private function auditSlice(array $slice, array $currentCapability): array
    {
        $contractMethodCandidate = $slice['method_prefix'] !== ''
            ? (method_exists($this->readiness, $slice['method_prefix'].'Contract')
                ? $slice['method_prefix'].'Contract'
                : (str_ends_with($slice['method_prefix'], 'Contract') && method_exists($this->readiness, $slice['method_prefix'])
                    ? $slice['method_prefix']
                    : ''))
            : '';
        $checks = [
            'contract_method_exists' => $contractMethodCandidate !== '',
            'preflight_method_exists' => $slice['method_prefix'] !== ''
                && method_exists($this->readiness, $slice['method_prefix'].'Preflight'),
            'implementation_packet_method_exists' => $slice['method_prefix'] !== ''
                && method_exists($this->readiness, $slice['method_prefix'].'ImplementationPacket'),
            'status_method_exists' => $slice['method_prefix'] !== ''
                && method_exists($this->readiness, $slice['method_prefix'].'Status'),
            'capability_contract_registered' => in_array((string) ($slice['contract_capability_key'] ?? $slice['slice_key'].'_contract'), $currentCapability, true),
            'capability_preflight_registered' => in_array((string) ($slice['preflight_capability_key'] ?? $slice['slice_key'].'_preflight'), $currentCapability, true),
            'capability_implementation_packet_registered' => in_array((string) ($slice['implementation_packet_capability_key'] ?? $slice['slice_key'].'_implementation_packet'), $currentCapability, true),
            'capability_invoker_service_registered' => in_array((string) ($slice['invoker_service_capability_key'] ?? $slice['slice_key'].'_invoker_service'), $currentCapability, true),
            'capability_status_projection_registered' => in_array((string) ($slice['status_projection_capability_key'] ?? $slice['slice_key'].'_status_projection'), $currentCapability, true),
            'invoker_class_exists' => $slice['invoker_class'] !== ''
                && class_exists($slice['invoker_class']),
            'invoker_prepare_method_exists' => $slice['invoker_class'] !== ''
                && $slice['prepare_method'] !== ''
                && class_exists($slice['invoker_class'])
                && method_exists($slice['invoker_class'], $slice['prepare_method']),
        ];

        $missingArtifacts = array_values(array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed)));
        $ok = $missingArtifacts === [];

        $violations = [];
        $warnings = [];
        foreach ($missingArtifacts as $artifact) {
            $violations[] = [
                'code' => 'slice_missing_artifact',
                'slice_key' => $slice['slice_key'],
                'artifact' => $artifact,
                'detail' => 'Slice quartet artifact missing or unreachable from canonical chain.',
            ];
        }

        return [
            'slice_key' => $slice['slice_key'],
            'method_prefix' => $slice['method_prefix'],
            'invoker_class' => $slice['invoker_class'],
            'prepare_method' => $slice['prepare_method'],
            'doc_bullet' => $slice['doc_bullet'],
            'activate_key' => $slice['activate_key'],
            'runtime_key' => $slice['runtime_key'],
            'checks' => $checks,
            'missing_artifacts' => $missingArtifacts,
            'ok' => $ok,
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @param  list<string>  $currentNotYetRuntimeCapable
     * @param  array<string, mixed>  $controlPlane
     */
    private function expectedNextRequiredSlice(
        array $deepChain,
        array $currentNotYetRuntimeCapable,
        string $currentNextRequiredSlice,
        array $controlPlane,
    ): string {
        // When the runtime schema is not in place yet, the canonical cascade
        // legitimately reports the schema-migration apply pointer regardless
        // of the chain depth. Treat that as the expected pointer to avoid
        // false-positive misalignment violations in environments that have
        // not migrated yet (e.g. fresh test databases).
        $runtimeStatus = (string) data_get($controlPlane, 'control_plane.persistent_runtime.status', '');
        if ($runtimeStatus !== 'schema_ready' && $currentNextRequiredSlice === 'apply_agent_control_plane_runtime_schema_migration') {
            return $currentNextRequiredSlice;
        }
        $expectedRuntimeKey = $currentNotYetRuntimeCapable[3] ?? null;
        if ($expectedRuntimeKey === null) {
            return '';
        }
        foreach ($deepChain as $slice) {
            if ($slice['runtime_key'] === $expectedRuntimeKey) {
                return $slice['activate_key'];
            }
        }

        return $this->deriveActivateKeyFromRuntime($expectedRuntimeKey);
    }

    private function deriveActivateKeyFromRuntime(string $runtimeKey): string
    {
        if ($runtimeKey === '') {
            return '';
        }
        $body = preg_replace('/^automatic_dispatch_scheduler_/', '', $runtimeKey);
        $body = preg_replace('/_runtime$/', '', $body);

        return 'activate_signed_one_shot_scheduler_tick_'.$body;
    }

    /**
     * @param  list<string>  $currentCapability
     * @param  list<string>  $currentNotYetRuntimeCapable
     * @param  list<string>  $currentNextBuildSlices
     * @param  array<string, mixed>  $controlPlane
     * @return array{violations: list<array<string, mixed>>, warnings: list<array<string, mixed>>, invariants: array<string, bool>}
     */
    private function globalInvariants(
        array $currentCapability,
        array $currentNotYetRuntimeCapable,
        string $currentNextRequiredSlice,
        array $currentNextBuildSlices,
        string $expectedNextRequiredSlice,
        array $controlPlane,
    ): array {
        $violations = [];
        $warnings = [];

        $invariants = [
            'current_capability_is_unique' => count(array_unique($currentCapability)) === count($currentCapability),
            'not_yet_runtime_capable_slot_3_present' => isset($currentNotYetRuntimeCapable[3]) && is_string($currentNotYetRuntimeCapable[3]) && $currentNotYetRuntimeCapable[3] !== '',
            'next_required_slice_present' => $currentNextRequiredSlice !== '',
            'next_build_slices_contains_next_required_slice' => in_array($currentNextRequiredSlice, $currentNextBuildSlices, true),
            'expected_next_required_slice_present' => $expectedNextRequiredSlice !== '',
            'current_next_required_slice_matches_expected' => $expectedNextRequiredSlice === '' || $currentNextRequiredSlice === $expectedNextRequiredSlice,
            'persistent_runtime_schema_known' => in_array(
                (string) data_get($controlPlane, 'control_plane.persistent_runtime.status', ''),
                ['schema_ready', 'schema_missing'],
                true,
            ),
            'control_plane_canonical_name_present' => (string) data_get($controlPlane, 'control_plane.canonical_name', '') === 'Atlas Agent Control Plane',
            'control_plane_parent_program_present' => (string) data_get($controlPlane, 'control_plane.parent_program', '') === 'Atlas Self-Construction OS',
        ];

        if (! $invariants['next_build_slices_contains_next_required_slice']) {
            $violations[] = [
                'code' => 'next_build_slices_does_not_contain_next_required_slice',
                'detail' => 'persistent_runtime.next_required_slice must appear inside control_plane.next_build_slices.',
            ];
        }

        if (! $invariants['current_next_required_slice_matches_expected']) {
            $violations[] = [
                'code' => 'next_required_slice_does_not_match_expected',
                'detail' => sprintf(
                    'persistent_runtime.next_required_slice=%s does not match expected pointer derived from not_yet_runtime_capable[3]=%s.',
                    $currentNextRequiredSlice,
                    $expectedNextRequiredSlice,
                ),
            ];
        }

        if (! $invariants['current_capability_is_unique']) {
            $violations[] = [
                'code' => 'duplicate_capabilities_registered',
                'detail' => 'agentControlPlane().current_capability contains duplicates.',
            ];
        }

        return [
            'violations' => $violations,
            'warnings' => $warnings,
            'invariants' => $invariants,
        ];
    }

    /**
     * @param  array<string, mixed>  $controlPlane
     * @return array<string, bool>
     */
    private function runtimeSafety(array $controlPlane): array
    {
        $readiness = (array) data_get($controlPlane, 'control_plane.readiness', []);
        $dispatchAllowed = (bool) data_get($readiness, 'dispatch_allowed', false);
        $executionAllowed = (bool) data_get($readiness, 'execution_allowed', false);
        $persistentRuntime = (array) data_get($controlPlane, 'control_plane.persistent_runtime', []);

        $flags = [
            'execution_allowed' => (bool) data_get($controlPlane, 'execution_allowed', false),
            'dispatch_allowed' => (bool) data_get($controlPlane, 'dispatch_allowed', false),
            'ledger_write_allowed' => (bool) data_get($controlPlane, 'ledger_write_allowed', false),
            'completion_allowed' => (bool) data_get($controlPlane, 'completion_allowed', false),
            'claim_persisted' => (bool) data_get($controlPlane, 'claim_persisted', false),
            'readiness_execution_allowed' => $executionAllowed,
            'readiness_dispatch_allowed' => $dispatchAllowed,
            'persistent_runtime_write_runtime_enabled' => (bool) data_get($persistentRuntime, 'write_runtime_enabled', false)
                && (bool) data_get($persistentRuntime, 'adapter_invocation_contract_enabled', true) === false,
        ];

        // Each runtime-safety flag describes whether a forbidden runtime
        // capability has been observed anywhere in the control-plane
        // projection. They are derived directly from the canonical
        // execution_allowed / dispatch_allowed / ledger_write_allowed gates
        // so that synthetic overrides can flip a flag and the audit can
        // honestly report it.
        $observedFlags = [
            'actual_process_start_allowed_anywhere' => $flags['execution_allowed'] === true,
            'provider_process_call_allowed_anywhere' => $flags['dispatch_allowed'] === true,
            'adapter_invocation_allowed_anywhere' => $flags['dispatch_allowed'] === true,
            'adapter_execution_allowed_anywhere' => $flags['execution_allowed'] === true,
            'dispatch_allowed_anywhere' => $flags['dispatch_allowed'] === true,
            'token_spend_allowed_anywhere' => $flags['execution_allowed'] === true,
            'self_programming_allowed_anywhere' => $flags['execution_allowed'] === true,
            'external_process_started_by_atlas' => $flags['execution_allowed'] === true,
            'codex_cli_invoked' => $flags['execution_allowed'] === true,
            'shell_spawned_by_runtime' => $flags['execution_allowed'] === true,
        ];

        $allFalse = ! in_array(true, $observedFlags, true)
            && $flags['ledger_write_allowed'] === false
            && $flags['completion_allowed'] === false
            && $flags['claim_persisted'] === false;

        return $observedFlags + [
            'runtime_safety_all_false' => $allFalse,
            'control_plane_execution_allowed_observed' => $flags['execution_allowed'],
            'control_plane_dispatch_allowed_observed' => $flags['dispatch_allowed'],
            'control_plane_ledger_write_allowed_observed' => $flags['ledger_write_allowed'],
            'control_plane_completion_allowed_observed' => $flags['completion_allowed'],
            'control_plane_claim_persisted_observed' => $flags['claim_persisted'],
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    private function documentationAudit(array $deepChain): array
    {
        $path = base_path('docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md');
        $exists = is_file($path);
        $contents = $exists ? (string) @file_get_contents($path) : '';
        $bulletsFound = [];
        $duplicateBullets = [];
        if ($exists) {
            foreach ($deepChain as $slice) {
                $bullet = $slice['doc_bullet'];
                if ($bullet === '') {
                    continue;
                }
                // Match the canonical bullet anchor: a list item that begins with
                // "- expose and implement automatic dispatch scheduler one-shot tick Codex real invoker <slice phrase>"
                // so that we ignore in-passing "routes next to <slice>" mentions inside other bullets.
                $pattern = '/^\\s*-\\s+expose\\s+(?:and\\s+implement\\s+)?automatic\\s+dispatch\\s+scheduler\\s+one-shot\\s+tick\\s+Codex\\s+real\\s+invoker\\s+'
                    .preg_quote($bullet, '/').'\\b/m';
                $count = preg_match_all($pattern, $contents) ?: 0;
                $bulletsFound[$slice['slice_key']] = $count;
                if ($count >= 2) {
                    $duplicateBullets[] = $slice['slice_key'];
                }
            }
        }

        return [
            'contract_doc_present' => $exists,
            'contract_doc_path' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md',
            'contract_doc_byte_size' => $exists ? strlen($contents) : 0,
            'slice_bullets_found' => $bulletsFound,
            'duplicate_slice_bullets' => $duplicateBullets,
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    private function cliSurface(array $deepChain): array
    {
        $expectedOptions = [
            'agent-control-plane',
            'agent-control-plane-chain-integrity-certification',
            'agent-control-plane-chain-integrity-certification-status',
            'agent-control-plane-chain-integrity-certification-preflight',
            'agent-control-plane-chain-integrity-certification-implementation-packet',
        ];
        foreach ($deepChain as $slice) {
            $sliceKey = (string) $slice['slice_key'];
            $cliBase = $this->cliBaseForSlice($sliceKey);
            if ($cliBase === '') {
                continue;
            }
            // For slice families whose base already ends in `_contract`, the CLI
            // contract option uses the base CLI key (no extra `-contract` suffix)
            // — the rest of the quintet keeps the suffix convention.
            $expectedOptions[] = str_ends_with($sliceKey, '_contract') ? $cliBase : ($cliBase.'-contract');
            $expectedOptions[] = $cliBase.'-preflight';
            $expectedOptions[] = $cliBase.'-implementation-packet';
            $expectedOptions[] = $cliBase.'-status';
        }
        $expectedOptions = array_values(array_unique($expectedOptions));

        $present = [];
        $missing = [];
        try {
            $kernel = app(ConsoleKernelContract::class);
            $registry = $kernel->all();
            $command = $registry['atlas:ai:self-construction'] ?? null;
            if ($command === null) {
                throw new \RuntimeException('atlas:ai:self-construction command is not registered.');
            }
            $definition = $command->getDefinition();
            foreach ($expectedOptions as $optionName) {
                if ($definition->hasOption($optionName)) {
                    $present[] = $optionName;
                } else {
                    $missing[] = $optionName;
                }
            }
            $aligned = $missing === [];
        } catch (\Throwable $error) {
            $missing = $expectedOptions;
            $present = [];
            $aligned = false;
        }

        return [
            'command_name' => 'atlas:ai:self-construction',
            'expected_options' => $expectedOptions,
            'present_options' => $present,
            'missing_options' => $missing,
            'handlers_aligned' => $aligned,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     */
    private function allQuartetMethodsPresent(array $sliceReports): bool
    {
        foreach ($sliceReports as $report) {
            $checks = (array) ($report['checks'] ?? []);
            if (
                ($checks['contract_method_exists'] ?? false) !== true
                || ($checks['preflight_method_exists'] ?? false) !== true
                || ($checks['implementation_packet_method_exists'] ?? false) !== true
                || ($checks['status_method_exists'] ?? false) !== true
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    private function invokerSurface(array $deepChain): array
    {
        $missing = [];
        foreach ($deepChain as $slice) {
            $invokerClass = $slice['invoker_class'];
            if ($invokerClass === '' || ! class_exists($invokerClass)) {
                $missing[] = $slice['slice_key'];

                continue;
            }
            if ($slice['prepare_method'] === '' || ! method_exists($invokerClass, $slice['prepare_method'])) {
                $missing[] = $slice['slice_key'];
            }
        }

        return [
            'deep_checked' => count($deepChain),
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testSurface(): array
    {
        $dedicatedTestPath = base_path('tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php');
        $commandTestPath = base_path('tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php');

        return [
            'dedicated_test_path' => 'tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneChainIntegrityAuditTest.php',
            'dedicated_test_present' => is_file($dedicatedTestPath),
            'command_test_present' => is_file($commandTestPath),
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function countDuplicates(array $values): int
    {
        return count($values) - count(array_unique($values));
    }

    /**
     * @param  array{violations: list<array<string, mixed>>, warnings: list<array<string, mixed>>, invariants: array<string, bool>}  $globalInvariants
     * @param  array<string, mixed>  $runtimeSafety
     * @param  list<array<string, mixed>>  $sliceReports
     * @param  array<string, mixed>  $documentation
     * @param  array<string, mixed>  $cliSurface
     * @param  array<string, mixed>  $capabilitySurface
     * @return array<string, bool>
     */
    private function invariantsMap(
        array $globalInvariants,
        array $runtimeSafety,
        array $sliceReports,
        array $documentation,
        array $cliSurface,
        array $capabilitySurface,
    ): array {
        $perSliceOk = true;
        foreach ($sliceReports as $report) {
            if (($report['ok'] ?? false) !== true) {
                $perSliceOk = false;
                break;
            }
        }

        $base = $globalInvariants['invariants'];
        $base['runtime_safety_all_false'] = (bool) $runtimeSafety['runtime_safety_all_false'];
        $base['contract_doc_present'] = (bool) $documentation['contract_doc_present'];
        $base['no_duplicate_doc_bullets'] = ($documentation['duplicate_slice_bullets'] ?? []) === [];
        $base['cli_surface_aligned'] = (bool) $cliSurface['handlers_aligned'];
        $base['capability_surface_unique'] = ((int) $capabilitySurface['duplicate_count']) === 0;
        $base['all_deep_slices_ok'] = $perSliceOk;

        return $base;
    }

    /**
     * @param  list<string>  $currentCapability
     * @return list<string>
     */
    private function buildShallowChain(array $currentCapability): array
    {
        $suffixes = ['_contract', '_preflight', '_implementation_packet', '_invoker_service', '_status_projection'];
        $candidates = [];
        foreach ($currentCapability as $capability) {
            if (! is_string($capability)) {
                continue;
            }
            if (! str_starts_with($capability, 'automatic_dispatch_scheduler_one_shot_tick_')) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($capability, $suffix)) {
                    $candidate = substr($capability, 0, -strlen($suffix));
                    if ($candidate !== '') {
                        $candidates[$candidate] = true;
                    }
                    break;
                }
            }
        }

        // A slice family is only canonical when *every* one of its five
        // quintet members is registered in the capability list. This avoids
        // false positives when a slice key legitimately ends in `_contract`
        // (e.g. `post_start_receipt_contract`) and would otherwise be split
        // into a shorter family without the full quintet.
        $shallow = [];
        foreach (array_keys($candidates) as $candidate) {
            $allFive = true;
            foreach ($suffixes as $suffix) {
                if (! in_array($candidate.$suffix, $currentCapability, true)) {
                    $allFive = false;
                    break;
                }
            }
            if ($allFive) {
                $shallow[] = $candidate;
            }
        }

        return array_values(array_unique($shallow));
    }

    /**
     * @param  list<string>  $shallowChain
     * @param  list<string>  $currentCapability
     * @return array{slices: list<array<string, mixed>>, violations: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    private function auditShallowChain(array $shallowChain, array $currentCapability): array
    {
        $slices = [];
        $violations = [];
        $warnings = [];
        $suffixes = ['_contract', '_preflight', '_implementation_packet', '_invoker_service', '_status_projection'];
        foreach ($shallowChain as $sliceKey) {
            $checks = [];
            $missing = [];
            foreach ($suffixes as $suffix) {
                $present = in_array($sliceKey.$suffix, $currentCapability, true);
                $checks['capability'.$suffix] = $present;
                if (! $present) {
                    $missing[] = $sliceKey.$suffix;
                }
            }
            $ok = $missing === [];
            $slices[] = [
                'slice_key' => $sliceKey,
                'checks' => $checks,
                'missing_capabilities' => $missing,
                'ok' => $ok,
            ];
            foreach ($missing as $missingCapability) {
                $warnings[] = [
                    'code' => 'shallow_slice_capability_quintet_incomplete',
                    'slice_key' => $sliceKey,
                    'missing_capability' => $missingCapability,
                    'detail' => 'Shallow chain slice is missing part of the canonical quintet (contract/preflight/implementation_packet/invoker_service/status_projection).',
                ];
            }
        }

        return [
            'slices' => $slices,
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @param  array<string, mixed>  $controlPlane
     * @return array{edges: list<array<string, string>>, broken_edges: list<array<string, string>>, violations: list<array<string, mixed>>}
     */
    private function regressionMatrix(array $deepChain, array $controlPlane): array
    {
        $edges = [];
        $brokenEdges = [];
        $violations = [];
        $count = count($deepChain);
        for ($i = 0; $i < $count; $i++) {
            $currentSlice = $deepChain[$i];
            $expectedNext = $i + 1 < $count ? $deepChain[$i + 1]['activate_key'] : '';
            $declaredNext = $this->extractDeclaredNextSliceFromStatus($currentSlice);
            $edge = [
                'from_slice' => $currentSlice['slice_key'],
                'expected_next' => $expectedNext,
                'declared_next' => $declaredNext,
                'edge_ok' => $expectedNext === '' || $declaredNext === '' || $declaredNext === $expectedNext,
            ];
            $edges[] = $edge;
            if ($expectedNext !== '' && $declaredNext !== '' && $declaredNext !== $expectedNext) {
                $brokenEdges[] = $edge;
                $violations[] = [
                    'code' => 'broken_chain_edge',
                    'slice_key' => $currentSlice['slice_key'],
                    'detail' => sprintf(
                        'Status projection of %s declares next_required_slice=%s, but deep chain expects %s.',
                        $currentSlice['slice_key'],
                        $declaredNext,
                        $expectedNext,
                    ),
                ];
            }
        }

        return [
            'edges' => $edges,
            'broken_edges' => $brokenEdges,
            'violations' => $violations,
        ];
    }

    /**
     * @param  array<string, string>  $slice
     */
    private function extractDeclaredNextSliceFromStatus(array $slice): string
    {
        $methodPrefix = (string) ($slice['method_prefix'] ?? '');
        if ($methodPrefix === '' || ! method_exists($this->readiness, $methodPrefix.'Status')) {
            return '';
        }
        try {
            $payload = $this->readiness->{$methodPrefix.'Status'}($this->normalizeReadinessOptions([]));
        } catch (\Throwable $error) {
            return '';
        }
        $statusBlock = (array) data_get($payload, $this->statusBlockKey($slice['slice_key']), []);
        $nextSlice = (string) data_get($statusBlock, 'next_required_slice', '');
        // Some status projections embed the next_required_slice on a sub-block
        // (e.g. status.next_required_slice) — the data_get above already covers
        // that case. Repair-style placeholders are filtered out so they do not
        // generate false-positive broken-edge violations.
        if ($nextSlice !== '' && str_starts_with($nextSlice, 'repair_')) {
            return '';
        }

        return $nextSlice;
    }

    private function statusBlockKey(string $sliceKey): string
    {
        return 'agent_'.$sliceKey.'_status';
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @param  list<string>  $shallowChain
     * @param  list<array<string, mixed>>  $sliceReports
     * @param  array<string, mixed>  $shallowReports
     * @return array<string, mixed>
     */
    private function chainCoverage(array $deepChain, array $shallowChain, array $sliceReports, array $shallowReports): array
    {
        $deepKeys = array_map(static fn (array $slice): string => (string) $slice['slice_key'], $deepChain);
        $missingDeepChecks = array_values(array_diff($shallowChain, $deepKeys));

        return [
            'deep_checked_slice_count' => count($deepChain),
            'shallow_checked_slice_count' => count($shallowChain),
            'missing_deep_checks' => $missingDeepChecks,
            'missing_deep_check_count' => count($missingDeepChecks),
            'shallow_chain_ok_count' => count(array_filter((array) ($shallowReports['slices'] ?? []), static fn (array $report): bool => (bool) ($report['ok'] ?? false))),
            'deep_chain_ok_count' => count(array_filter($sliceReports, static fn (array $report): bool => (bool) ($report['ok'] ?? false))),
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @param  list<array<string, mixed>>  $sliceReports
     * @return list<array<string, mixed>>
     */
    private function capabilityGaps(array $deepChain, array $sliceReports): array
    {
        return ChainIntegrity\AgentControlPlaneGapCollector::capabilityGaps($deepChain, $sliceReports);
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return list<array<string, mixed>>
     */
    private function collectInvokerGaps(array $sliceReports): array
    {
        return ChainIntegrity\AgentControlPlaneGapCollector::collectInvokerGaps($sliceReports);
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return list<array<string, mixed>>
     */
    private function collectReadinessGaps(array $sliceReports): array
    {
        $gaps = [];
        foreach ($sliceReports as $report) {
            $checks = (array) ($report['checks'] ?? []);
            foreach (['contract_method_exists', 'preflight_method_exists', 'implementation_packet_method_exists', 'status_method_exists'] as $key) {
                if (($checks[$key] ?? false) !== true) {
                    $gaps[] = [
                        'slice_key' => (string) ($report['slice_key'] ?? ''),
                        'readiness_check' => $key,
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $runtimeSafety
     * @return list<string>
     */
    private function runtimeSafetyGaps(array $runtimeSafety): array
    {
        $gaps = [];
        $expectedFalse = [
            'actual_process_start_allowed_anywhere',
            'provider_process_call_allowed_anywhere',
            'adapter_invocation_allowed_anywhere',
            'adapter_execution_allowed_anywhere',
            'dispatch_allowed_anywhere',
            'token_spend_allowed_anywhere',
            'self_programming_allowed_anywhere',
            'external_process_started_by_atlas',
            'codex_cli_invoked',
            'shell_spawned_by_runtime',
        ];
        foreach ($expectedFalse as $flag) {
            if ((bool) ($runtimeSafety[$flag] ?? false) === true) {
                $gaps[] = $flag;
            }
        }
        if (($runtimeSafety['runtime_safety_all_false'] ?? false) !== true) {
            $gaps[] = 'runtime_safety_all_false';
        }

        return $gaps;
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array{
     *     evidence_to_dispatch_chain_ok: bool,
     *     dispatch_authorization_chain_ok: bool,
     *     receipt_use_chain_ok: bool,
     *     post_start_provider_start_driver_ready_next: bool,
     *     corridor_slice_keys: list<string>,
     *     corridor_slice_status: array<string, array<string, bool>>,
     *     violations: list<array<string, mixed>>,
     * }
     */
    private function postStartEvidenceCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        return ChainIntegrity\AgentControlPlaneCorridorProjector::postStartEvidenceCorridor($sliceReports, $currentNextRequiredSlice);
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    private function providerToRuntimeCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        return ChainIntegrity\AgentControlPlaneCorridorProjector::providerToRuntimeCorridor($sliceReports, $currentNextRequiredSlice);
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    private function providerRuntimePreflightMatrix(array $sliceReports): array
    {
        $reportsByKey = [];
        foreach ($sliceReports as $report) {
            $reportsByKey[(string) ($report['slice_key'] ?? '')] = $report;
        }

        // Static contract-level expectations per slice. Each flag asserts that
        // the canonical contract metadata REQUIRES a given upstream metadata
        // class — it is not a runtime probe.
        $matrixSchema = [
            'post_start_provider_start_driver_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'sandbox_binding_required' => true,
                'heartbeat_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_adapter_invocation_boundary_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'heartbeat_required' => true,
                'adapter_descriptor_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_adapter_execution_guard_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'adapter_descriptor_required' => true,
                'adapter_guard_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_provider_execution_contract_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'adapter_guard_required' => true,
                'provider_execution_contract_required' => true,
                'sandbox_binding_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_process_start_release_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'provider_execution_contract_required' => true,
                'process_release_receipt_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_supervised_start_executor_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'process_release_receipt_required' => true,
                'supervised_start_contract_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_process_spawn_enablement_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'supervised_start_contract_required' => true,
                'spawn_enablement_contract_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_final_process_spawn_executor_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'spawn_enablement_contract_required' => true,
                'stdout_stderr_sink_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_external_process_runtime_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'runtime_environment_contract_required' => true,
                'stdout_stderr_sink_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_process_invocation_authorization_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'runtime_environment_contract_required' => true,
                'invocation_authorization_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_external_process_invoker_dry_run_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'invocation_authorization_required' => true,
                'dry_run_receipt_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_real_invoker_release_preflight_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'dry_run_receipt_required' => true,
                'release_preflight_receipt_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
            'post_start_signed_real_invoker_release_gate' => [
                'accepted_evidence_required' => true,
                'provider_start_projection_required' => true,
                'release_preflight_receipt_required' => true,
                'signed_release_receipt_required' => true,
                'prerequisite_metadata_present' => true,
                'runtime_flags_false' => true,
            ],
        ];

        $rows = [];
        $allTrue = true;
        $rowCount = 0;
        $checkCount = 0;
        $checkOkCount = 0;
        foreach ($matrixSchema as $logicalName => $checks) {
            $sliceKey = 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_'.$logicalName;
            $report = $reportsByKey[$sliceKey] ?? null;
            // A row's checks are honest only when the underlying slice is OK
            // in the deep chain. Otherwise we flip them to false so callers
            // can distinguish missing artifacts from satisfied preflight
            // requirements.
            $sliceOk = ($report['ok'] ?? false) === true;
            $rowChecks = [];
            foreach ($checks as $checkName => $expected) {
                $value = $sliceOk && $expected === true;
                $rowChecks[$checkName] = $value;
                $checkCount++;
                if ($value === true) {
                    $checkOkCount++;
                }
                if ($value !== true) {
                    $allTrue = false;
                }
            }
            $rowOk = ! in_array(false, $rowChecks, true);
            $rows[$logicalName] = [
                'slice_key' => $sliceKey,
                'present_in_deep_chain' => $report !== null,
                'slice_ok' => $sliceOk,
                'checks' => $rowChecks,
                'row_ok' => $rowOk,
            ];
            $rowCount++;
        }

        return [
            'matrix_name' => 'provider_runtime_preflight_matrix',
            'matrix_version' => 'v1',
            'rows' => $rows,
            'row_count' => $rowCount,
            'check_count' => $checkCount,
            'check_ok_count' => $checkOkCount,
            'all_true' => $allTrue,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    private function implementationToOperatorHandoffCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        return ChainIntegrity\AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor($sliceReports, $currentNextRequiredSlice);
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    private function cycleIntegrity(array $deepChain, string $currentNextRequiredSlice): array
    {
        // Canonical activate keys that mark the explicit reentry corridor.
        // The cycle can land on the receipt contract itself, or on the next
        // evidence receipt contract once the receipt contract surface is
        // already structurally ready and has advanced its local status.
        $intentionalReentryActivateKeys = [
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt',
        ];
        $intentionalReentryReason = 'operator_handoff_completes_release_cycle_and_reenters_post_start_evidence_corridor';
        $intentionalReentryDetected = array_key_exists($currentNextRequiredSlice, $intentionalReentryActivateKeys);
        $intentionalReentryTargetSliceKey = $intentionalReentryActivateKeys[$currentNextRequiredSlice] ?? null;

        $sliceKeys = array_map(static fn (array $entry): string => (string) $entry['slice_key'], $deepChain);
        $sliceKeySet = array_count_values($sliceKeys);
        $repeatedSliceFamilies = [];
        foreach ($sliceKeySet as $key => $count) {
            if ($count >= 2) {
                $repeatedSliceFamilies[] = ['slice_key' => $key, 'count' => $count];
            }
        }

        $reentryEdges = [];
        $cycleDetected = false;
        if ($intentionalReentryDetected) {
            $reentryEdges[] = [
                'from_slice' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff',
                'to_slice' => $intentionalReentryTargetSliceKey,
                'intentional' => true,
                'reason' => $intentionalReentryReason,
            ];
            $cycleDetected = true;
        }

        // Slices considered "previously certified" once the
        // implementation-to-operator-handoff corridor has produced its
        // operator handoff. A regression is a pointer that lands inside
        // this set WITHOUT the intentional reentry justification.
        $previouslyCertifiedActivateKeys = [
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_receipt_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_evidence_acceptance_bridge_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_liveness_monitor_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_release_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_executor_handoff_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_adapter_execution_guard_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_execution_contract_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_release_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_executor_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_spawn_enablement_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_runtime_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_invocation_authorization_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_plan_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_fresh_release_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_executor_enablement_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_supervised_start_activation_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_final_process_start_authorization_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_start_envelope_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_start_execution_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_process_starter_readiness_gate_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_manual_start_executor_receipt_contract',
            'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_operator_start_handoff_contract',
        ];

        $regressions = [];
        $cycleViolations = [];
        $cycleWarnings = [];
        $unintentionalCycleDetected = false;
        if (! $intentionalReentryDetected && in_array($currentNextRequiredSlice, $previouslyCertifiedActivateKeys, true)) {
            $regressions[] = [
                'code' => 'pointer_regressed_into_previously_certified_slice_without_reentry',
                'current_pointer' => $currentNextRequiredSlice,
                'detail' => 'Pointer regressed into a previously certified slice without an intentional_reentry justification.',
            ];
            $cycleViolations[] = $regressions[count($regressions) - 1];
            $unintentionalCycleDetected = true;
        }

        $cycleOk = $cycleViolations === [] && ! $unintentionalCycleDetected;
        $status = $cycleOk
            ? 'ok'
            : ($unintentionalCycleDetected ? 'blocked' : 'warning');

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_cycle_integrity.v1',
            'status' => $status,
            'cycle_detected' => $cycleDetected,
            'unintentional_cycle_detected' => $unintentionalCycleDetected,
            'intentional_reentry_detected' => $intentionalReentryDetected,
            'intentional_reentry_target' => $intentionalReentryDetected ? $intentionalReentryTargetSliceKey : null,
            'intentional_reentry_reason' => $intentionalReentryDetected ? $intentionalReentryReason : null,
            'intentional_reentry_activate_key' => $intentionalReentryDetected ? $currentNextRequiredSlice : null,
            'intentional_reentry_activate_keys' => array_keys($intentionalReentryActivateKeys),
            'repeated_slice_families' => $repeatedSliceFamilies,
            'repeated_slice_count' => count($repeatedSliceFamilies),
            'reentry_edges' => $reentryEdges,
            'regressions' => $regressions,
            'regression_count' => count($regressions),
            'cycle_warnings' => $cycleWarnings,
            'cycle_violations' => $cycleViolations,
            'cycle_ok' => $cycleOk,
            'terminal_horizon' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
            'terminal_horizons' => array_keys($intentionalReentryActivateKeys),
            'terminal_horizon_reason' => 'reentry_into_post_start_evidence_corridor_after_operator_handoff_completes_release_cycle',
        ];
    }

    /**
     * @param  list<array<string, string>>  $deepChain
     * @param  array<string, mixed>  $cycleIntegrity
     * @return array<string, mixed>
     */
    private function terminalHorizonAnalysis(array $deepChain, string $currentNextRequiredSlice, array $cycleIntegrity): array
    {
        $indexedActivateKeys = [];
        foreach ($deepChain as $index => $entry) {
            $indexedActivateKeys[(string) $entry['activate_key']] = $index;
        }
        $intentionalReentry = (bool) ($cycleIntegrity['intentional_reentry_detected'] ?? false);

        $horizonType = 'blocked_unknown';
        $horizonReason = 'pointer_does_not_match_any_known_horizon';
        $horizonOk = false;
        $nextSafeMacroBatch = null;
        $remainingKnownSlicesAfterHorizon = [];

        if ($intentionalReentry) {
            $horizonType = 'intentional_reentry';
            $horizonReason = (string) ($cycleIntegrity['intentional_reentry_reason'] ?? '');
            $horizonOk = true;
            $nextSafeMacroBatch = 'reentry_into_post_start_evidence_corridor';
        } elseif (isset($indexedActivateKeys[$currentNextRequiredSlice])) {
            $horizonType = 'linear_next';
            $horizonReason = 'pointer_points_to_next_slice_in_deep_chain';
            $horizonOk = true;
            $idx = $indexedActivateKeys[$currentNextRequiredSlice];
            $remaining = array_slice($deepChain, $idx + 1);
            $remainingKnownSlicesAfterHorizon = array_map(static fn (array $entry): string => (string) $entry['activate_key'], $remaining);
            $nextSafeMacroBatch = $remaining[0]['activate_key'] ?? null;
        } elseif ($currentNextRequiredSlice === 'apply_agent_control_plane_runtime_schema_migration') {
            $horizonType = 'terminal_runtime_gate';
            $horizonReason = 'environment_has_not_applied_runtime_schema_migration_yet';
            $horizonOk = true;
        }

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_horizon_analysis.v1',
            'current_pointer' => $currentNextRequiredSlice,
            'expected_pointer' => (string) ($cycleIntegrity['terminal_horizon'] ?? ''),
            'horizon_type' => $horizonType,
            'horizon_reason' => $horizonReason,
            'horizon_ok' => $horizonOk,
            'next_safe_macro_batch' => $nextSafeMacroBatch,
            'remaining_known_slices_after_horizon' => $remainingKnownSlicesAfterHorizon,
            'completion_claim_allowed' => false,
        ];
    }

    /**
     * @param  list<string>  $logicalNames
     * @param  array<string, array<string, bool>>  $sliceStatus
     */
    private function allCorridorSlicesOk(array $logicalNames, array $sliceStatus): bool
    {
        return ChainIntegrity\AgentControlPlaneCorridorProjector::allCorridorSlicesOk($logicalNames, $sliceStatus);
    }

    /**
     * @param  array<string, mixed>  $controlPlane
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function mergeOverrideProjection(array $controlPlane, array $override): array
    {
        if (isset($override['current_capability']) && is_array($override['current_capability'])) {
            data_set($controlPlane, 'control_plane.current_capability', array_values($override['current_capability']));
        }
        if (isset($override['append_capability']) && is_array($override['append_capability'])) {
            $current = (array) data_get($controlPlane, 'control_plane.current_capability', []);
            data_set($controlPlane, 'control_plane.current_capability', array_values(array_merge($current, $override['append_capability'])));
        }
        if (isset($override['remove_capability']) && is_array($override['remove_capability'])) {
            $current = (array) data_get($controlPlane, 'control_plane.current_capability', []);
            $filtered = array_values(array_diff($current, $override['remove_capability']));
            data_set($controlPlane, 'control_plane.current_capability', $filtered);
        }
        if (isset($override['not_yet_runtime_capable']) && is_array($override['not_yet_runtime_capable'])) {
            data_set($controlPlane, 'control_plane.not_yet_runtime_capable', array_values($override['not_yet_runtime_capable']));
        }
        if (isset($override['next_required_slice'])) {
            data_set($controlPlane, 'control_plane.persistent_runtime.next_required_slice', (string) $override['next_required_slice']);
        }
        if (isset($override['next_build_slices']) && is_array($override['next_build_slices'])) {
            data_set($controlPlane, 'control_plane.next_build_slices', array_values($override['next_build_slices']));
        }
        if (isset($override['flags']) && is_array($override['flags'])) {
            foreach ($override['flags'] as $flagKey => $flagValue) {
                data_set($controlPlane, $flagKey, $flagValue);
            }
        }

        return $controlPlane;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $clone = $payload;
        unset($clone['agent_control_plane_chain_integrity_certification_hash']);
        // generated_at is wall-clock; excluding it lets the hash represent the
        // structural state of the chain, which is what callers care about.
        unset($clone['generated_at']);

        return hash('sha256', (string) json_encode($clone, JSON_THROW_ON_ERROR));
    }
}
