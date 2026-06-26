<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ChainIntegrity;

/**
 * Pure corridor projections for the Agent Control Plane chain integrity audit.
 *
 * Extracted from AgentControlPlaneChainIntegrityAuditService to reduce the
 * god-class. All methods are pure transformations over sliceReports — no
 * instance state.
 */
final class AgentControlPlaneCorridorProjector
{
    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    public static function postStartEvidenceCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        $corridor = [
            'post_start_receipt_contract' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_receipt_contract',
            'post_start_evidence_receipt' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_receipt',
            'post_start_evidence_acceptance_bridge' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_evidence_acceptance_bridge',
            'post_start_liveness_monitor' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_liveness_monitor',
            'post_start_dispatch_release_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_release_gate',
            'post_start_signed_dispatch_authorization_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_dispatch_authorization_gate',
            'post_start_dispatch_executor_handoff' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_executor_handoff',
            'post_start_dispatch_receipt_use_executor' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_dispatch_receipt_use_executor',
        ];
        $reportsByKey = [];
        foreach ($sliceReports as $report) {
            $reportsByKey[(string) ($report['slice_key'] ?? '')] = $report;
        }
        $sliceStatus = [];
        $violations = [];
        $okCount = 0;
        foreach ($corridor as $logicalName => $sliceKey) {
            $report = $reportsByKey[$sliceKey] ?? null;
            $checks = (array) ($report['checks'] ?? []);
            $ok = ($report['ok'] ?? false) === true;
            $sliceStatus[$logicalName] = [
                'slice_key' => $sliceKey,
                'present_in_deep_chain' => $report !== null,
                'all_artifacts_ok' => $ok,
                'contract_method_exists' => (bool) ($checks['contract_method_exists'] ?? false),
                'preflight_method_exists' => (bool) ($checks['preflight_method_exists'] ?? false),
                'implementation_packet_method_exists' => (bool) ($checks['implementation_packet_method_exists'] ?? false),
                'status_method_exists' => (bool) ($checks['status_method_exists'] ?? false),
                'invoker_class_exists' => (bool) ($checks['invoker_class_exists'] ?? false),
                'invoker_prepare_method_exists' => (bool) ($checks['invoker_prepare_method_exists'] ?? false),
            ];
            if ($report === null) {
                $violations[] = [
                    'code' => 'corridor_slice_missing_from_deep_chain',
                    'slice_key' => $sliceKey,
                    'detail' => 'Evidence-to-dispatch corridor slice is not present in the deep chain.',
                ];

                continue;
            }
            if ($ok) {
                $okCount++;
            }
        }

        // Sub-chain readiness flags
        $evidenceCorridorPrefix = ['post_start_receipt_contract', 'post_start_evidence_receipt', 'post_start_evidence_acceptance_bridge', 'post_start_liveness_monitor'];
        $dispatchCorridorPrefix = ['post_start_dispatch_release_gate', 'post_start_signed_dispatch_authorization_gate'];
        $receiptUseCorridorPrefix = ['post_start_dispatch_executor_handoff', 'post_start_dispatch_receipt_use_executor'];

        $evidenceOk = self::allCorridorSlicesOk($evidenceCorridorPrefix, $sliceStatus);
        $dispatchOk = self::allCorridorSlicesOk($dispatchCorridorPrefix, $sliceStatus);
        $receiptUseOk = self::allCorridorSlicesOk($receiptUseCorridorPrefix, $sliceStatus);

        $providerStartReadyNext = $evidenceOk && $dispatchOk && $receiptUseOk
            && $currentNextRequiredSlice === 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_provider_start_driver_gate_contract';

