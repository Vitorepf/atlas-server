<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecConsolidationPlanner;
use Tests\TestCase;

final class AtlasExternalBrainSpecConsolidationPlannerTest extends TestCase
{
    private AtlasExternalBrainSpecConsolidationPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new AtlasExternalBrainSpecConsolidationPlanner;
    }

    private function candidate(string $id, string $theme, array $files = [], array $extra = []): array
    {
        return array_merge([
            'task_id'             => $id,
            'theme'               => $theme,
            'allowed_files'       => $files ?: ["app/Services/{$id}.php"],
            'acceptance_criteria' => ["{$id}:must_pass"],
            'dependencies'        => [],
            'required_evidence'   => ['tests_result'],
        ], $extra);
    }

    // ── AC2: normal pressure → unconsolidated; high + same theme + ≥2 → consolidated

    public function test_ac2_normal_pressure_leaves_all_candidates_unconsolidated(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'normal',
            'candidates'     => [
                $this->candidate('task-a', 'scoring'),
                $this->candidate('task-b', 'scoring'),
            ],
        ]);

        $this->assertSame([], $result['consolidated_tasks']);
        $this->assertContains('task-a', $result['unconsolidated']);
        $this->assertContains('task-b', $result['unconsolidated']);
    }

    public function test_ac2_high_pressure_with_shared_theme_consolidates_group(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring'),
                $this->candidate('task-b', 'scoring'),
            ],
        ]);

        $this->assertCount(1, $result['consolidated_tasks']);
        $macro = $result['consolidated_tasks'][0];
        $this->assertSame('scoring', $macro['theme']);
        $this->assertContains('task-a', $macro['source_task_ids']);
        $this->assertContains('task-b', $macro['source_task_ids']);
    }

    public function test_ac2_single_candidate_in_theme_remains_unconsolidated(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('solo', 'routing'),
                $this->candidate('task-a', 'scoring'),
                $this->candidate('task-b', 'scoring'),
            ],
        ]);

        $this->assertContains('solo', $result['unconsolidated']);
        $this->assertCount(1, $result['consolidated_tasks']);
    }

    // ── AC3: file collision, cross-dep, max_files overflow → unconsolidated + rejection_reasons

    public function test_ac3_file_collision_rejects_group_with_reason(): void
    {
        $sharedFile = 'app/Services/Shared.php';
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring', [$sharedFile]),
                $this->candidate('task-b', 'scoring', [$sharedFile, 'app/Services/B.php']),
            ],
        ]);

        $this->assertSame([], $result['consolidated_tasks']);
        $this->assertArrayHasKey('task-a', $result['rejection_reasons']);
        $this->assertSame('file_collision', $result['rejection_reasons']['task-a']);
    }

    public function test_ac3_cross_candidate_dependency_rejects_group(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring', [], ['dependencies' => ['task-b']]),
                $this->candidate('task-b', 'scoring'),
            ],
        ]);

        $this->assertSame([], $result['consolidated_tasks']);
        $this->assertArrayHasKey('task-a', $result['rejection_reasons']);
        $this->assertSame('incompatible_dependencies', $result['rejection_reasons']['task-a']);
    }

    public function test_ac3_max_files_overflow_rejects_group(): void
    {
        // 3 candidates × 4 files each = 12 > default MAX_FILES=8
        $result = $this->planner->plan([
            'queue_pressure'        => 'high',
            'max_consolidated_files' => 5,
            'candidates'            => [
                $this->candidate('t1', 'scoring', ['app/A.php', 'app/B.php', 'app/C.php']),
                $this->candidate('t2', 'scoring', ['app/D.php', 'app/E.php', 'app/F.php']),
            ],
        ]);

        $this->assertSame([], $result['consolidated_tasks']);
        $this->assertSame('over_wide_task', $result['rejection_reasons']['t1']);
    }

    // ── AC4: consolidated macro-task has all required fields

    public function test_ac4_macro_task_preserves_unique_allowed_files(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring', ['app/A.php']),
                $this->candidate('task-b', 'scoring', ['app/B.php']),
            ],
        ]);

        $files = $result['consolidated_tasks'][0]['allowed_files'];
        $this->assertContains('app/A.php', $files);
        $this->assertContains('app/B.php', $files);
        $this->assertCount(2, $files);
    }

    public function test_ac4_macro_task_merges_acceptance_criteria(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring'),
                $this->candidate('task-b', 'scoring'),
            ],
        ]);

        $ac = $result['consolidated_tasks'][0]['acceptance_criteria'];
        $this->assertContains('task-a:must_pass', $ac);
        $this->assertContains('task-b:must_pass', $ac);
    }

    public function test_ac4_macro_task_has_source_task_ids_and_consolidation_score(): void
    {
        $result = $this->planner->plan([
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring'),
                $this->candidate('task-b', 'scoring'),
            ],
        ]);

        $macro = $result['consolidated_tasks'][0];
        $this->assertArrayHasKey('source_task_ids',     $macro);
        $this->assertArrayHasKey('consolidation_score', $macro);
        $this->assertIsFloat($macro['consolidation_score']);
        $this->assertGreaterThan(0.0, $macro['consolidation_score']);
    }

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $input = [
            'queue_pressure' => 'high',
            'candidates'     => [
                $this->candidate('task-a', 'scoring'),
                $this->candidate('task-b', 'scoring'),
            ],
        ];

        $this->assertSame($this->planner->plan($input), $this->planner->plan($input));
    }
}
