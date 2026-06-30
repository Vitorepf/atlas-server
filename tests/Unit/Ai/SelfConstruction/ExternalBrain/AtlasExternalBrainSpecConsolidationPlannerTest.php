<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecConsolidationPlanner;
use Tests\TestCase;

final class AtlasExternalBrainSpecConsolidationPlannerTest extends TestCase
{
    private function svc(): AtlasExternalBrainSpecConsolidationPlanner
    {
        return new AtlasExternalBrainSpecConsolidationPlanner;
    }

    private function candidate(
        string $id,
        string $theme = 'external_brain',
        array $files = [],
        array $acceptance = [],
        array $deps = [],
        array $evidence = ['tests_or_gates_result'],
    ): array {
        return [
            'task_id' => $id,
            'theme' => $theme,
            'allowed_files' => $files ?: ["app/{$id}.php", "tests/{$id}Test.php"],
            'acceptance_criteria' => $acceptance ?: ["{$id} passes tests"],
            'dependencies' => $deps,
            'required_evidence' => $evidence,
        ];
    }

    private function plan(array $candidates, string $pressure = 'high', int $maxFiles = 8): array
    {
        return $this->svc()->plan([
            'candidates' => $candidates,
            'queue_pressure' => $pressure,
            'max_consolidated_files' => $maxFiles,
        ]);
    }

    // ── normal pressure — no consolidation ───────────────────────────────────

    public function test_normal_pressure_skips_consolidation(): void
    {
        $r = $this->plan(
            [$this->candidate('t1'), $this->candidate('t2')],
            pressure: 'normal'
        );

        $this->assertSame([], $r['consolidated_tasks']);
        $this->assertContains('t1', $r['unconsolidated']);
        $this->assertContains('t2', $r['unconsolidated']);
    }

    // ── happy path consolidation ──────────────────────────────────────────────

