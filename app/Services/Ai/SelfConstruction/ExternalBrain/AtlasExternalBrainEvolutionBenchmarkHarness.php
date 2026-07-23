<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic golden-set harness for judging brain-originated task batches.
 *
 * Nine canonical golden scenarios across nine rubric dimensions with per-dimension
 * failure reporting — no opaque aggregate score.
 *
 * INPUT batch items:
 *   type              string   'architecture'|'evolution'|'bug_fix'|'template'|'refactor'
 *   leverage          string   'high'|'medium'|'low'
 *   is_template_copy  bool
 *   projects          list<string>
 *   claims_evolution  bool
 *   objective         string
 *   has_proof         bool     (optional; defaults true — false = task lacks runnable proof)
 *   is_queue_driven   bool     (optional; defaults false — true = filler from queue pressure)
 *   is_second_pass    bool     (optional; defaults false — true = second-pass breakthrough)
 *   has_certification bool     (optional; defaults true — false = not yet certified)
 *
 * Dimensions (original):
 *   template_farm_free        — ≤40% template copies
 *   quota_padding_free        — ≤60% low-leverage tasks
 *   evolutionary_leap_present — ≥1 high-leverage architecture/evolution task
 *   architectural_coverage    — ≥1 architecture or evolution task
 *   cross_project_reach       — tasks touch >1 distinct project
 *
 * Dimensions (regression):
 *   proof_weighted_leverage   — no high-leverage task exists without has_proof=true
 *   queue_pressure_free       — ≤50% queue-driven tasks
 *   second_pass_present       — ≥1 is_second_pass=true task
 *   finality_floor_met        — ≥1 task with has_certification=true (or not explicitly false)
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainEvolutionBenchmarkHarness
{
    public const SCHEMA = 'atlas.external_brain.evolution_benchmark_harness.v1';

    public const SCENARIO_BUG_HUNT_ONLY            = 'bug_hunt_only';
    public const SCENARIO_TEMPLATE_FARM            = 'template_farm';
    public const SCENARIO_HONEST_EXHAUSTED         = 'honest_exhausted';
    public const SCENARIO_HIGH_LEVERAGE_ARCH       = 'high_leverage_architecture_wave';
    public const SCENARIO_CROSS_PROJECT            = 'cross_project_evolution_wave';
    public const SCENARIO_UNCLASSIFIED             = 'unclassified';
    public const SCENARIO_PROOF_WEIGHTED_LEVERAGE  = 'proof_weighted_leverage';
    public const SCENARIO_QUEUE_PRESSURE           = 'queue_pressure';
    public const SCENARIO_SECOND_PASS_BREAKTHROUGH = 'second_pass_breakthrough';
    public const SCENARIO_FINALITY_FLOOR           = 'finality_floor';
    public const SCENARIO_PREMATURE_EXHAUSTION_CLAIM = 'premature_exhaustion_claim';

    private const TEMPLATE_FARM_RATIO  = 0.40;
    private const QUOTA_PADDING_RATIO  = 0.60;
    private const QUEUE_PRESSURE_RATIO = 0.50;

    /** @return list<array<string,mixed>> */
    public function goldenScenarios(): array
    {
        return [
            [
                'scenario'          => self::SCENARIO_BUG_HUNT_ONLY,
                'description'       => 'All tasks are low-leverage bug fixes; no evolutionary work.',
                'batch'             => [
                    ['objective' => 'Fix null pointer in UserService',   'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
                    ['objective' => 'Fix divide-by-zero in Calculator',  'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
                    ['objective' => 'Fix missing null check in Router',  'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
                    ['objective' => 'Fix off-by-one in Paginator',       'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_BUG_HUNT_ONLY,
            ],
            [
                'scenario'          => self::SCENARIO_TEMPLATE_FARM,
                'description'       => 'Majority of tasks are template copies with no real evolution.',
                'batch'             => [
                    ['objective' => 'Add CRUD for EntityA', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
                    ['objective' => 'Add CRUD for EntityB', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
                    ['objective' => 'Add CRUD for EntityC', 'type' => 'template', 'leverage' => 'low', 'is_template_copy' => true,  'projects' => ['atlas-server'], 'claims_evolution' => false],
                    ['objective' => 'Fix minor bug',         'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_TEMPLATE_FARM,
            ],
            [
                'scenario'          => self::SCENARIO_HONEST_EXHAUSTED,
                'description'       => 'Brain honestly reports it found no new evolution work.',
                'batch'             => [],
                'honest_exhausted'  => true,
                'expected_scenario' => self::SCENARIO_HONEST_EXHAUSTED,
            ],
            [
                'scenario'          => self::SCENARIO_HIGH_LEVERAGE_ARCH,
                'description'       => 'Batch contains high-leverage architecture tasks in a single project.',
                'batch'             => [
                    ['objective' => 'Design new compounding memory architecture',  'type' => 'architecture', 'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
                    ['objective' => 'Implement evidence ledger schema',            'type' => 'evolution',    'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
                    ['objective' => 'Wire self-improvement cycle to brain output', 'type' => 'architecture', 'leverage' => 'medium', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_HIGH_LEVERAGE_ARCH,
            ],
            [
                'scenario'          => self::SCENARIO_CROSS_PROJECT,
                'description'       => 'Batch delivers high-leverage evolution across multiple Atlas projects.',
                'batch'             => [
                    ['objective' => 'Add brain context pack to atlas-desktop', 'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-desktop', 'atlas-server'], 'claims_evolution' => true],
                    ['objective' => 'Sync memory projection to atlas-app',     'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-app'],                     'claims_evolution' => true],
                    ['objective' => 'Wire cross-project evolution profile',    'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server', 'atlas-desktop'], 'claims_evolution' => true],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_CROSS_PROJECT,
            ],
            // ── regression cases ───────────────────────────────────────────────
            [
                'scenario'          => self::SCENARIO_PROOF_WEIGHTED_LEVERAGE,
                'description'       => 'Batch claims high-leverage evolution but no task carries runnable proof.',
                'batch'             => [
                    ['objective' => 'Design new compounding layer', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
                    ['objective' => 'Implement evidence ledger v2', 'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
                    ['objective' => 'Wire self-improvement cycle',  'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_proof' => false],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_PROOF_WEIGHTED_LEVERAGE,
            ],
            [
                'scenario'          => self::SCENARIO_QUEUE_PRESSURE,
                'description'       => 'Batch is dominated by queue-driven filler tasks with no real origination.',
                'batch'             => [
                    ['objective' => 'Drain queue item A', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true],
                    ['objective' => 'Drain queue item B', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true],
                    ['objective' => 'Drain queue item C', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true],
                    ['objective' => 'Drain queue item D', 'type' => 'bug_fix', 'leverage' => 'low', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => false, 'is_queue_driven' => true],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_QUEUE_PRESSURE,
            ],
            [
                'scenario'          => self::SCENARIO_SECOND_PASS_BREAKTHROUGH,
                'description'       => 'One second-pass breakthrough investigation surfaces a high-leverage architectural advance.',
                'batch'             => [
                    ['objective' => 'Cross-codebase scan reveals wiring gap in AtlasWiringAdapter', 'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_second_pass' => true, 'has_proof' => true],
                    ['objective' => 'Evidence ledger harvest: 3 unreported proofs found',           'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'is_second_pass' => true, 'has_proof' => true],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_SECOND_PASS_BREAKTHROUGH,
            ],
            [
                'scenario'          => self::SCENARIO_FINALITY_FLOOR,
                'description'       => 'Batch looks architecturally rich but no task has been certified — finality floor not met.',
                'batch'             => [
                    ['objective' => 'Design loop governor upgrade',   'type' => 'architecture', 'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
                    ['objective' => 'Implement capability rubric v3', 'type' => 'evolution',    'leverage' => 'medium', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true, 'has_certification' => false],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_FINALITY_FLOOR,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $batch
     * @param  array<string,bool>  $exhaustionProof  {research_exhausted?, simplification_exhausted?,
     *   second_pass_exhausted?} — a key explicitly set to false means that path was NOT exhausted.
     *   An omitted key is treated as satisfied (backward compatible: existing callers that never
     *   pass this argument keep getting honest_exhausted accepted at face value).
     * @return array{
     *     schema: string,
     *     scenario: string,
     *     dimension_results: array<string,bool>,
     *     dimension_failures: array<string,string>,
     * }
     */
    public function evaluate(array $batch, bool $honestExhausted = false, array $exhaustionProof = []): array
    {
        $emptyDims = [
            'template_farm_free'       => true,
            'quota_padding_free'       => true,
            'evolutionary_leap_present' => false,
            'architectural_coverage'   => false,
            'cross_project_reach'      => false,
            'proof_weighted_leverage'  => true,
            'queue_pressure_free'      => true,
            'second_pass_present'      => false,
            'finality_floor_met'       => true,
            'honest_exhaustion_accepted' => true,
        ];

        if ($honestExhausted && $batch === []) {
            $unexhaustedPaths = [];
            foreach (['research_exhausted', 'simplification_exhausted', 'second_pass_exhausted'] as $path) {
                if (array_key_exists($path, $exhaustionProof) && $exhaustionProof[$path] === false) {
                    $unexhaustedPaths[] = $path;
                }
            }

            if ($unexhaustedPaths === []) {
                return $this->buildResult(self::SCENARIO_HONEST_EXHAUSTED, $emptyDims, []);
            }

            $rejectedDims = $emptyDims;
            $rejectedDims['honest_exhaustion_accepted'] = false;

            return $this->buildResult(self::SCENARIO_PREMATURE_EXHAUSTION_CLAIM, $rejectedDims, [
                'honest_exhaustion_accepted' => 'exhaustion claimed but paths not exhausted: '.implode(',', $unexhaustedPaths),
            ]);
        }

        $count = count($batch);

        if ($count === 0) {
            return $this->buildResult(
                self::SCENARIO_UNCLASSIFIED,
                $emptyDims,
                ['evolutionary_leap_present' => 'no tasks in batch', 'architectural_coverage' => 'no tasks in batch', 'cross_project_reach' => 'no tasks in batch'],
            );
        }

        $templateCopies  = array_filter($batch, fn (array $t): bool => (bool) ($t['is_template_copy'] ?? false));
        $lowLeverage     = array_filter($batch, fn (array $t): bool => ($t['leverage'] ?? 'low') === 'low');
        $archOrEvo       = array_filter($batch, fn (array $t): bool => in_array($t['type'] ?? '', ['architecture', 'evolution'], true));
        $highLeverageEvo = array_filter($batch, fn (array $t): bool =>
            ($t['leverage'] ?? 'low') === 'high' &&
            in_array($t['type'] ?? '', ['architecture', 'evolution'], true)
        );

        $templateRatio    = count($templateCopies) / $count;
        $lowLeverageRatio = count($lowLeverage) / $count;

        $projects = [];
        foreach ($batch as $task) {
            foreach ((array) ($task['projects'] ?? []) as $p) {
                $projects[(string) $p] = true;
            }
        }
        $distinctProjects = count($projects);

        // Regression dimensions.
        $highLeverageTasks     = array_filter($batch, fn (array $t): bool => ($t['leverage'] ?? '') === 'high');
        $highLeverageWithProof = array_filter($highLeverageTasks, fn (array $t): bool => (bool) ($t['has_proof'] ?? true));
        // Queue-driven tasks that PROVABLY repaired a malformed queue input into a grounded,
        // high-leverage, proven task are healing work, not filler pressure — they never count
        // against queue_pressure_free.
        $isQueueHealing = fn (array $t): bool =>
            (bool) ($t['is_queue_driven'] ?? false)
            && (bool) ($t['repairs_malformed_queue_input'] ?? false)
            && ($t['is_grounded'] ?? true) === true
            && ($t['leverage'] ?? 'low') === 'high'
            && (bool) ($t['has_proof'] ?? true);
        $queueDriven            = array_filter($batch, fn (array $t): bool => (bool) ($t['is_queue_driven'] ?? false) && ! $isQueueHealing($t));
        $secondPassTasks        = array_filter($batch, fn (array $t): bool => (bool) ($t['is_second_pass'] ?? false));
        $certifiedTasks         = array_filter($batch, fn (array $t): bool => (bool) ($t['has_certification'] ?? true));

        $queueDrivenRatio = count($queueDriven) / $count;

        $dimensions = [
            'template_farm_free'       => $templateRatio <= self::TEMPLATE_FARM_RATIO,
            'quota_padding_free'       => $lowLeverageRatio <= self::QUOTA_PADDING_RATIO,
            'evolutionary_leap_present' => count($highLeverageEvo) >= 1,
            'architectural_coverage'   => count($archOrEvo) >= 1,
            'cross_project_reach'      => $distinctProjects > 1,
            'proof_weighted_leverage'  => count($highLeverageTasks) === 0 || count($highLeverageWithProof) >= 1,
            'queue_pressure_free'      => $queueDrivenRatio <= self::QUEUE_PRESSURE_RATIO,
            'second_pass_present'      => count($secondPassTasks) >= 1,
            'finality_floor_met'       => count($certifiedTasks) > 0,
        ];

        $failures = [];
        if (! $dimensions['template_farm_free']) {
            $pct = (int) round($templateRatio * 100);
            $failures['template_farm_free'] = "{$pct}% template copies exceed 40% threshold";
        }
        if (! $dimensions['quota_padding_free']) {
            $pct = (int) round($lowLeverageRatio * 100);
            $failures['quota_padding_free'] = "{$pct}% low-leverage tasks exceed 60% threshold";
        }
        if (! $dimensions['evolutionary_leap_present']) {
            $failures['evolutionary_leap_present'] = 'no high-leverage architecture or evolution task found';
        }
        if (! $dimensions['architectural_coverage']) {
            $failures['architectural_coverage'] = 'no architecture or evolution type task in batch';
        }
        if (! $dimensions['cross_project_reach']) {
            $failures['cross_project_reach'] = "{$distinctProjects} distinct project(s) — need more than 1";
        }
        if (! $dimensions['proof_weighted_leverage']) {
            $failures['proof_weighted_leverage'] = 'high-leverage tasks present but none carry runnable proof (has_proof=true)';
        }
        if (! $dimensions['queue_pressure_free']) {
            $pct = (int) round($queueDrivenRatio * 100);
            $failures['queue_pressure_free'] = "{$pct}% queue-driven tasks exceed 50% threshold — real origination required";
        }
        if (! $dimensions['second_pass_present']) {
            $failures['second_pass_present'] = 'no second-pass breakthrough task found (is_second_pass=true)';
        }
        if (! $dimensions['finality_floor_met']) {
            $failures['finality_floor_met'] = 'no certified task found — finality floor not met (has_certification must not be false)';
        }

        return $this->buildResult($this->classifyScenario($dimensions), $dimensions, $failures);
    }

    /**
     * @return array{schema:string, all_passed:bool, total:int, passed:int, results:list<array<string,mixed>>}
     */
    public function runSelfTest(): array
    {
        $results   = [];
        $allPassed = true;

        foreach ($this->goldenScenarios() as $golden) {
            $honestExhausted = (bool) ($golden['honest_exhausted'] ?? false);
            $result    = $this->evaluate((array) ($golden['batch'] ?? []), $honestExhausted);
            $passed    = $result['scenario'] === $golden['expected_scenario'];
            $allPassed = $allPassed && $passed;
            $results[] = [
                'scenario'           => $golden['scenario'],
                'expected'           => $golden['expected_scenario'],
                'actual'             => $result['scenario'],
                'passed'             => $passed,
                'dimension_results'  => $result['dimension_results'],
                'dimension_failures' => $result['dimension_failures'],
            ];
        }

        return [
            'schema'     => self::SCHEMA,
            'all_passed' => $allPassed,
            'total'      => count($results),
            'passed'     => count(array_filter($results, fn (array $r): bool => $r['passed'])),
            'results'    => $results,
        ];
    }

    /** @param array<string,bool> $dimensions */
    private function classifyScenario(array $dimensions): string
    {
        if (! $dimensions['template_farm_free']) {
            return self::SCENARIO_TEMPLATE_FARM;
        }
        if (! $dimensions['queue_pressure_free']) {
            return self::SCENARIO_QUEUE_PRESSURE;
        }
        if (! $dimensions['proof_weighted_leverage']) {
            return self::SCENARIO_PROOF_WEIGHTED_LEVERAGE;
        }
        if (! $dimensions['finality_floor_met']) {
            return self::SCENARIO_FINALITY_FLOOR;
        }
        if (! $dimensions['quota_padding_free'] && ! $dimensions['evolutionary_leap_present']) {
            return self::SCENARIO_BUG_HUNT_ONLY;
        }
        if ($dimensions['second_pass_present'] && $dimensions['evolutionary_leap_present']) {
            return $dimensions['cross_project_reach']
                ? self::SCENARIO_CROSS_PROJECT
                : self::SCENARIO_SECOND_PASS_BREAKTHROUGH;
        }
        if ($dimensions['evolutionary_leap_present'] && $dimensions['cross_project_reach']) {
            return self::SCENARIO_CROSS_PROJECT;
        }
        if ($dimensions['evolutionary_leap_present'] && $dimensions['architectural_coverage']) {
            return self::SCENARIO_HIGH_LEVERAGE_ARCH;
        }

        return self::SCENARIO_UNCLASSIFIED;
    }

    /**
     * @param  array<string,bool>    $dimensions
     * @param  array<string,string>  $failures
     * @return array{schema:string, scenario:string, dimension_results:array<string,bool>, dimension_failures:array<string,string>}
     */
    private function buildResult(string $scenario, array $dimensions, array $failures): array
    {
        return [
            'schema'             => self::SCHEMA,
            'scenario'           => $scenario,
            'dimension_results'  => $dimensions,
            'dimension_failures' => $failures,
        ];
    }
}