        return [
            'evidence_to_dispatch_chain_ok' => $evidenceOk,
            'dispatch_authorization_chain_ok' => $dispatchOk,
            'receipt_use_chain_ok' => $receiptUseOk,
            'post_start_provider_start_driver_ready_next' => $providerStartReadyNext,
            'corridor_slice_keys' => array_values($corridor),
            'corridor_slice_status' => $sliceStatus,
            'violations' => $violations,
            'corridor_ok_count' => $okCount,
            'corridor_total_count' => count($corridor),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    public static function providerToRuntimeCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        $corridor = [
            'post_start_provider_start_driver_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate',
            'post_start_adapter_invocation_boundary_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_invocation_boundary_gate',
            'post_start_adapter_execution_guard_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_adapter_execution_guard_gate',
            'post_start_provider_execution_contract_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_execution_contract_gate',
            'post_start_process_start_release_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_release_gate',
            'post_start_supervised_start_executor_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_executor_gate',
            'post_start_process_spawn_enablement_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_spawn_enablement_gate',
            'post_start_final_process_spawn_executor_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_spawn_executor_gate',
            'post_start_external_process_runtime_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_runtime_gate',
            'post_start_process_invocation_authorization_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_invocation_authorization_gate',
            'post_start_external_process_invoker_dry_run_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_external_process_invoker_dry_run_gate',
            'post_start_real_invoker_release_preflight_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_real_invoker_release_preflight_gate',
            'post_start_signed_real_invoker_release_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_signed_real_invoker_release_gate',
        ];
        $reportsByKey = [];
        foreach ($sliceReports as $report) {
            $reportsByKey[(string) ($report['slice_key'] ?? '')] = $report;
        }
        $sliceStatus = [];
        $violations = [];
        $okCount = 0;
        $edges = [];
        $previous = null;
        foreach ($corridor as $logicalName => $sliceKey) {
            $report = $reportsByKey[$sliceKey] ?? null;
            $checks = (array) ($report['checks'] ?? []);
            $ok = ($report['ok'] ?? false) === true;
            $sliceStatus[$logicalName] = [
                'slice_key' => $sliceKey,
                'present_in_deep_chain' => $report !== null,
                'all_artifacts_ok' => $ok,
                'contract_method_exists' => (bool) ($checks['contract_method_exists'] ?? false),
                'preflight_method_exists' => (bool) ($checks['preflight_method_exists'] ?? false),
                'implementation_packet_method_exists' => (bool) ($checks['implementation_packet_method_exists'] ?? false),
                'status_method_exists' => (bool) ($checks['status_method_exists'] ?? false),
                'invoker_class_exists' => (bool) ($checks['invoker_class_exists'] ?? false),
                'invoker_prepare_method_exists' => (bool) ($checks['invoker_prepare_method_exists'] ?? false),
            ];
            if ($previous !== null) {
                $edges[] = [
                    'from_slice' => $previous,
                    'to_slice' => $sliceKey,
                    'edge_ok' => isset($reportsByKey[$previous]) && isset($reportsByKey[$sliceKey])
                        && ($reportsByKey[$previous]['ok'] ?? false) === true
                        && ($reportsByKey[$sliceKey]['ok'] ?? false) === true,
                ];
            }
            $previous = $sliceKey;
            if ($report === null) {
                $violations[] = [
                    'code' => 'provider_to_runtime_corridor_slice_missing_from_deep_chain',
                    'slice_key' => $sliceKey,
                    'detail' => 'Provider-to-real-invoker corridor slice is not present in the deep chain.',
                ];

                continue;
            }
            if ($ok) {
                $okCount++;
            }
        }

        $providerChain = ['post_start_provider_start_driver_gate'];
        $adapterChain = ['post_start_adapter_invocation_boundary_gate', 'post_start_adapter_execution_guard_gate'];
        $providerExecChain = ['post_start_provider_execution_contract_gate'];
        $releaseChain = ['post_start_process_start_release_gate'];
        $supervisedSpawnChain = ['post_start_supervised_start_executor_gate', 'post_start_process_spawn_enablement_gate', 'post_start_final_process_spawn_executor_gate'];
        $runtimeChain = ['post_start_external_process_runtime_gate'];
        $invocationChain = ['post_start_process_invocation_authorization_gate'];
        $dryRunReleasePreflightChain = ['post_start_external_process_invoker_dry_run_gate', 'post_start_real_invoker_release_preflight_gate'];

        $providerOk = self::allCorridorSlicesOk($providerChain, $sliceStatus);
        $adapterOk = self::allCorridorSlicesOk($adapterChain, $sliceStatus);
        $providerExecutionOk = self::allCorridorSlicesOk($providerExecChain, $sliceStatus);
        $processReleaseOk = self::allCorridorSlicesOk($releaseChain, $sliceStatus);
        $supervisedSpawnOk = self::allCorridorSlicesOk($supervisedSpawnChain, $sliceStatus);
        $externalRuntimeOk = self::allCorridorSlicesOk($runtimeChain, $sliceStatus);
        $invocationAuthOk = self::allCorridorSlicesOk($invocationChain, $sliceStatus);
        $dryRunReleaseOk = self::allCorridorSlicesOk($dryRunReleasePreflightChain, $sliceStatus);
        $signedReleaseOk = self::allCorridorSlicesOk(['post_start_signed_real_invoker_release_gate'], $sliceStatus);

        $providerToRuntimeOk = $providerOk && $adapterOk && $providerExecutionOk && $processReleaseOk
            && $supervisedSpawnOk && $externalRuntimeOk && $invocationAuthOk && $dryRunReleaseOk && $signedReleaseOk;

        $signedRealReleaseReadyNext = $providerToRuntimeOk
            && $currentNextRequiredSlice === 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_implementation_boundary_gate_contract';

        $gapSummary = [
            'provider_chain_gap' => ! $providerOk,
            'adapter_chain_gap' => ! $adapterOk,
            'provider_execution_contract_chain_gap' => ! $providerExecutionOk,
            'process_start_release_chain_gap' => ! $processReleaseOk,
            'supervised_spawn_chain_gap' => ! $supervisedSpawnOk,
            'external_runtime_chain_gap' => ! $externalRuntimeOk,
            'invocation_authorization_chain_gap' => ! $invocationAuthOk,
            'dry_run_to_release_preflight_chain_gap' => ! $dryRunReleaseOk,
            'signed_real_release_chain_gap' => ! $signedReleaseOk,
        ];

        return [
            'provider_to_runtime_chain_ok' => $providerToRuntimeOk,
            'adapter_boundary_chain_ok' => $adapterOk,
            'provider_execution_contract_chain_ok' => $providerExecutionOk,
            'process_start_release_chain_ok' => $processReleaseOk,
            'supervised_spawn_chain_ok' => $supervisedSpawnOk,
            'external_runtime_chain_ok' => $externalRuntimeOk,
            'invocation_authorization_chain_ok' => $invocationAuthOk,
            'dry_run_to_release_preflight_chain_ok' => $dryRunReleaseOk,
            'signed_real_release_ready_next' => $signedRealReleaseReadyNext,
            'corridor_slice_keys' => array_values($corridor),
            'corridor_slice_status' => $sliceStatus,
            'corridor_edge_status' => $edges,
            'corridor_gap_summary' => $gapSummary,
            'violations' => $violations,
            'corridor_ok_count' => $okCount,
            'corridor_total_count' => count($corridor),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    public static function implementationToOperatorHandoffCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        $corridor = [
            'post_start_implementation_boundary_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_implementation_boundary_gate',
            'post_start_executor_plan_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_plan_gate',
            'post_start_executor_fresh_release_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_fresh_release_gate',
            'post_start_executor_enablement_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_executor_enablement_gate',
            'post_start_supervised_start_activation_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_supervised_start_activation_gate',
            'post_start_guarded_process_start_executor_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_guarded_process_start_executor_gate',
            'post_start_final_process_start_authorization_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_final_process_start_authorization_gate',
            'post_start_actual_process_start_rehearsal_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_actual_process_start_rehearsal_gate',
            'post_start_process_start_envelope_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_start_envelope_gate',
            'post_start_start_execution_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_start_execution_gate',
            'post_start_process_starter_readiness_gate' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_process_starter_readiness_gate',
            'post_start_manual_start_executor_receipt' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_manual_start_executor_receipt',
            'post_start_operator_start_handoff' => 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_operator_start_handoff',
        ];
        $reportsByKey = [];
        foreach ($sliceReports as $report) {
            $reportsByKey[(string) ($report['slice_key'] ?? '')] = $report;
        }
        $sliceStatus = [];
        $violations = [];
        $okCount = 0;
        $edges = [];
        $previous = null;
        foreach ($corridor as $logicalName => $sliceKey) {
            $report = $reportsByKey[$sliceKey] ?? null;
            $checks = (array) ($report['checks'] ?? []);
            $ok = ($report['ok'] ?? false) === true;
            $sliceStatus[$logicalName] = [
                'slice_key' => $sliceKey,
                'present_in_deep_chain' => $report !== null,
                'all_artifacts_ok' => $ok,
                'contract_method_exists' => (bool) ($checks['contract_method_exists'] ?? false),
                'preflight_method_exists' => (bool) ($checks['preflight_method_exists'] ?? false),
                'implementation_packet_method_exists' => (bool) ($checks['implementation_packet_method_exists'] ?? false),
                'status_method_exists' => (bool) ($checks['status_method_exists'] ?? false),
                'invoker_class_exists' => (bool) ($checks['invoker_class_exists'] ?? false),
                'invoker_prepare_method_exists' => (bool) ($checks['invoker_prepare_method_exists'] ?? false),
            ];
            if ($previous !== null) {
                $edges[] = [
                    'from_slice' => $previous,
                    'to_slice' => $sliceKey,
                    'edge_ok' => isset($reportsByKey[$previous]) && isset($reportsByKey[$sliceKey])
                        && ($reportsByKey[$previous]['ok'] ?? false) === true
                        && ($reportsByKey[$sliceKey]['ok'] ?? false) === true,
                ];
            }
            $previous = $sliceKey;
            if ($report === null) {
                $violations[] = [
                    'code' => 'implementation_to_operator_handoff_corridor_slice_missing_from_deep_chain',
                    'slice_key' => $sliceKey,
                    'detail' => 'Implementation-to-operator-handoff corridor slice is not present in the deep chain.',
                ];

                continue;
            }
            if ($ok) {
                $okCount++;
            }
        }

        $boundaryChain = ['post_start_implementation_boundary_gate'];
        $planChain = ['post_start_executor_plan_gate'];
        $releaseChain = ['post_start_executor_fresh_release_gate'];
        $enablementChain = ['post_start_executor_enablement_gate'];
        $supervisedChain = ['post_start_supervised_start_activation_gate'];
        $guardedChain = ['post_start_guarded_process_start_executor_gate'];
        $finalAuthChain = ['post_start_final_process_start_authorization_gate'];
        $rehearsalEnvelopeChain = ['post_start_actual_process_start_rehearsal_gate', 'post_start_process_start_envelope_gate'];
        $startExecReadinessChain = ['post_start_start_execution_gate', 'post_start_process_starter_readiness_gate'];
        $manualHandoffChain = ['post_start_manual_start_executor_receipt', 'post_start_operator_start_handoff'];

        $boundaryOk = self::allCorridorSlicesOk($boundaryChain, $sliceStatus);
        $planOk = self::allCorridorSlicesOk($planChain, $sliceStatus);
        $releaseOk = self::allCorridorSlicesOk($releaseChain, $sliceStatus);
        $enablementOk = self::allCorridorSlicesOk($enablementChain, $sliceStatus);
        $supervisedOk = self::allCorridorSlicesOk($supervisedChain, $sliceStatus);
        $guardedOk = self::allCorridorSlicesOk($guardedChain, $sliceStatus);
        $finalAuthOk = self::allCorridorSlicesOk($finalAuthChain, $sliceStatus);
        $rehearsalEnvelopeOk = self::allCorridorSlicesOk($rehearsalEnvelopeChain, $sliceStatus);
        $startExecReadinessOk = self::allCorridorSlicesOk($startExecReadinessChain, $sliceStatus);
        $manualHandoffOk = self::allCorridorSlicesOk($manualHandoffChain, $sliceStatus);

        $allOk = $boundaryOk && $planOk && $releaseOk && $enablementOk && $supervisedOk
            && $guardedOk && $finalAuthOk && $rehearsalEnvelopeOk && $startExecReadinessOk
            && $manualHandoffOk;

        // The operator handoff completes the release cycle; the next horizon
        // is an explicit intentional reentry into post_start_receipt_contract
        // so the evidence corridor can ingest the new run.
        $operatorHandoffReentryReady = $allOk
            && $currentNextRequiredSlice === 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract';

        $gapSummary = [
            'implementation_boundary_chain_gap' => ! $boundaryOk,
            'executor_plan_chain_gap' => ! $planOk,
            'executor_release_chain_gap' => ! $releaseOk,
            'executor_enablement_chain_gap' => ! $enablementOk,
            'supervised_activation_chain_gap' => ! $supervisedOk,
            'guarded_start_chain_gap' => ! $guardedOk,
            'final_authorization_chain_gap' => ! $finalAuthOk,
            'rehearsal_to_envelope_chain_gap' => ! $rehearsalEnvelopeOk,
            'start_execution_to_readiness_chain_gap' => ! $startExecReadinessOk,
            'manual_start_to_operator_handoff_chain_gap' => ! $manualHandoffOk,
        ];

        return [
            'implementation_to_operator_handoff_chain_ok' => $allOk,
            'implementation_boundary_chain_ok' => $boundaryOk,
            'executor_plan_chain_ok' => $planOk,
            'executor_release_chain_ok' => $releaseOk,
            'executor_enablement_chain_ok' => $enablementOk,
            'supervised_activation_chain_ok' => $supervisedOk,
            'guarded_start_chain_ok' => $guardedOk,
            'final_authorization_chain_ok' => $finalAuthOk,
            'rehearsal_to_envelope_chain_ok' => $rehearsalEnvelopeOk,
            'start_execution_to_readiness_chain_ok' => $startExecReadinessOk,
            'manual_start_to_operator_handoff_chain_ok' => $manualHandoffOk,
            'operator_handoff_reentry_ready' => $operatorHandoffReentryReady,
            'corridor_slice_keys' => array_values($corridor),
            'corridor_slice_status' => $sliceStatus,
            'corridor_edge_status' => $edges,
            'corridor_gap_summary' => $gapSummary,
            'violations' => $violations,
            'corridor_ok_count' => $okCount,
            'corridor_total_count' => count($corridor),
        ];
    }

    /**
     * @param  list<string>  $logicalNames
     * @param  array<string, array<string, bool>>  $sliceStatus
     */
    public static function allCorridorSlicesOk(array $logicalNames, array $sliceStatus): bool
    {
        foreach ($logicalNames as $logicalName) {
            $entry = $sliceStatus[$logicalName] ?? null;
            if ($entry === null || ($entry['all_artifacts_ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
