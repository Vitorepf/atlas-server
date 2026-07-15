<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationTestingAdapter;
use App\Services\Ai\Programming\AtlasDev\Mutation\PerFileMutationStats;
use App\Services\Ai\Programming\AtlasDev\Mutation\SymfonyMutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;

/**
 * Runs the canonical Infection adapter once per registered mutative surface
 * and converts the observed summaries into the Quality Foundry
 * mutation-evidence contract. No score is synthesized when Infection fails,
 * produces no mutants, or leaves a registered surface unobserved.
 */
final class QualityFoundryMutationCoverageRunner
{
    /** @var array<string,list<string>> */
    private const SURFACE_TESTS = [
        'atlas_dev.pipeline_run_executor' => [
            'tests/Feature/Ai/Programming/AtlasDev/EndToEndPlanOnlyTest.php',
            'tests/Unit/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionServiceTest.php',
            'tests/Unit/Ai/Programming/AtlasDev/Http/PipelineRunExecutorTest.php',
            'tests/Feature/Ai/Programming/AtlasDev/Http/PlanRunSurfaceParityTest.php',
        ],
        'atlas_forge.work_packet_execution_cycle' => [
            'tests/Feature/Ai/Programming/Forge/ForgeObraRuntimeTest.php',
            'tests/Unit/Ai/Programming/Forge/Execution/ForgeObraRuntimeContractTest.php',
        ],
        'atlas_autonomos.task_serving' => [
            'tests/Feature/Ai/TaskQueueRegistryIndexStoreTest.php',
        ],
        'atlas_autonomos.native_worker' => [
            'tests/Unit/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedSupervisorCycleTest.php',
        ],
        'atlas_autonomos.commit_governance' => [
            'tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php',
            'tests/Feature/Ai/AtlasTaskScopedCommitterTest.php',
            'tests/Feature/Ai/AcosMax/Multv10VerificationSeamTest.php',
            'tests/Feature/SelfConstruction/BootSmokeGateTest.php',
            'tests/Feature/Ai/SelfConstruction/AtlasTaskLandingCertifyTest.php',
        ],
        'engineering_kernel.merge_actuator' => [
            'tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php',
        ],
    ];

    /** @var array<string,list<string>> */
    private const SURFACE_SOURCE_FILES = [
        'atlas_dev.pipeline_run_executor' => [
            'app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php',
            'app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php',
        ],
        'atlas_forge.work_packet_execution_cycle' => [
            'app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php',
            'app/Services/Ai/Programming/Forge/Execution/ForgeObraRuntime.php',
        ],
        'atlas_autonomos.task_serving' => [
            'app/Services/Ai/SelfConstruction/AtlasTaskServingService.php',
            'app/Services/Ai/SelfConstruction/TaskServing/AtlasTaskServableHeartbeatService.php',
        ],
        'atlas_autonomos.native_worker' => [
            'app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedSupervisorCycle.php',
        ],
        'atlas_autonomos.commit_governance' => [
            'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
        ],
        'engineering_kernel.merge_actuator' => [
            'app/Services/Ai/EngineeringKernel/MergeActuator.php',
            'app/Services/Ai/EngineeringKernel/Adapters/TaskLaneMergeActuatorAdapter.php',
            'app/Services/Ai/SelfConstruction/Governance/AtlasTaskMergeActuator.php',
        ],
    ];

