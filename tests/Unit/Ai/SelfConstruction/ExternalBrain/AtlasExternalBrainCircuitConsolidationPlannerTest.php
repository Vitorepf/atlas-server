<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCircuitConsolidationPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCircuitConsolidationPlannerTest extends TestCase
{
    private function svc(): AtlasExternalBrainCircuitConsolidationPlanner
    {
        return new AtlasExternalBrainCircuitConsolidationPlanner;
    }

    // ── AC: wave ordering ─────────────────────────────────────────────────────

    public function test_ordered_wave_case_merge_candidate_produces_all_four_waves_in_order(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'merge', 'required_tests' => ['tests/FooTest.php']],
            ],
        ]);

        $waveNames = array_column($r['waves'], 'wave');
        $this->assertSame([
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_BEHAVIOR_LOCK,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_CONSOLIDATE,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_DELETE_OR_MERGE,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_KNOWLEDGE_SYNC,
        ], $waveNames);
    }

    public function test_ordered_wave_case_delete_candidate_without_knowledge_sync_skips_consolidate_and_sync_waves(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c2', 'action' => 'delete'],
            ],
        ]);

        $waveNames = array_column($r['waves'], 'wave');
        $this->assertSame([
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_BEHAVIOR_LOCK,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_DELETE_OR_MERGE,
        ], $waveNames);
    }

    public function test_simplify_candidate_with_knowledge_sync_flag_produces_lock_execute_and_sync_waves(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c3', 'action' => 'simplify', 'requires_knowledge_sync' => true],
            ],
        ]);

        $waveNames = array_column($r['waves'], 'wave');
        $this->assertSame([
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_BEHAVIOR_LOCK,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_DELETE_OR_MERGE,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_KNOWLEDGE_SYNC,
        ], $waveNames);
    }

    public function test_keep_candidate_is_never_planned(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c4', 'action' => 'keep'],
            ],
        ]);

        $this->assertSame([], $r['waves']);
        $this->assertSame([], $r['tasks']);
    }

    // ── AC: dependency preservation ───────────────────────────────────────────

    public function test_dependency_preservation_case_execute_depends_on_its_lock_task(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'delete'],
            ],
        ]);

        $this->assertSame(['lock:c1'], $r['tasks']['execute:c1']['depends_on']);
        $this->assertArrayHasKey('lock:c1', $r['tasks']);
    }

    public function test_dependency_preservation_case_merge_execute_depends_on_lock_and_consolidate(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'merge'],
            ],
        ]);

        $this->assertSame(['lock:c1'], $r['tasks']['consolidate:c1']['depends_on']);
        $this->assertSame(['lock:c1', 'consolidate:c1'], $r['tasks']['execute:c1']['depends_on']);
    }

    public function test_dependency_preservation_case_knowledge_sync_depends_on_execute(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'delete', 'requires_knowledge_sync' => true],
            ],
        ]);

        $this->assertSame(['execute:c1'], $r['tasks']['knowledge_sync:c1']['depends_on']);
    }

    public function test_dependency_preservation_no_task_depends_on_a_task_from_a_later_or_equal_wave(): void
    {
        $waveRank = [
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_BEHAVIOR_LOCK   => 0,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_CONSOLIDATE     => 1,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_DELETE_OR_MERGE => 2,
            AtlasExternalBrainCircuitConsolidationPlanner::WAVE_KNOWLEDGE_SYNC  => 3,
        ];

        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'merge', 'required_tests' => ['t1']],
                ['candidate_id' => 'c2', 'action' => 'delete', 'requires_knowledge_sync' => true],
                ['candidate_id' => 'c3', 'action' => 'simplify'],
            ],
        ]);

        foreach ($r['tasks'] as $taskId => $task) {
            foreach ($task['depends_on'] as $depId) {
                $this->assertArrayHasKey($depId, $r['tasks'], "dependency {$depId} of {$taskId} must exist in the graph");
                $this->assertLessThan(
                    $waveRank[$task['wave']],
                    $waveRank[$r['tasks'][$depId]['wave']],
                    "{$taskId} (wave {$task['wave']}) depends on {$depId} which must be from a strictly earlier wave",
                );
            }
        }
    }

    public function test_dependency_preservation_lock_task_appears_before_execute_task_in_its_wave_bucket(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'delete'],
            ],
        ]);

        $lockWaveIndex = array_search(AtlasExternalBrainCircuitConsolidationPlanner::WAVE_BEHAVIOR_LOCK, array_column($r['waves'], 'wave'), true);
        $executeWaveIndex = array_search(AtlasExternalBrainCircuitConsolidationPlanner::WAVE_DELETE_OR_MERGE, array_column($r['waves'], 'wave'), true);

        $this->assertLessThan($executeWaveIndex, $lockWaveIndex);
    }

    // ── multiple candidates ────────────────────────────────────────────────────

    public function test_multiple_candidates_each_get_their_own_isolated_tasks(): void
    {
        $r = $this->svc()->plan([
            'candidates' => [
                ['candidate_id' => 'c1', 'action' => 'delete'],
                ['candidate_id' => 'c2', 'action' => 'merge'],
            ],
        ]);

        $lockTasks = array_filter(array_column($r['waves'], 'tasks')[0] ?? [], static fn ($t) => true);
        $this->assertContains('lock:c1', $lockTasks);
        $this->assertContains('lock:c2', $lockTasks);
        $this->assertArrayHasKey('execute:c1', $r['tasks']);
        $this->assertArrayHasKey('execute:c2', $r['tasks']);
        $this->assertArrayHasKey('consolidate:c2', $r['tasks']);
        $this->assertArrayNotHasKey('consolidate:c1', $r['tasks']);
    }

    // ── empty / schema / determinism ──────────────────────────────────────────

    public function test_empty_candidates_yields_empty_plan(): void
    {
        $r = $this->svc()->plan(['candidates' => []]);

        $this->assertSame([], $r['waves']);
        $this->assertSame([], $r['tasks']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->plan(['candidates' => []]);

        $this->assertSame(AtlasExternalBrainCircuitConsolidationPlanner::SCHEMA, $r['schema']);
    }

    public function test_plan_is_deterministic(): void
    {
        $input = ['candidates' => [
            ['candidate_id' => 'c1', 'action' => 'merge', 'required_tests' => ['t1']],
        ]];

        $this->assertSame(
            $this->svc()->plan($input),
            $this->svc()->plan($input),
        );
    }

    public function test_malformed_candidate_is_skipped(): void
    {
        $r = $this->svc()->plan(['candidates' => ['not-an-array', ['candidate_id' => '', 'action' => 'delete']]]);

        $this->assertSame([], $r['waves']);
    }
}
