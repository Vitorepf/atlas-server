<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Str;

/**
 * Coverage report for the Agent Control Plane certification stack.
 * Measures slice/edge/CLI/readiness/invoker/doc/runtime-flag/scenario
 * /fuzz/command-status/proof-bundle coverage and produces a single
 * coverage_score + grade.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentControlPlaneCertificationCoverageReportService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_coverage_report.v1';

    public const MODE = 'read_only_agent_control_plane_certification_coverage_report';

    public function __construct(
        private readonly AgentControlPlaneChainIntegrityAuditService $audit,
        private readonly AgentControlPlaneDeterministicChainReplayService $replay,
        private readonly AgentControlPlaneCertificationScenarioSimulator $simulator,
        private readonly AgentControlPlaneCertificationFuzzHarness $fuzz,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function report(array $options = []): array
    {
        $skipFuzz = (bool) ($options['skip_fuzz'] ?? false);
        $skipSimulator = (bool) ($options['skip_simulator'] ?? false);

        $audit = $this->audit->audit();
        $replay = $this->replay->replay();
        $simulator = $skipSimulator ? null : $this->simulator->simulate();
        $fuzz = $skipFuzz ? null : $this->fuzz->run(['iteration_count' => 12]);

        $sliceCount = (int) data_get($audit, 'chain_length', 0);
        $sliceOk = 0;
        foreach ((array) data_get($audit, 'slices', []) as $slice) {
            if ((bool) data_get($slice, 'ok', false)) {
                $sliceOk++;
            }
        }
        $sliceCoverage = $sliceCount > 0 ? round($sliceOk / $sliceCount, 4) : 0.0;

        $edges = (array) data_get($audit, 'per_slice_next_edge', []);
        $edgeOk = 0;
        foreach ($edges as $edge) {
            if ((bool) data_get($edge, 'edge_ok', false)) {
                $edgeOk++;
            }
        }
        $edgeCoverage = count($edges) > 0 ? round($edgeOk / count($edges), 4) : 0.0;

        $cliSurface = (array) data_get($audit, 'cli_surface', []);
        $cliPresent = count((array) data_get($cliSurface, 'present_options', []));
        $cliMissing = count((array) data_get($cliSurface, 'missing_options', []));
        $cliTotal = $cliPresent + $cliMissing;
        $cliCoverage = $cliTotal > 0 ? round($cliPresent / $cliTotal, 4) : 1.0;

        $readinessSurface = (array) data_get($audit, 'readiness_surface', []);
        $readinessAllOk = (bool) data_get($readinessSurface, 'all_quartet_methods_present', false);
        $readinessGapCount = (int) data_get($readinessSurface, 'readiness_gaps_count', 0);
        $readinessCoverage = $readinessAllOk && $readinessGapCount === 0 ? 1.0 : 0.0;

        $invokerSurface = (array) data_get($audit, 'invoker_surface', []);
        $invokerOk = (int) data_get($invokerSurface, 'deep_checked', 0) - count((array) data_get($audit, 'invoker_gaps', []));
        $invokerTotal = (int) data_get($invokerSurface, 'deep_checked', 0);
        $invokerCoverage = $invokerTotal > 0 ? round(max(0, $invokerOk) / $invokerTotal, 4) : 1.0;

        $docCount = (int) array_sum(array_values((array) data_get($audit, 'documentation.slice_bullets_found', [])));
        $expectedDocCount = $sliceCount;
        $docCoverage = $expectedDocCount > 0 ? round(min($docCount, $expectedDocCount) / $expectedDocCount, 4) : 1.0;

        $runtimeFlagAllFalse = (bool) data_get($replay, 'runtime_safety.runtime_safety_all_false', false);
        $runtimeFlagCoverage = $runtimeFlagAllFalse ? 1.0 : 0.0;

        $scenarioCoverage = 0.0;
        $scenarioCount = 0;
        if ($simulator !== null) {
            $scenarioCount = (int) data_get($simulator, 'scenario_count', 0);
            $scenarioCoverage = (float) data_get($simulator, 'detection_rate', 0.0);
        }

        $fuzzCoverage = 0.0;
        $fuzzIterations = 0;
        if ($fuzz !== null) {
            $fuzzIterations = (int) data_get($fuzz, 'iteration_count', 0);
            $detected = (int) data_get($fuzz, 'detected_count', 0);
            $fuzzCoverage = $fuzzIterations > 0 ? round($detected / $fuzzIterations, 4) : 0.0;
        }

        $kernel = app(ConsoleKernel::class);
        $registry = $kernel->all();
        $statusOptionCount = 0;
        $statusFlagPresent = 0;
        $statusFlags = [
            '--agent-control-plane-chain-integrity-certification-status',
            '--agent-control-plane-deterministic-chain-replay-status',
            '--agent-control-plane-replay-snapshot-store-status',
            '--agent-control-plane-replay-diff-status',
            '--agent-control-plane-macro-sprint-promotion-gate-status',
            '--agent-control-plane-certification-baseline-status',
            '--agent-control-plane-certification-scenario-simulator-status',
            '--agent-control-plane-release-dossier-status',
            '--agent-control-plane-certification-mutation-guard-status',
            '--agent-control-plane-certification-evidence-query-status',
            '--agent-control-plane-certification-scenario-corpus-status',
            '--agent-control-plane-certification-fuzz-harness-status',
            '--agent-control-plane-multi-snapshot-comparison-status',
            '--agent-control-plane-release-dossier-exporter-status',
            '--agent-control-plane-certification-coverage-report-status',
            '--agent-control-plane-certification-status-batch-status',
            '--agent-control-plane-runtime-evidence-journal-status',
            '--agent-control-plane-execution-workspace-runtime-status',
            '--agent-control-plane-governance-approval-runtime-status',
            '--agent-control-plane-automatic-cost-import-runtime-status',
            '--agent-control-plane-automatic-work-product-collection-runtime-status',
            '--agent-control-plane-adapter-execution-runtime-boundary-status',
            '--agent-control-plane-dispatch-planner-runtime-status',
            '--agent-control-plane-validation-gate-runtime-status',
            '--agent-control-plane-merge-review-runtime-status',
            '--agent-control-plane-task-packet-queue-status',
            '--agent-control-plane-claim-lease-runtime-status',
            '--agent-control-plane-scope-lock-runtime-validator-status',
            '--agent-control-plane-task-queue-orchestrator-status',
            '--agent-control-plane-task-queue-lease-certification-status',
            '--agent-control-plane-runtime-pilot-orchestrator-status',
            '--agent-control-plane-runtime-pilot-certification-status',
            '--agent-control-plane-agent-runtime-registry-status',
            '--agent-control-plane-agent-runtime-registry-heartbeat-status',
            '--agent-control-plane-agent-runtime-registry-capability-catalog-status',
            '--agent-control-plane-agent-runtime-registry-availability-status',
            '--agent-control-plane-agent-runtime-registry-task-matcher-status',
            '--agent-control-plane-agent-runtime-registry-load-balancing-status',
            '--agent-control-plane-agent-runtime-registry-quarantine-status',
            '--agent-control-plane-agent-runtime-registry-handoff-status',
            '--agent-control-plane-agent-runtime-registry-orchestrator-status',
            '--agent-control-plane-agent-runtime-registry-certification-status',
        ];
        $statusOptionCount = count($statusFlags);
        $command = $registry['atlas:ai:self-construction'] ?? null;
        if ($command !== null) {
            $definition = $command->getDefinition();
            $available = array_keys($definition->getOptions());
            foreach ($statusFlags as $flag) {
                $name = ltrim($flag, '-');
                if (in_array($name, $available, true)) {
                    $statusFlagPresent++;
                }
            }
        }
        $commandStatusCoverage = $statusOptionCount > 0 ? round($statusFlagPresent / $statusOptionCount, 4) : 0.0;

        $proofBundle = (array) data_get($replay, 'proof_bundle', []);
        $expectedProofKinds = ['chain_integrity_summary', 'control_plane_summary', 'capability_summary', 'readiness_summary', 'cli_summary', 'invoker_summary', 'docs_summary', 'runtime_safety_summary', 'cycle_summary', 'terminal_horizon_summary', 'regression_matrix_summary'];
        $proofPresent = 0;
        foreach ($expectedProofKinds as $kind) {
            if (array_key_exists($kind, $proofBundle)) {
                $proofPresent++;
            }
        }
        $proofCoverage = count($expectedProofKinds) > 0 ? round($proofPresent / count($expectedProofKinds), 4) : 0.0;

        $integratedSurfaceMethods = [
            'agentControlPlaneTaskPacketQueueStatus',
            'agentControlPlaneClaimLeaseRuntimeStatus',
            'agentControlPlaneScopeLockRuntimeValidatorStatus',
            'agentControlPlaneTaskQueueOrchestratorStatus',
            'agentControlPlaneTaskQueueLeaseCertificationStatus',
            'agentControlPlaneRuntimeEvidenceJournalStatus',
            'agentControlPlaneExecutionWorkspaceRuntimeStatus',
            'agentControlPlaneGovernanceApprovalRuntimeStatus',
            'agentControlPlaneAutomaticCostImportRuntimeStatus',
            'agentControlPlaneAutomaticWorkProductCollectionRuntimeStatus',
            'agentControlPlaneAdapterExecutionRuntimeBoundaryStatus',
            'agentControlPlaneDispatchPlannerRuntimeStatus',
            'agentControlPlaneValidationGateRuntimeStatus',
            'agentControlPlaneMergeReviewRuntimeStatus',
            'agentControlPlaneRuntimePilotOrchestratorStatus',
            'agentControlPlaneRuntimePilotCertificationStatus',
            'agentControlPlaneAgentRuntimeRegistryStatus',
            'agentControlPlaneAgentRuntimeRegistryHeartbeatStatus',
            'agentControlPlaneAgentRuntimeRegistryCapabilityCatalogStatus',
            'agentControlPlaneAgentRuntimeRegistryAvailabilityStatus',
            'agentControlPlaneAgentRuntimeRegistryTaskMatcherStatus',
            'agentControlPlaneAgentRuntimeRegistryLoadBalancingStatus',
            'agentControlPlaneAgentRuntimeRegistryQuarantineStatus',
            'agentControlPlaneAgentRuntimeRegistryHandoffStatus',
            'agentControlPlaneAgentRuntimeRegistryOrchestratorStatus',
            'agentControlPlaneAgentRuntimeRegistryCertificationStatus',
        ];
        $integratedSurfacePresent = 0;
        foreach ($integratedSurfaceMethods as $method) {
            if (method_exists(AtlasSelfConstructionReadinessService::class, $method)) {
                $integratedSurfacePresent++;
            }
        }
        $integratedRuntimeCoverage = count($integratedSurfaceMethods) > 0
            ? round($integratedSurfacePresent / count($integratedSurfaceMethods), 4)
            : 0.0;

        $coverageBlocks = [
            'slice_coverage' => $sliceCoverage,
            'edge_coverage' => $edgeCoverage,
            'cli_coverage' => $cliCoverage,
            'readiness_coverage' => $readinessCoverage,
            'invoker_coverage' => $invokerCoverage,
            'doc_coverage' => $docCoverage,
            'runtime_flag_coverage' => $runtimeFlagCoverage,
            'scenario_coverage' => $scenarioCoverage,
            'fuzz_coverage' => $fuzzCoverage,
            'command_status_coverage' => $commandStatusCoverage,
            'proof_bundle_coverage' => $proofCoverage,
            'integrated_runtime_coverage' => $integratedRuntimeCoverage,
        ];

        $weights = array_fill_keys(array_keys($coverageBlocks), 1.0);
        $weightedSum = 0.0;
        $totalWeight = 0.0;
        foreach ($coverageBlocks as $block => $value) {
            $weightedSum += $value * $weights[$block];
            $totalWeight += $weights[$block];
        }
        $coverageScore = $totalWeight > 0 ? round($weightedSum / $totalWeight, 4) : 0.0;

        $missing = [];
        foreach ($coverageBlocks as $block => $value) {
            if ($value < 1.0) {
                $missing[] = [
                    'block' => $block,
                    'value' => $value,
                    'gap' => round(1.0 - $value, 4),
                ];
            }
        }

        $grade = match (true) {
            $coverageScore >= 0.95 => 'A',
            $coverageScore >= 0.85 => 'B',
            $coverageScore >= 0.70 => 'C',
            $coverageScore >= 0.50 => 'D',
            default => 'F',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'report_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => 'available',
            'read_only' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'external_provider_call' => false,
            'token_spend' => false,
            'process_started' => false,
            'self_programming_allowed' => false,
            'completion_claim_allowed' => false,
            'coverage_score' => $coverageScore,
            'coverage_grade' => $grade,
            'coverage_blocks' => $coverageBlocks,
            'missing_coverage' => $missing,
            'block_count' => count($coverageBlocks),
            'metrics' => [
                'slice_count' => $sliceCount,
                'slice_ok_count' => $sliceOk,
                'edge_count' => count($edges),
                'edge_ok_count' => $edgeOk,
                'cli_present' => $cliPresent,
                'cli_missing' => $cliMissing,
                'invoker_total' => $invokerTotal,
                'invoker_gaps' => count((array) data_get($audit, 'invoker_gaps', [])),
                'doc_count' => $docCount,
                'doc_expected' => $expectedDocCount,
                'runtime_flag_all_false' => $runtimeFlagAllFalse,
                'scenario_count' => $scenarioCount,
                'fuzz_iteration_count' => $fuzzIterations,
                'command_status_flag_count' => $statusOptionCount,
                'command_status_flag_present' => $statusFlagPresent,
                'proof_bundle_kinds_present' => $proofPresent,
                'proof_bundle_kinds_expected' => count($expectedProofKinds),
                'integrated_surface_present' => $integratedSurfacePresent,
                'integrated_surface_expected' => count($integratedSurfaceMethods),
            ],
            'options_applied' => [
                'skip_fuzz' => $skipFuzz,
                'skip_simulator' => $skipSimulator,
            ],
            'non_execution_guarantees' => [
                'coverage_does_not_start_codex',
                'coverage_does_not_call_codex_cli_or_app',
                'coverage_does_not_spawn_subprocess',
                'coverage_does_not_invoke_adapter',
                'coverage_does_not_execute_adapter',
                'coverage_does_not_call_provider',
                'coverage_does_not_dispatch_work',
                'coverage_does_not_spend_tokens',
                'coverage_does_not_enable_self_programming',
                'coverage_does_not_write_ledger',
                'coverage_does_not_mutate_pointer',
                'coverage_does_not_promote_completion_claim',
            ],
            'human_summary' => sprintf('Certification coverage: %.4f (grade %s) across %d blocks.', $coverageScore, $grade, count($coverageBlocks)),
        ];

        $payload['coverage_hash'] = $this->stableHash($this->normalizeForCoverageHash($payload));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForCoverageHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['report_id'], $clone['generated_at'], $clone['coverage_hash']);

        // metrics include scenario count etc. that are stable for a given chain state.
        return $this->recursivelyKsort($clone);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private function stableHash(array $payload): string
    {
        $payload = $this->recursivelyKsort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
