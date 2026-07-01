<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTaskLineageCompiler;
use Tests\TestCase;

/**
 * Focused contract test: proves dependency edges from capability providers, unlock edges to
 * consumers, deterministic topological waves, missing_prerequisites, dangling_prerequisite
 * blockers for non-exploratory tasks, the exploratory evidence-floor exemption, and
 * cycle_detected blockers replacing a misleading ready wave.
 */
final class AtlasTaskFabricTaskLineageCompilerTest extends TestCase
{
    private function compiler(): AtlasTaskFabricTaskLineageCompiler
    {
        return new AtlasTaskFabricTaskLineageCompiler;
    }

    public function test_dependency_edge_is_produced_from_the_capability_provider(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'provider', 'capabilities' => ['cap_x'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'consumer', 'capabilities' => [], 'dependencies' => ['cap_x'], 'unlocks' => []],
        ]);

        $dependsOn = array_values(array_filter($result['edges'], static fn (array $e): bool => $e['type'] === 'depends_on'));
        self::assertCount(1, $dependsOn);
        self::assertSame('provider', $dependsOn[0]['from']);
        self::assertSame('consumer', $dependsOn[0]['to']);
        self::assertSame('cap_x', $dependsOn[0]['symbol']);
    }

    public function test_unlock_edge_is_produced_to_the_declared_consumer(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'gate', 'capabilities' => [], 'dependencies' => [], 'unlocks' => ['feature_flag']],
            ['id' => 'downstream', 'capabilities' => [], 'dependencies' => ['feature_flag'], 'unlocks' => []],
        ]);

        $unlocks = array_values(array_filter($result['edges'], static fn (array $e): bool => $e['type'] === 'unlocks'));
        self::assertCount(1, $unlocks);
        self::assertSame('gate', $unlocks[0]['from']);
        self::assertSame('downstream', $unlocks[0]['to']);
        self::assertSame('feature_flag', $unlocks[0]['symbol']);
    }

    public function test_waves_are_deterministic_topological_order_for_equivalent_input(): void
    {
        $specsInOneOrder = [
            ['id' => 'c', 'capabilities' => ['cap_c'], 'dependencies' => ['cap_b'], 'unlocks' => []],
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_a'], 'unlocks' => []],
        ];
        $specsShuffled = [
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_a'], 'unlocks' => []],
            ['id' => 'c', 'capabilities' => ['cap_c'], 'dependencies' => ['cap_b'], 'unlocks' => []],
        ];

        $r1 = $this->compiler()->compile($specsInOneOrder);
        $r2 = $this->compiler()->compile($specsShuffled);

        self::assertSame($r1['waves'], $r2['waves']);
        self::assertSame([['a'], ['b'], ['c']], $r1['waves']);
    }

    public function test_lineage_nodes_edges_missing_prerequisites_terminal_conditions_and_blockers_are_stable(): void
    {
        $specs = [
            ['id' => 'provider', 'capabilities' => ['cap_x'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'orphan', 'capabilities' => [], 'dependencies' => ['ghost'], 'unlocks' => []],
        ];

        $r1 = $this->compiler()->compile($specs);
        $r2 = $this->compiler()->compile($specs);

        self::assertSame($r1['lineage_nodes'], $r2['lineage_nodes']);
        self::assertSame($r1['edges'], $r2['edges']);
        self::assertSame($r1['waves'], $r2['waves']);
        self::assertSame($r1['missing_prerequisites'], $r2['missing_prerequisites']);
        self::assertSame($r1['terminal_stop_conditions'], $r2['terminal_stop_conditions']);
        self::assertSame($r1['blockers'], $r2['blockers']);
    }

    public function test_missing_prerequisite_is_recorded_when_no_capability_provider_exists(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'orphan', 'capabilities' => [], 'dependencies' => ['nonexistent_cap'], 'unlocks' => []],
        ]);

        self::assertCount(1, $result['missing_prerequisites']);
        self::assertSame('orphan', $result['missing_prerequisites'][0]['task_id']);
        self::assertContains('nonexistent_cap', $result['missing_prerequisites'][0]['missing']);
    }

    public function test_dangling_prerequisite_blocker_applies_to_non_exploratory_tasks(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'task-x', 'capabilities' => [], 'dependencies' => ['missing_cap'], 'unlocks' => []],
        ]);

        self::assertContains('dangling_prerequisite:task-x:missing_cap', $result['blockers']);
    }

    public function test_exploratory_task_with_evidence_floor_is_exempt_from_dangling_prerequisite_blocker(): void
    {
        $result = $this->compiler()->compile([
            [
                'id' => 'research-a',
                'capabilities' => [],
                'dependencies' => ['unknown_cap'],
                'unlocks' => [],
                'exploratory' => true,
                'evidence_floor' => 'at_least_one_test_green',
            ],
        ]);

        $danglingBlockers = array_filter($result['blockers'], static fn (string $b): bool => str_starts_with($b, 'dangling_prerequisite:'));
        self::assertSame([], array_values($danglingBlockers));
        self::assertCount(1, $result['missing_prerequisites'], 'missing prerequisite must still be recorded even when exempt from the blocker');
    }

    public function test_dependency_cycle_reports_cycle_detected_blocker_instead_of_a_misleading_ready_wave(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'x', 'capabilities' => ['cap_x'], 'dependencies' => ['cap_y'], 'unlocks' => []],
            ['id' => 'y', 'capabilities' => ['cap_y'], 'dependencies' => ['cap_x'], 'unlocks' => []],
        ]);

        $cycleBlockers = array_values(array_filter($result['blockers'], static fn (string $b): bool => str_starts_with($b, 'cycle_detected:')));
        self::assertContains('cycle_detected:x', $cycleBlockers);
        self::assertContains('cycle_detected:y', $cycleBlockers);

        // Neither cyclic task appears in any wave — no misleading ready wave is produced for them.
        $tasksInAnyWave = array_merge(...$result['waves']);
        self::assertNotContains('x', $tasksInAnyWave);
        self::assertNotContains('y', $tasksInAnyWave);
    }
}