    public function __construct(
        private readonly string $repoRoot,
        private readonly ?MutationCommandRunner $commandRunner = null,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $runId = 'quality-foundry-live-mutation', ?string $onlySurface = null): array
    {
        $surfaceIds = $onlySurface === null
            ? EngineeringExecutionSurfaceRegistry::ids()
            : [$onlySurface];
        if ($onlySurface !== null && ! EngineeringExecutionSurfaceRegistry::isConfirmedMutativeSurface($onlySurface)) {
            return [
                'schema' => 'atlas.quality_foundry.mutation_coverage_evidence.v1',
                'status' => 'failed',
                'failure_reason' => 'unknown_mutation_surface',
            ];
        }
        $adapter = new MutationTestingAdapter(
            commandRunner: $this->commandRunner ?? new SymfonyMutationCommandRunner([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
            ]),
            e3Config: ElevationConfig::for('e3', ['mode' => 'hard']),
            repoRoot: $this->repoRoot,
            // The canonical surface battery can contain hundreds of real
            // mutants. Keep the full population (no sampling) and give the
            // isolated verifier enough time to emit its JSON receipt.
            timeoutSeconds: 3600,
        );
        $surfaceResults = [];
        $tested = [];
        $sourceFiles = [];
        $total = 0;
        $killed = 0;
        $failures = [];
        foreach ($surfaceIds as $surfaceId) {
            $surfaceFiles = array_values(array_filter(
                self::SURFACE_SOURCE_FILES[$surfaceId] ?? [],
                fn (string $path): bool => is_file($this->repoRoot.'/'.$path),
            ));
            $testFiles = array_values(array_filter(
                self::SURFACE_TESTS[$surfaceId] ?? [],
                fn (string $path): bool => is_file($this->repoRoot.'/'.$path),
            ));
            $sourceFiles = [...$sourceFiles, ...$surfaceFiles];
            if ($surfaceFiles === [] || $testFiles === []) {
                $failures[$surfaceId] = 'surface_scope_empty';
                $surfaceResults[$surfaceId] = ['status' => 'failed', 'reason' => 'surface_scope_empty'];
                continue;
            }
            $result = $adapter->run($runId.'-'.str_replace('.', '-', $surfaceId), [...$surfaceFiles, ...$testFiles]);
            $stats = $this->aggregateStats($result->perFileStats);
            $skippedMutants = (int) ($result->rawCounts['skippedCount'] ?? 0);
            if ($result->skipped || $result->failed || $result->rawCounts === null || $stats === null || $skippedMutants > 0) {
                $reason = $result->failed
                    ? $result->failureReason
                    : ($result->skipReason !== ''
                        ? $result->skipReason
                        : ($skippedMutants > 0 ? 'mutation_report_contains_skipped_mutants' : 'mutation_report_missing_or_unreadable'));
                $failures[$surfaceId] = $reason;
                $surfaceResults[$surfaceId] = [
                    'status' => 'failed',
                    'reason' => $reason,
                    'summary_path' => $result->summaryPath,
                    'report_path' => $result->reportPath,
                    'summary_artifact' => $this->artifactRef($result->summaryPath),
                    'report_artifact' => $this->artifactRef($result->reportPath),
                    'raw_counts' => $result->rawCounts,
                    'skipped_mutants' => $skippedMutants,
                    'scope' => $result->scope === null ? null : [
                        'test_files' => $result->scope->testFiles,
                        'source_files' => $result->scope->sourceFiles,
                    ],
                ];
                continue;
            }
            $tested[] = $surfaceId;
            $total += $stats['total'];
            $killed += $stats['killed'];
            $surfaceResults[$surfaceId] = [
                'status' => 'observed',
                'total_mutants' => $stats['total'],
                'killed_mutants' => $stats['killed'],
                'surviving_mutants' => max(0, $stats['total'] - $stats['killed']),
                'mutation_score_percent' => round(100.0 * $stats['killed'] / max(1, $stats['total']), 2),
                'summary_total_mutants' => (int) ($result->rawCounts['totalMutantsCount'] ?? 0),
                'summary_killed_mutants' => (int) ($result->rawCounts['killedCount'] ?? 0),
                'summary_path' => $result->summaryPath,
                'report_path' => $result->reportPath,
                'summary_artifact' => $this->artifactRef($result->summaryPath),
                'report_artifact' => $this->artifactRef($result->reportPath),
            ];
        }
        $sourceFiles = array_values(array_unique($sourceFiles));
        sort($sourceFiles, SORT_STRING);
        sort($tested, SORT_STRING);
        if ($total <= 0) {
            return [
                'schema' => 'atlas.quality_foundry.mutation_coverage_evidence.v1',
                'status' => 'failed',
                'failure_reason' => $failures === [] ? 'mutation_report_population_empty' : 'surface_mutation_runs_failed',
                'surface_results' => $surfaceResults,
                'failures' => $failures,
                'source_files' => $sourceFiles,
            ];
        }
        $surviving = max(0, $total - $killed);
        $score = round(100.0 * $killed / $total, 2);

        return [
            'schema' => 'atlas.quality_foundry.mutation_coverage_evidence.v1',
            'status' => $failures === [] ? 'observed' : 'failed',
            'registered_mutation_surfaces' => EngineeringExecutionSurfaceRegistry::ids(),
            'tested_mutation_surfaces' => $tested,
            'total_mutants' => $total,
            'killed_mutants' => $killed,
            'surviving_mutants' => $surviving,
            'mutation_score_percent' => $score,
            'mutation_score_floor_percent' => 60,
            'surface_results' => $surfaceResults,
            'failures' => $failures,
            'source_files' => $sourceFiles,
        ];
    }

    /** @return array{total:int,killed:int}|null */
    private function aggregateStats(?array $perFileStats): ?array
    {
        if ($perFileStats === null || $perFileStats === []) {
            return null;
        }
        $total = 0;
        $killed = 0;
        foreach ($perFileStats as $stat) {
            if ($stat instanceof PerFileMutationStats) {
                $total += $stat->total;
                $killed += $stat->killed;
            } elseif (is_array($stat)) {
                $total += (int) ($stat['total'] ?? 0);
                $killed += (int) ($stat['killed'] ?? 0);
            }
        }

        return $total > 0 ? ['total' => $total, 'killed' => $killed] : null;
    }

    /** @return array{path:?string,sha256:?string,bytes:?int} */
    private function artifactRef(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return ['path' => $path, 'sha256' => null, 'bytes' => null];
        }

        return [
            'path' => $path,
            'sha256' => hash_file('sha256', $path) ?: null,
            'bytes' => filesize($path) ?: 0,
        ];
    }

}
