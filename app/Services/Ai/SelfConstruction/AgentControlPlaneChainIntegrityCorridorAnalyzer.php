<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ChainIntegrity\AgentControlPlaneCorridorProjector;

/**
 * CORRIDOR ANALYSER concern, extracted from the god-class
 * {@see AgentControlPlaneChainIntegrityAuditService}.
 *
 * Owns the dispatch/runtime corridor shape computations: postStartEvidenceCorridor,
 * providerToRuntimeCorridor, providerRuntimePreflightMatrix,
 * implementationToOperatorHandoffCorridor and the allCorridorSlicesOk helper.
 *
 * Four of the methods are thin object-oriented seams over the existing
 * AgentControlPlaneCorridorProjector::static() corridor projections; the fifth
 * (providerRuntimePreflightMatrix) owns the full per-slice preflight matrix
 * logic so the audit service can stay focused on the higher-level chain audit.
 */
final class AgentControlPlaneChainIntegrityCorridorAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @param  string  $currentNextRequiredSlice
     * @return array<string, mixed>
     */
    public function postStartEvidenceCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        return AgentControlPlaneCorridorProjector::postStartEvidenceCorridor($sliceReports, $currentNextRequiredSlice);
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    public function providerToRuntimeCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        return AgentControlPlaneCorridorProjector::providerToRuntimeCorridor($sliceReports, $currentNextRequiredSlice);
    }

    /**
     * @param  list<array<string, mixed>>  $sliceReports
     * @return array<string, mixed>
     */
    public function providerRuntimePreflightMatrix(array $sliceReports): array
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
    public function implementationToOperatorHandoffCorridor(array $sliceReports, string $currentNextRequiredSlice): array
    {
        return AgentControlPlaneCorridorProjector::implementationToOperatorHandoffCorridor($sliceReports, $currentNextRequiredSlice);
    }

    /**
     * @param  list<string>  $logicalNames
     * @param  array<string, array<string, bool>>  $sliceStatus
     */
    public function allCorridorSlicesOk(array $logicalNames, array $sliceStatus): bool
    {
        return AgentControlPlaneCorridorProjector::allCorridorSlicesOk($logicalNames, $sliceStatus);
    }
}
