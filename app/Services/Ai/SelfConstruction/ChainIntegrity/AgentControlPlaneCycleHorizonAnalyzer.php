<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ChainIntegrity;

/**
 * Projects cycle integrity and terminal horizon analysis from the deep chain
 * and the current next-required-slice pointer.
 *
 * Extracted from AgentControlPlaneChainIntegrityAuditService to reduce the
 * god-class. All methods are pure — no instance state.
 */
final class AgentControlPlaneCycleHorizonAnalyzer
{
    /**
     * @param  list<array<string, string>>  $deepChain
     * @return array<string, mixed>
     */
    public static function cycleIntegrity(array $deepChain, string $currentNextRequiredSlice): array
    {
        // Canonical activate keys that mark the explicit reentry corridor.
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
    public static function terminalHorizonAnalysis(array $deepChain, string $currentNextRequiredSlice, array $cycleIntegrity): array
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
}
