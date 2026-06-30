<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic golden-set harness for judging brain-originated task batches.
 *
 * Provides five canonical golden scenarios (bug_hunt_only, template_farm,
 * honest_exhausted, high_leverage_architecture_wave, cross_project_evolution_wave)
 * and evaluates input batches against five rubric dimensions with per-dimension
 * failure reporting — no opaque aggregate score.
 *
 * INPUT batch items:
 *   type            string   'architecture'|'evolution'|'bug_fix'|'template'|'refactor'
 *   leverage        string   'high'|'medium'|'low'
 *   is_template_copy bool
 *   projects        list<string>  distinct project(s) the task touches
 *   claims_evolution bool
 *   objective       string
 *
 * Dimensions:
 *   template_farm_free        — ≤40% template copies
 *   quota_padding_free        — ≤60% low-leverage tasks
 *   evolutionary_leap_present — ≥1 high-leverage architecture/evolution task
 *   architectural_coverage    — ≥1 architecture or evolution task
 *   cross_project_reach       — tasks touch >1 distinct project
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainEvolutionBenchmarkHarness
{
    public const SCHEMA = 'atlas.external_brain.evolution_benchmark_harness.v1';

    public const SCENARIO_BUG_HUNT_ONLY       = 'bug_hunt_only';
    public const SCENARIO_TEMPLATE_FARM       = 'template_farm';
    public const SCENARIO_HONEST_EXHAUSTED    = 'honest_exhausted';
    public const SCENARIO_HIGH_LEVERAGE_ARCH  = 'high_leverage_architecture_wave';
    public const SCENARIO_CROSS_PROJECT       = 'cross_project_evolution_wave';
    public const SCENARIO_UNCLASSIFIED        = 'unclassified';

    private const TEMPLATE_FARM_RATIO  = 0.40; // >40% template copies = template farm
    private const QUOTA_PADDING_RATIO  = 0.60; // >60% low-leverage = quota padding

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
                    ['objective' => 'Design new compounding memory architecture', 'type' => 'architecture', 'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
                    ['objective' => 'Implement evidence ledger schema',           'type' => 'evolution',    'leverage' => 'high',   'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
                    ['objective' => 'Wire self-improvement cycle to brain output', 'type' => 'architecture', 'leverage' => 'medium', 'is_template_copy' => false, 'projects' => ['atlas-server'], 'claims_evolution' => true],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_HIGH_LEVERAGE_ARCH,
            ],
            [
                'scenario'          => self::SCENARIO_CROSS_PROJECT,
                'description'       => 'Batch delivers high-leverage evolution across multiple Atlas projects.',
                'batch'             => [
                    ['objective' => 'Add brain context pack to atlas-desktop',  'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-desktop', 'atlas-server'], 'claims_evolution' => true],
                    ['objective' => 'Sync memory projection to atlas-app',      'type' => 'evolution',    'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-app'],                     'claims_evolution' => true],
                    ['objective' => 'Wire cross-project evolution profile',     'type' => 'architecture', 'leverage' => 'high', 'is_template_copy' => false, 'projects' => ['atlas-server', 'atlas-desktop'], 'claims_evolution' => true],
                ],
                'honest_exhausted'  => false,
                'expected_scenario' => self::SCENARIO_CROSS_PROJECT,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $batch
     * @return array{
     *     schema: string,
     *     scenario: string,
     *     dimension_results: array<string,bool>,
     *     dimension_failures: array<string,string>,
     * }
     */
    public function evaluate(array $batch, bool $honestExhausted = false): array
    {
        if ($honestExhausted && $batch === []) {
            return $this->buildResult(
                self::SCENARIO_HONEST_EXHAUSTED,
                ['template_farm_free' => true, 'quota_padding_free' => true, 'evolutionary_leap_present' => false, 'architectural_coverage' => false, 'cross_project_reach' => false],
                [],
            );
        }

        $count = count($batch);

        if ($count === 0) {
            return $this->buildResult(
                self::SCENARIO_UNCLASSIFIED,
                ['template_farm_free' => true, 'quota_padding_free' => true, 'evolutionary_leap_present' => false, 'architectural_coverage' => false, 'cross_project_reach' => false],
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

        $templateRatio   = count($templateCopies) / $count;
        $lowLeverageRatio = count($lowLeverage) / $count;

        $projects = [];
        foreach ($batch as $task) {
            foreach ((array) ($task['projects'] ?? []) as $p) {
                $projects[(string) $p] = true;
            }
        }
        $distinctProjects = count($projects);

        $dimensions = [
            'template_farm_free'       => $templateRatio <= self::TEMPLATE_FARM_RATIO,
            'quota_padding_free'       => $lowLeverageRatio <= self::QUOTA_PADDING_RATIO,
            'evolutionary_leap_present' => count($highLeverageEvo) >= 1,
            'architectural_coverage'   => count($archOrEvo) >= 1,
            'cross_project_reach'      => $distinctProjects > 1,
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

        if (! $dimensions['quota_padding_free'] && ! $dimensions['evolutionary_leap_present']) {
            return self::SCENARIO_BUG_HUNT_ONLY;
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
