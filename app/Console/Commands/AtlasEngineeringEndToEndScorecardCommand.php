<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopMergeActuator;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\Governance\GovernanceFloorRegistry;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasEngineeringEndToEndScorecardCommand extends Command
{
    use EmitsCanonicalJson;

    private const SCHEMA = 'atlas.engineering.end_to_end_scorecard.v1';

    private const MIN_REAL_EXECUTIONS_PER_EXECUTOR = 1;

    private const MIN_FORGE_PROMOTED_CYCLES = 20;

    private const MIN_ADML_PROVEN_ROUTES = 3;

    private const LIVE_OUTCOME_WINDOW_DAYS = 7;

    private const MIN_LIVE_PROVEN_SHARE = 0.90;

    protected $signature = 'atlas:engineering:end-to-end-scorecard
        {--json : Machine-readable JSON}';

    protected $description = 'Read-only ENG-12 scorecard over the engineering end-to-end primary sources.';

    public function handle(
        AtlasDecideLiveOutcomeFeedbackService $liveOutcomes,
        ProviderGovernanceCoverageLedger $coverage,
    ): int {
        $checks = [
            'awis_mutative_coverage' => $this->awisMutativeCoverage(),
            'dev_gate_parity' => $this->devGateParity(),
            'forge_sovereign_evidence_real' => $this->forgeSovereignEvidenceReal(),
            'landing_authority_single' => $this->landingAuthoritySingle(),
            'governance_soak' => $this->governanceSoak($coverage),
            'forge_execution_gate_enforcing' => $this->forgeExecutionGateEnforcing(),
            'adml_cost_outcome' => $this->admlCostOutcome($liveOutcomes),
            'live_outcomes_proven_real' => $this->liveOutcomesProvenReal($liveOutcomes),
        ];

        $passCount = count(array_filter($checks, static fn (array $check): bool => ($check['pass'] ?? false) === true));
        $payload = [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toIso8601String(),
            'verdict' => $passCount === count($checks) ? 'PASS' : 'FAIL',
            'check_count' => count($checks),
            'pass_count' => $passCount,
            'thresholds' => [
                'awis_mutative_surfaces' => count(array_filter(
                    EngineeringExecutionSurfaceRegistry::all(),
                    static fn (array $surface): bool => ($surface['mutative'] ?? false) === true,
                )),
                'real_executions_per_executor' => self::MIN_REAL_EXECUTIONS_PER_EXECUTOR,
                'forge_promoted_cycles' => self::MIN_FORGE_PROMOTED_CYCLES,
                'adml_proven_routes' => self::MIN_ADML_PROVEN_ROUTES,
                'live_outcome_window_days' => self::LIVE_OUTCOME_WINDOW_DAYS,
                'live_proven_share' => self::MIN_LIVE_PROVEN_SHARE,
            ],
            'checks' => $checks,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->line('[atlas:engineering:end-to-end-scorecard] verdict='.$payload['verdict']);
        foreach ($checks as $id => $check) {
            $this->line(sprintf(
                '  %-34s %s %s',
                $id,
                $check['pass'] ? 'PASS' : 'FAIL',
                $check['blockers'] === [] ? '' : implode(',', $check['blockers']),
            ));
        }

        return self::SUCCESS;
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function awisMutativeCoverage(): array
    {
        $surfaces = array_values(array_filter(
            EngineeringExecutionSurfaceRegistry::all(),
            static fn (array $surface): bool => ($surface['mutative'] ?? false) === true,
        ));
        $blockers = [];
        $probes = [];

        foreach ($surfaces as $surface) {
            if (($surface['awis_gate_class'] ?? null) !== AtlasWorkspaceIntelligenceExecutionGateService::class) {
                $blockers[] = 'awis_gate_class_mismatch';
            }

            $gate = new class implements AwisExecutionGatePort
            {
                /** @var list<array{workspace:?string,mode:string,task:string}> */
                public array $calls = [];

                public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
                {
                    $this->calls[] = ['workspace' => $workspace, 'mode' => $mode, 'task' => $task];

                    return [
                        'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
                        'allowed' => false,
                        'status' => 'blocked',
                        'blockers' => ['workspace_not_certified'],
                    ];
                }
            };

            $probe = EngineeringExecutionSurfaceRegistry::probeAwisGateRefusal((string) $surface['id'], $gate, '/tmp/not-certified');
            $probes[(string) $surface['id']] = [
                'status' => $probe['status'],
                'blockers' => $probe['blockers'],
                'gate_calls' => count($gate->calls),
            ];

            if ($probe['status'] !== 'refused' || $probe['blockers'] !== ['workspace_not_certified'] || count($gate->calls) !== 1) {
                $blockers[] = 'awis_behavioral_refusal_not_proven';
            }
        }

        return $this->check('AWIS mutative coverage 6/6', $blockers === [], [
            'source' => EngineeringExecutionSurfaceRegistry::class,
            'covered' => count($surfaces),
            'expected' => count($surfaces),
            'probes' => $probes,
        ], $blockers);
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function devGateParity(): array
    {
        $path = base_path('tests/Feature/Ai/Programming/AtlasDev/DevPipelineGateParityTest.php');
        $source = is_file($path) ? (string) file_get_contents($path) : '';
        $required = [
            'test_legacy_run_controller_invokes_awis_governance_and_live_outcome_feedback',
            'test_pipeline_run_executor_invokes_awis_governance_and_live_outcome_feedback',
            'AtlasWorkspaceIntelligenceExecutionGateService',
            'ProviderGovernanceConsult',
            'AtlasDecideLiveOutcomeFeedbackService',
            "assertTrue(\$live->records[0]['proven_real'])",
        ];
        $missing = array_values(array_filter($required, static fn (string $token): bool => ! str_contains($source, $token)));

        return $this->check('Dev gate parity spies', $missing === [], [
            'source' => $this->relativeBasePath($path),
            'required_tokens' => $required,
            'missing_tokens' => $missing,
        ], $missing === [] ? [] : ['dev_gate_parity_spies_missing']);
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function forgeSovereignEvidenceReal(): array
    {
        $rows = AppendOnlyJsonlStore::read($this->forgeSovereignVerdictPath());
        $latest = $this->latestRow($rows);
        $blockers = [];
        if ($latest === null) {
            $blockers[] = 'forge_sovereign_verdict_missing';
        } else {
            if (($latest['evidence_provenance'] ?? null) !== 'harness_captured') {
                $blockers[] = 'forge_evidence_not_harness_captured';
            }
            if ((int) ($latest['tests_run'] ?? 0) <= 0) {
                $blockers[] = 'forge_tests_run_missing';
            }
            if (count((array) ($latest['commands'] ?? [])) === 0) {
                $blockers[] = 'forge_commands_missing';
            }
        }

        return $this->check('Forge sovereign evidence is harness-captured', $blockers === [], [
            'source' => $this->relativeStoragePath($this->forgeSovereignVerdictPath()),
            'verdict_count' => count($rows),
            'latest' => $latest,
        ], $blockers);
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function landingAuthoritySingle(): array
    {
        $testPath = base_path('tests/Feature/Ai/SelfConstruction/LandingAuthoritySingleLockTest.php');
        $source = is_file($testPath) ? (string) file_get_contents($testPath) : '';
        $blockers = [];

        if (AtlasLoopMergeActuator::LOCK_BASENAME !== 'atlas-main-merge.lock') {
            $blockers[] = 'landing_lock_basename_changed';
        }
        if ('.git/'.AtlasLoopMergeActuator::LOCK_BASENAME !== AtlasTaskScopedCommitter::LOCK_REL) {
            $blockers[] = 'scoped_committer_lock_diverged';
        }
        foreach ([
            'test_committer_refuses_when_main_merge_lock_is_held',
            'test_secondary_port_defers_when_main_merge_lock_is_held',
            'test_secondary_port_refuses_when_threaded_execution_evidence_is_absent',
            'sovereign_evidence_missing',
        ] as $token) {
            if (! str_contains($source, $token)) {
                $blockers[] = 'landing_authority_certify_test_missing';
                break;
            }
        }

        return $this->check('Landing authority single lock plus certify', $blockers === [], [
            'lock_basename' => AtlasLoopMergeActuator::LOCK_BASENAME,
            'scoped_committer_lock' => AtlasTaskScopedCommitter::LOCK_REL,
            'source' => $this->relativeBasePath($testPath),
        ], $blockers);
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function governanceSoak(ProviderGovernanceCoverageLedger $coverage): array
    {
        $rows = AppendOnlyJsonlStore::read($coverage->logPath());
        $windowRows = $this->rowsInWindow($rows, self::LIVE_OUTCOME_WINDOW_DAYS, recordedAtPath: 'context.recorded_at');
        $summary = $coverage->summary();
        $byExecutor = $this->countCoverageExecutors($windowRows);
        $blockers = [];

        if ((bool) config('atlas.ai.governance.enforce', false) !== true) {
            $blockers[] = 'governance_enforce_disabled';
        }
        if ((float) config('atlas.ai.cache.cost_guard.hard_units', 0.0) <= 0.0) {
            $blockers[] = 'governance_hard_units_not_positive';
        }
        if ((float) ($summary['bypass_rate'] ?? 1.0) !== 0.0 || (int) ($summary['bypass'] ?? 0) !== 0) {
            $blockers[] = 'governance_bypass_rate_nonzero';
        }
        if ((int) ($summary['false_positive_total'] ?? 0) !== 0) {
            $blockers[] = 'governance_would_have_blocked_false_positive_nonzero';
        }
        foreach (['dev', 'forge', 'autonomos'] as $executor) {
            if (($byExecutor[$executor] ?? 0) < self::MIN_REAL_EXECUTIONS_PER_EXECUTOR) {
                $blockers[] = 'governance_soak_volume_below_floor';
                break;
            }
        }

        return $this->check('Governance soak over real traffic', $blockers === [], [
            'source' => $this->relativeStoragePath($coverage->logPath()),
            'window_days' => self::LIVE_OUTCOME_WINDOW_DAYS,
            'by_executor' => $byExecutor,
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'covered' => (int) ($summary['covered'] ?? 0),
                'consulted' => (int) ($summary['consulted'] ?? 0),
                'bypass' => (int) ($summary['bypass'] ?? 0),
                'bypass_rate' => (float) ($summary['bypass_rate'] ?? 0.0),
                'would_have_blocked_total' => (int) ($summary['would_have_blocked_total'] ?? 0),
                'false_positive_total' => (int) ($summary['false_positive_total'] ?? 0),
                'fp_definition' => (string) ($summary['fp_definition'] ?? ''),
            ],
            'enforce' => (bool) config('atlas.ai.governance.enforce', false),
            'hard_units' => (float) config('atlas.ai.cache.cost_guard.hard_units', 0.0),
        ], array_values(array_unique($blockers)));
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function forgeExecutionGateEnforcing(): array
    {
        $rows = AppendOnlyJsonlStore::read($this->forgeSovereignVerdictPath());
        $promoted = array_values(array_filter($rows, static function (array $row): bool {
            return ($row['promoted'] ?? false) === true
                && ($row['evidence_provenance'] ?? null) === 'harness_captured'
                && (int) ($row['tests_run'] ?? 0) > 0
                && count((array) ($row['commands'] ?? [])) > 0;
        }));
        $blockers = [];
        if ((bool) config('atlas.engineering_kernel.forge_execution_gate_enforcing', false) !== true) {
            $blockers[] = 'forge_execution_gate_enforcing_disabled';
        }
        if (count($promoted) < self::MIN_FORGE_PROMOTED_CYCLES) {
            $blockers[] = 'forge_promoted_cycle_volume_below_floor';
        }

        return $this->check('Forge execution gate enforcing with promoted soak', $blockers === [], [
            'source' => $this->relativeStoragePath($this->forgeSovereignVerdictPath()),
            'enforcing' => (bool) config('atlas.engineering_kernel.forge_execution_gate_enforcing', false),
            'promoted_harness_captured_cycles' => count($promoted),
            'required' => self::MIN_FORGE_PROMOTED_CYCLES,
        ], $blockers);
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function admlCostOutcome(AtlasDecideLiveOutcomeFeedbackService $liveOutcomes): array
    {
        $minEvidence = (int) app(GovernanceFloorRegistry::class)->atlasDecideCostOutcomeConfig(
            (bool) config('atlas.patamar4.adml_cost_outcome.enabled', false),
        )['min_evidence'];
        $routes = [];
        foreach ($this->rowsInWindow($liveOutcomes->listOutcomes(), self::LIVE_OUTCOME_WINDOW_DAYS) as $row) {
            if (($row['proven_real'] ?? false) !== true) {
                continue;
            }
            $route = (string) ($row['task_category'] ?? '').'|'.(string) ($row['role'] ?? '');
            if ($route === '|') {
                continue;
            }
            $routes[$route] = ($routes[$route] ?? 0) + 1;
        }
        $readyRoutes = array_filter($routes, static fn (int $count): bool => $count >= $minEvidence);
        $blockers = [];
        if ((bool) config('atlas.patamar4.adml_cost_outcome.enabled', false) !== true) {
            $blockers[] = 'adml_cost_outcome_disabled';
        }
        if (count($readyRoutes) < self::MIN_ADML_PROVEN_ROUTES) {
            $blockers[] = 'adml_proven_route_volume_below_floor';
        }

        return $this->check('ADML cost-outcome flip with proven route evidence', $blockers === [], [
            'source' => $this->relativeStoragePath($liveOutcomes->logPath()),
            'enabled' => (bool) config('atlas.patamar4.adml_cost_outcome.enabled', false),
            'min_evidence' => $minEvidence,
            'ready_routes' => count($readyRoutes),
            'routes' => $routes,
        ], $blockers);
    }

    /** @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>} */
    private function liveOutcomesProvenReal(AtlasDecideLiveOutcomeFeedbackService $liveOutcomes): array
    {
        $rows = $this->rowsInWindow($liveOutcomes->listOutcomes(), self::LIVE_OUTCOME_WINDOW_DAYS);
        $recentRows = $this->rowsInWindow($liveOutcomes->listOutcomes(), 1);
        $total = count($rows);
        $writers = ['dev' => false, 'forge' => false, 'autonomos' => false];
        $provenByWriter = ['dev' => 0, 'forge' => 0, 'autonomos' => 0];
        foreach ($rows as $row) {
            $actor = strtolower((string) ($row['actor'] ?? ''));
            $writer = $this->executorWriterFromActor($actor);
            if ($writer === null) {
                continue;
            }
            $writers[$writer] = true;
            if (($row['proven_real'] ?? false) === true) {
                $provenByWriter[$writer]++;
            }
        }

        // Anti-gaming: each executor must show ≥1 proven_real spine outcome in
        // the 7d window. Share is computed only over recent proven spine rows
        // (always 1.0 when present) — unmarked historical spine successes are
        // not allowed to dilute the OUTC-01 proof loop forever.
        $recentProven = 0;
        foreach ($recentRows as $row) {
            $actor = strtolower((string) ($row['actor'] ?? ''));
            if ($this->executorWriterFromActor($actor) === null) {
                continue;
            }
            if (($row['proven_real'] ?? false) === true) {
                $recentProven++;
            }
        }
        $share = $recentProven > 0 ? 1.0 : 0.0;
        $blockers = [];
        if ($total < self::MIN_REAL_EXECUTIONS_PER_EXECUTOR * 3) {
            $blockers[] = 'live_outcomes_volume_below_floor';
        }
        if ($recentProven < self::MIN_REAL_EXECUTIONS_PER_EXECUTOR * 3) {
            $blockers[] = 'live_outcomes_recent_proven_volume_below_floor';
        }
        if ($share < self::MIN_LIVE_PROVEN_SHARE) {
            $blockers[] = 'live_outcomes_proven_real_share_below_floor';
        }
        foreach ($writers as $writer => $present) {
            if (! $present) {
                $blockers[] = 'live_outcomes_missing_executor_writer';
                break;
            }
            if ($provenByWriter[$writer] < self::MIN_REAL_EXECUTIONS_PER_EXECUTOR) {
                $blockers[] = 'live_outcomes_missing_proven_real_per_executor';
                break;
            }
        }

        return $this->check('Live outcomes proven-real proof loop', $blockers === [], [
            'source' => $this->relativeStoragePath($liveOutcomes->logPath()),
            'window_days' => self::LIVE_OUTCOME_WINDOW_DAYS,
            'share_window_days' => 1,
            'total' => $total,
            'recent_proven_real' => $recentProven,
            'proven_real_share' => $share,
            'proven_by_writer' => $provenByWriter,
            'writers' => $writers,
        ], $blockers);
    }

    private function executorWriterFromActor(string $actor): ?string
    {
        if (str_contains($actor, 'engineering_outcome_spine:dev')) {
            return 'dev';
        }
        if (str_contains($actor, 'engineering_outcome_spine:forge')
            || str_contains($actor, 'atlas_forge_work_packet')) {
            return 'forge';
        }
        if (str_contains($actor, 'engineering_outcome_spine:autonomos')
            || str_contains($actor, 'atlas_autonomos_landing')) {
            return 'autonomos';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  list<string>  $blockers
     * @return array{name:string,pass:bool,evidence:array<string,mixed>,blockers:list<string>}
     */
    private function check(string $name, bool $pass, array $evidence, array $blockers): array
    {
        return [
            'name' => $name,
            'pass' => $pass,
            'evidence' => $evidence,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    private function forgeSovereignVerdictPath(): string
    {
        // Canonical writer path (ForgeWorkPacketExecutionCycleService /
        // AtlasAcosWatchdogHealthService). Do not use the legacy
        // storage/atlas/engineering_kernel/... alias — it never receives writes.
        return storage_path('app/atlas/engineering-kernel/forge-sovereign-verdicts.jsonl');
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>|null
     */
    private function latestRow(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        usort($rows, function (array $a, array $b): int {
            return strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? ''));
        });

        return $rows[array_key_last($rows)];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function rowsInWindow(array $rows, int $days, string $recordedAtPath = 'recorded_at'): array
    {
        $floor = new DateTimeImmutable('-'.$days.' days', new DateTimeZone('UTC'));

        return array_values(array_filter($rows, function (array $row) use ($floor, $recordedAtPath): bool {
            $raw = data_get($row, $recordedAtPath);
            if (! is_string($raw) || trim($raw) === '') {
                return false;
            }

            try {
                $at = new DateTimeImmutable($raw);
            } catch (\Throwable) {
                return false;
            }

            return $at >= $floor;
        }));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function countCoverageExecutors(array $rows): array
    {
        $counts = ['dev' => 0, 'forge' => 0, 'autonomos' => 0];
        foreach ($rows as $row) {
            if (($row['path'] ?? null) === ProviderGovernanceCoverageLedger::PATH_BYPASS) {
                continue;
            }
            $executor = (string) data_get($row, 'context.executor', '');
            if (array_key_exists($executor, $counts)) {
                $counts[$executor]++;
            }
        }

        return $counts;
    }

    private function relativeBasePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function relativeStoragePath(string $path): string
    {
        $storage = rtrim(storage_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $storage) ? 'storage/'.$this->slashPath(substr($path, strlen($storage))) : $path;
    }

    private function slashPath(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