    public function test_same_theme_candidates_consolidated_into_macro_task(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain'),
            $this->candidate('t2', theme: 'brain'),
        ]);

        $this->assertCount(1, $r['consolidated_tasks']);
        $this->assertSame(['t1', 't2'], $r['consolidated_tasks'][0]['source_task_ids']);
    }

    public function test_macro_task_combines_allowed_files(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', files: ['app/A.php']),
            $this->candidate('t2', theme: 'brain', files: ['app/B.php']),
        ]);

        $files = $r['consolidated_tasks'][0]['allowed_files'];
        $this->assertContains('app/A.php', $files);
        $this->assertContains('app/B.php', $files);
    }

    public function test_macro_task_combines_acceptance_criteria(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', acceptance: ['t1 passes']),
            $this->candidate('t2', theme: 'brain', acceptance: ['t2 passes']),
        ]);

        $acceptance = $r['consolidated_tasks'][0]['acceptance_criteria'];
        $this->assertContains('t1 passes', $acceptance);
        $this->assertContains('t2 passes', $acceptance);
    }

    public function test_macro_task_deduplicates_dependencies(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', deps: ['dep_a', 'dep_b']),
            $this->candidate('t2', theme: 'brain', deps: ['dep_b', 'dep_c']),
        ]);

        $deps = $r['consolidated_tasks'][0]['dependencies'];
        $this->assertCount(3, $deps);
        $this->assertContains('dep_b', $deps);
    }

    public function test_macro_task_deduplicates_required_evidence(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', evidence: ['tests_or_gates_result', 'implementation_notes']),
            $this->candidate('t2', theme: 'brain', evidence: ['tests_or_gates_result']),
        ]);

        $evidence = $r['consolidated_tasks'][0]['required_evidence'];
        $unique = array_unique($evidence);
        $this->assertCount(count($unique), $evidence);
    }

    // ── different themes kept separate ────────────────────────────────────────

    public function test_different_theme_groups_produce_separate_macro_tasks(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'alpha'),
            $this->candidate('t2', theme: 'alpha'),
            $this->candidate('t3', theme: 'beta'),
            $this->candidate('t4', theme: 'beta'),
        ]);

        $this->assertCount(2, $r['consolidated_tasks']);
    }

    // ── rejection: file collision ─────────────────────────────────────────────

    public function test_file_collision_blocks_consolidation(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', files: ['app/Shared.php', 'tests/t1.php']),
            $this->candidate('t2', theme: 'brain', files: ['app/Shared.php', 'tests/t2.php']),
        ]);

        $this->assertSame([], $r['consolidated_tasks']);
        $this->assertContains('t1', $r['unconsolidated']);
        $this->assertSame('file_collision', $r['rejection_reasons']['t1']);
    }

    // ── rejection: over-wide task ─────────────────────────────────────────────

    public function test_over_wide_task_blocks_consolidation(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', files: ['a.php', 'b.php', 'c.php']),
            $this->candidate('t2', theme: 'brain', files: ['d.php', 'e.php', 'f.php']),
        ], maxFiles: 4);  // combined = 6 > 4

        $this->assertSame([], $r['consolidated_tasks']);
        $this->assertSame('over_wide_task', $r['rejection_reasons']['t1']);
    }

    // ── rejection: incompatible dependencies ─────────────────────────────────

    public function test_cross_group_dependency_blocks_consolidation(): void
    {
        // t2 depends on t1 — which is in the same consolidation group
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', files: ['app/A.php']),
            $this->candidate('t2', theme: 'brain', files: ['app/B.php'], deps: ['t1']),
        ]);

        $this->assertSame([], $r['consolidated_tasks']);
        $this->assertSame('incompatible_dependencies', $r['rejection_reasons']['t1']);
    }

    // ── single candidate in theme: no consolidation ───────────────────────────

    public function test_single_candidate_theme_is_not_consolidated(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'solo'),
            $this->candidate('t2', theme: 'group'),
            $this->candidate('t3', theme: 'group'),
        ]);

        $this->assertContains('t1', $r['unconsolidated']);
        $this->assertCount(1, $r['consolidated_tasks']);
        $this->assertSame(['t2', 't3'], $r['consolidated_tasks'][0]['source_task_ids']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->plan([]);
        $this->assertSame(AtlasExternalBrainSpecConsolidationPlanner::SCHEMA, $r['schema_version']);
    }

    // ── AC1: consolidation_score ──────────────────────────────────────────────

    public function test_macro_task_has_consolidation_score(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'brain', files: ['app/A.php']),
            $this->candidate('t2', theme: 'brain', files: ['app/B.php']),
        ]);

        $this->assertArrayHasKey('consolidation_score', $r['consolidated_tasks'][0]);
        $score = $r['consolidated_tasks'][0]['consolidation_score'];
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_more_tasks_per_file_yields_higher_score(): void
    {
        // 4 tasks, 4 files → score = 4/5 = 0.8
        $dense = $this->plan([
            $this->candidate('t1', theme: 'b', files: ['a.php']),
            $this->candidate('t2', theme: 'b', files: ['b.php']),
            $this->candidate('t3', theme: 'b', files: ['c.php']),
            $this->candidate('t4', theme: 'b', files: ['d.php']),
        ]);

        // 2 tasks, 4 files → score = 2/5 = 0.4
        $sparse = $this->plan([
            $this->candidate('t1', theme: 'b', files: ['a.php', 'b.php']),
            $this->candidate('t2', theme: 'b', files: ['c.php', 'd.php']),
        ]);

        $this->assertGreaterThan(
            $sparse['consolidated_tasks'][0]['consolidation_score'],
            $dense['consolidated_tasks'][0]['consolidation_score'],
        );
    }

    // ── AC1: shared_theme ─────────────────────────────────────────────────────

    public function test_macro_task_has_shared_theme(): void
    {
        $r = $this->plan([
            $this->candidate('t1', theme: 'payments'),
            $this->candidate('t2', theme: 'payments'),
        ]);

        $this->assertArrayHasKey('shared_theme', $r['consolidated_tasks'][0]);
        $this->assertSame('payments', $r['consolidated_tasks'][0]['shared_theme']);
    }

    // ── AC1: max_allowed_files guard in output ────────────────────────────────

    public function test_output_contains_max_allowed_files_used(): void
    {
        $r = $this->plan(
            [$this->candidate('t1'), $this->candidate('t2')],
            maxFiles: 5,
        );

        $this->assertArrayHasKey('max_allowed_files_used', $r);
        $this->assertSame(5, $r['max_allowed_files_used']);
    }

    public function test_max_allowed_files_used_present_on_normal_pressure(): void
    {
        $r = $this->plan(
            [$this->candidate('t1'), $this->candidate('t2')],
            pressure: 'normal',
        );

        $this->assertArrayHasKey('max_allowed_files_used', $r);
    }

    // ── AC2: dependency_compatibility + collision refusal reasons ─────────────

    public function test_rejection_reasons_present_for_all_violations(): void
    {
        // collision case
        $rCollision = $this->plan([
            $this->candidate('t1', theme: 'x', files: ['app/Same.php']),
            $this->candidate('t2', theme: 'x', files: ['app/Same.php']),
        ]);
        $this->assertArrayHasKey('t1', $rCollision['rejection_reasons']);
        $this->assertArrayHasKey('t2', $rCollision['rejection_reasons']);
        $this->assertSame('file_collision', $rCollision['rejection_reasons']['t1']);

        // over-wide case
        $rWide = $this->plan([
            $this->candidate('t1', theme: 'y', files: ['a.php', 'b.php', 'c.php']),
            $this->candidate('t2', theme: 'y', files: ['d.php', 'e.php', 'f.php']),
        ], maxFiles: 4);
        $this->assertSame('over_wide_task', $rWide['rejection_reasons']['t1']);

        // dependency conflict
        $rDep = $this->plan([
            $this->candidate('t1', theme: 'z', files: ['a.php']),
            $this->candidate('t2', theme: 'z', files: ['b.php'], deps: ['t1']),
        ]);
        $this->assertSame('incompatible_dependencies', $rDep['rejection_reasons']['t1']);
    }
}
