<?php

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Str;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
/**
 * Coverage report for the Agent Control Plane certification stack.
 * Measures slice/edge/CLI/readiness/invoker/doc/runtime-flag/scenario
 * /fuzz/command-status/proof-bundle coverage grouped by 8 runtime surfaces
 * (runtime, queue, scope, evidence, workspace, worker, release, rollback)
 * and produces a structured report with covered, uncovered, stale items,
 * blocking_gaps, and next_certification_task.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;

final class AgentControlPlaneCertificationCoverageReportService
{
    use HashesKsortedPayloadCanonically;
    use RecursivelyKsortsArrays;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_certification_coverage_report.v1';

    public const MODE = 'read_only_agent_control_plane_certification_coverage_report';

    /** @var list<string> */
    private const SURFACE_GROUPS = [
        'runtime',
        'queue',
        'scope',
        'evidence',
        'workspace',
        'worker',
        'release',
        'rollback',
    ];

    /** @var list<string> Proof bundle kinds an evidence surface requires for non-blocking. */
    private const REQUIRED_EVIDENCE_KINDS = [
        'chain_integrity_summary',
        'control_plane_summary',
        'capability_summary',
        'readiness_summary',
        'cli_summary',
        'invoker_summary',
        'docs_summary',
        'runtime_safety_summary',
        'cycle_summary',
        'terminal_horizon_summary',
        'regression_matrix_summary',
    ];

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
        $missingStatusFlagNames = [];
        $command = $registry['atlas:ai:self-construction'] ?? null;
        if ($command !== null) {
            $definition = $command->getDefinition();
            $available = array_keys($definition->getOptions());
            foreach ($statusFlags as $flag) {
                $name = ltrim($flag, '-');
                if (in_array($name, $available, true)) {
                    $statusFlagPresent++;
                } else {
                    $missingStatusFlagNames[] = $flag;
                }
            }
        } else {
            $missingStatusFlagNames = $statusFlags;
        }
        $commandStatusCoverage = $statusOptionCount > 0 ? round($statusFlagPresent / $statusOptionCount, 4) : 0.0;

        $proofBundle = (array) data_get($replay, 'proof_bundle', []);
        $proofPresent = 0;
        foreach (self::REQUIRED_EVIDENCE_KINDS as $kind) {
            if (array_key_exists($kind, $proofBundle)) {
                $proofPresent++;
            }
        }
        $proofCoverage = count(self::REQUIRED_EVIDENCE_KINDS) > 0 ? round($proofPresent / count(self::REQUIRED_EVIDENCE_KINDS), 4) : 0.0;

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

        // ── group coverage by surface ─────────────────────────────────────────────

        $surfaces = [];
        $blockingGaps = [];

        foreach (self::SURFACE_GROUPS as $group) {
            $covered = [];
            $uncovered = [];
            $stale = [];
            $blocked = false;
            $blockedReasons = [];

            switch ($group) {
                case 'runtime':
                    $covered = [];
                    if ($runtimeFlagCoverage >= 1.0) {
                        $covered[] = 'runtime_safety_flags';
                    } else {
                        $uncovered[] = 'runtime_safety_flags';
                    }
                    if ($integratedRuntimeCoverage >= 1.0) {
                        $covered[] = 'integrated_runtime_methods';
                    } else {
                        $uncovered[] = 'integrated_runtime_methods';
                    }
                    if ($scenarioCoverage >= 0.9) {
                        $covered[] = 'certification_scenarios';
                    } else {
                        $uncovered[] = 'certification_scenarios';
                    }
                    if ($fuzzCoverage >= 0.9) {
                        $covered[] = 'fuzz_harness';
                    } else {
                        $uncovered[] = 'fuzz_harness';
                    }
                    break;

                case 'queue':
                    if ($sliceCoverage >= 1.0) {
                        $covered[] = 'chain_integrity_slices';
                    } else {
                        $uncovered[] = 'chain_integrity_slices';
                    }
                    if ($edgeCoverage >= 1.0) {
                        $covered[] = 'per_slice_next_edges';
                    } else {
                        $uncovered[] = 'per_slice_next_edges';
                    }
                    if ($commandStatusCoverage >= 1.0) {
                        $covered[] = 'task_queue_command_flags';
                    } else {
                        $uncovered[] = 'task_queue_command_flags';
                    }
                    break;

                case 'scope':
                    if ($invokerCoverage >= 1.0) {
                        $covered[] = 'invoker_coverage';
                    } else {
                        $uncovered[] = 'invoker_coverage';
                    }
                    if ($cliCoverage >= 1.0) {
                        $covered[] = 'cli_options';
                    } else {
                        $uncovered[] = 'cli_options';
                    }
                    break;

                case 'evidence':
                    if ($proofCoverage >= 1.0) {
                        $covered[] = 'proof_bundle';
                    } else {
                        $uncovered[] = 'proof_bundle';
                        $staleProofKinds = [];
                        foreach (self::REQUIRED_EVIDENCE_KINDS as $kind) {
                            if (! array_key_exists($kind, $proofBundle)) {
                                $staleProofKinds[] = 'missing_proof:'.$kind;
                            }
                        }
                        if ($staleProofKinds !== []) {
                            $stale = $staleProofKinds;
                            $blocked = true;
                            $blockedReasons[] = 'missing_proof';
                        }
                    }
                    if ($readinessCoverage >= 1.0) {
                        $covered[] = 'readiness_quartet';
                    } else {
                        $uncovered[] = 'readiness_quartet';
                        $blocked = true;
                        $blockedReasons[] = 'readiness_gaps';
                    }
                    break;

                case 'workspace':
                    if ($integratedRuntimeCoverage >= 0.5) {
                        $covered[] = 'execution_workspace_methods';
                    } else {
                        $uncovered[] = 'execution_workspace_methods';
                    }
                    if ($docCoverage >= 1.0) {
                        $covered[] = 'slice_documentation';
                    } else {
                        $uncovered[] = 'slice_documentation';
                    }
                    break;

                case 'worker':
                    $workerMethodPrefixes = ['agentControlPlaneAgentRuntimeRegistry'];
                    $workerOk = 0;
                    $workerTotal = 0;
                    foreach ($integratedSurfaceMethods as $method) {
                        foreach ($workerMethodPrefixes as $prefix) {
                            if (str_starts_with($method, $prefix)) {
                                $workerTotal++;
                                if (method_exists(AtlasSelfConstructionReadinessService::class, $method)) {
                                    $workerOk++;
                                }
                            }
                        }
                    }
                    if ($workerTotal > 0 && $workerOk === $workerTotal) {
                        $covered[] = 'agent_registry_methods';
                    } elseif ($workerTotal > 0) {
                        $uncovered[] = 'agent_registry_methods';
                    } else {
                        $uncovered[] = 'agent_registry_methods';
                    }
                    break;

                case 'release':
                    $releaseCoverageOk = $sliceCoverage >= 1.0 && $edgeCoverage >= 1.0 && $cliCoverage >= 1.0;
                    if ($releaseCoverageOk) {
                        $covered[] = 'release_prerequisites';
                    } else {
                        $uncovered[] = 'release_prerequisites';
                    }
                    break;

                case 'rollback':
                    $rollbackPresent = false;
                    foreach ((array) data_get($replay, 'proof_bundle', []) as $key => $val) {
                        if (str_contains((string) $key, 'rollback') || (is_array($val) && (bool) data_get($val, 'rollback', false))) {
                            $rollbackPresent = true;
                        }
                    }
                    if ($rollbackPresent) {
                        $covered[] = 'rollback_proof';
                    } else {
                        $uncovered[] = 'rollback_proof';
                        $stale[] = 'rollback_proof';
                        $blocked = true;
                        $blockedReasons[] = 'missing_rollback_proof';
                    }
                    break;
            }

            $surfaces[] = [
                'name' => $group,
                'coverage' => match (true) {
                    $uncovered === [] && $stale === [] => 1.0,
                    $covered === [] && $stale !== [] => 0.0,
                    $covered === [] => 0.0,
                    default => round(count($covered) / max(1, count($covered) + count($uncovered)), 4),
                },
                'covered' => $covered,
                'uncovered' => $uncovered,
                'stale' => $stale,
                'blocked' => $blocked,
                'blocked_reasons' => $blockedReasons,
            ];

            if ($blocked) {
                $blockingGaps[] = [
                    'surface' => $group,
                    'reasons' => $blockedReasons,
                    'uncovered_items' => $uncovered,
                    'stale_items' => $stale,
                ];
            }
        }

        $nextCertificationTask = $blockingGaps !== []
            ? 'resolve_blocking_gap:'.$blockingGaps[0]['surface']
            : 'no_blocking_gaps';

        // ── critical_gap_summary (backward compatible) ────────────────────────────

        $lowestCoverageBlocks = $missing;
        usort($lowestCoverageBlocks, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        $skippedInputs = [];
        if ($skipSimulator) {
            $skippedInputs[] = 'simulator';
        }
        if ($skipFuzz) {
            $skippedInputs[] = 'fuzz';
        }

        $recommendedNextFocus = $lowestCoverageBlocks !== []
            ? (string) $lowestCoverageBlocks[0]['block']
            : 'none_all_blocks_at_full_coverage';

        $criticalGapSummary = [
            'lowest_coverage_blocks' => $lowestCoverageBlocks,
            'missing_status_flags' => $missingStatusFlagNames,
            'skipped_inputs' => $skippedInputs,
            'blocked_by_skipped_inputs' => $skippedInputs !== [],
            'recommended_next_focus' => $recommendedNextFocus,
        ];

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
            'critical_gap_summary' => $criticalGapSummary,
            'surfaces' => $surfaces,
            'blocking_gaps' => $blockingGaps,
            'next_certification_task' => $nextCertificationTask,
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
                'proof_bundle_kinds_expected' => count(self::REQUIRED_EVIDENCE_KINDS),
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
            'human_summary' => sprintf('Certification coverage: %.4f (grade %s) across %d blocks, %d surfaces, %d blocking gaps.', $coverageScore, $grade, count($coverageBlocks), count($surfaces), count($blockingGaps)),
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

}
