<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTaskLineageCompiler;
use Tests\TestCase;

final class AtlasTaskFabricTaskLineageCompilerTest extends TestCase
{
    private function compiler(): AtlasTaskFabricTaskLineageCompiler
    {
        return new AtlasTaskFabricTaskLineageCompiler();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present_and_correct(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame(AtlasTaskFabricTaskLineageCompiler::SCHEMA, $result['schema']);
    }

    public function test_empty_input_yields_empty_output(): void
    {
        $result = $this->compiler()->compile([]);

        $this->assertSame([], $result['lineage_nodes']);
        $this->assertSame([], $result['edges']);
        $this->assertSame([], $result['waves']);
        $this->assertSame([], $result['missing_prerequisites']);
        $this->assertSame([], $result['terminal_stop_conditions']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
        ]);

        foreach (['schema', 'lineage_nodes', 'edges', 'waves', 'missing_prerequisites', 'terminal_stop_conditions', 'blockers'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    // ── lineage nodes ─────────────────────────────────────────────────────────

    public function test_single_spec_produces_one_lineage_node(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'task-a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
        ]);

        $this->assertCount(1, $result['lineage_nodes']);
        $node = $result['lineage_nodes'][0];
        $this->assertSame('task-a', $node['id']);
        $this->assertSame(['cap_a'], $node['capabilities']);
    }

    public function test_lineage_nodes_sorted_by_id(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'zzz', 'capabilities' => [], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'aaa', 'capabilities' => [], 'dependencies' => [], 'unlocks' => []],
        ]);

        $ids = array_column($result['lineage_nodes'], 'id');
        $this->assertSame(['aaa', 'zzz'], $ids);
    }

    // ── edges ─────────────────────────────────────────────────────────────────

    public function test_dependency_between_two_tasks_produces_depends_on_edge(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'provider', 'capabilities' => ['feature_x'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'consumer', 'capabilities' => [], 'dependencies' => ['feature_x'], 'unlocks' => []],
        ]);

        $dependsOnEdges = array_filter($result['edges'], fn (array $e): bool => $e['type'] === 'depends_on');
        $this->assertCount(1, $dependsOnEdges);
        $edge = array_values($dependsOnEdges)[0];
        $this->assertSame('provider', $edge['from']);
        $this->assertSame('consumer', $edge['to']);
        $this->assertSame('feature_x', $edge['symbol']);
    }

    public function test_unlocks_field_produces_unlocks_edge_to_dependent_task(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => ['cap_b_unlock']],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_b_unlock'], 'unlocks' => []],
        ]);

        $unlockEdges = array_filter($result['edges'], fn (array $e): bool => $e['type'] === 'unlocks');
        $this->assertCount(1, array_values($unlockEdges), 'should have one unlocks edge');
        $edge = array_values($unlockEdges)[0];
        $this->assertSame('a', $edge['from']);
        $this->assertSame('b', $edge['to']);
    }

    // ── waves ─────────────────────────────────────────────────────────────────

    public function test_independent_tasks_are_in_same_wave(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => [], 'unlocks' => []],
        ]);

        $this->assertCount(1, $result['waves']);
        $this->assertContains('a', $result['waves'][0]);
        $this->assertContains('b', $result['waves'][0]);
    }

    public function test_dependent_task_arrives_in_later_wave(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'provider', 'capabilities' => ['cap_x'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'consumer', 'capabilities' => [], 'dependencies' => ['cap_x'], 'unlocks' => []],
        ]);

        $this->assertCount(2, $result['waves']);
        $this->assertContains('provider', $result['waves'][0]);
        $this->assertContains('consumer', $result['waves'][1]);
    }

    public function test_three_task_chain_produces_three_waves(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_a'], 'unlocks' => []],
            ['id' => 'c', 'capabilities' => ['cap_c'], 'dependencies' => ['cap_b'], 'unlocks' => []],
        ]);

        $this->assertCount(3, $result['waves']);
        $this->assertSame(['a'], $result['waves'][0]);
        $this->assertSame(['b'], $result['waves'][1]);
        $this->assertSame(['c'], $result['waves'][2]);
    }

    // ── missing_prerequisites ─────────────────────────────────────────────────

    public function test_dependency_with_no_provider_recorded_in_missing_prerequisites(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'orphan', 'capabilities' => [], 'dependencies' => ['ghost_cap'], 'unlocks' => []],
        ]);

        $this->assertCount(1, $result['missing_prerequisites']);
        $this->assertSame('orphan', $result['missing_prerequisites'][0]['task_id']);
        $this->assertContains('ghost_cap', $result['missing_prerequisites'][0]['missing']);
    }

    public function test_satisfied_dependency_not_in_missing_prerequisites(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => [], 'dependencies' => ['cap_a'], 'unlocks' => []],
        ]);

        $this->assertSame([], $result['missing_prerequisites']);
    }

    // ── blockers ──────────────────────────────────────────────────────────────

    public function test_dangling_prerequisite_on_non_exploratory_task_adds_blocker(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'task-x', 'capabilities' => [], 'dependencies' => ['missing_cap'], 'unlocks' => []],
        ]);

        $this->assertContains('dangling_prerequisite:task-x:missing_cap', $result['blockers']);
    }

    public function test_exploratory_task_with_evidence_floor_exempt_from_dangling_blocker(): void
    {
        $result = $this->compiler()->compile([
            [
                'id'             => 'research-a',
                'capabilities'   => [],
                'dependencies'   => ['unknown_cap'],
                'unlocks'        => [],
                'exploratory'    => true,
                'evidence_floor' => 'at_least_one_test_green',
            ],
        ]);

        $danglingBlockers = array_filter($result['blockers'], fn (string $b): bool => str_starts_with($b, 'dangling_prerequisite:'));
        $this->assertEmpty($danglingBlockers, 'exploratory tasks with evidence_floor must not get dangling_prerequisite blocker');
        $this->assertCount(1, $result['missing_prerequisites']);
    }

    public function test_exploratory_task_without_evidence_floor_still_gets_dangling_blocker(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'research-b', 'capabilities' => [], 'dependencies' => ['unknown_cap'], 'unlocks' => [], 'exploratory' => true],
        ]);

        $this->assertContains('dangling_prerequisite:research-b:unknown_cap', $result['blockers']);
    }

    public function test_cycle_detected_blocker_for_circular_dependency(): void
    {
        // a depends on cap_b, b depends on cap_a → cycle
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => ['cap_b'], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_a'], 'unlocks' => []],
        ]);

        $cycleBlockers = array_filter($result['blockers'], fn (string $b): bool => str_starts_with($b, 'cycle_detected:'));
        $this->assertCount(2, $cycleBlockers, 'both tasks in the cycle must be flagged');
    }

    public function test_clean_chain_has_no_blockers(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_a'], 'unlocks' => []],
        ]);

        $this->assertSame([], $result['blockers']);
    }

    // ── terminal_stop_conditions ──────────────────────────────────────────────

    public function test_leaf_task_with_no_consumer_is_terminal(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'leaf', 'capabilities' => ['final_cap'], 'dependencies' => [], 'unlocks' => []],
        ]);

        $terminalIds = array_column($result['terminal_stop_conditions'], 'task_id');
        $this->assertContains('leaf', $terminalIds);
        $leafCond = array_values(array_filter($result['terminal_stop_conditions'], fn (array $t): bool => $t['task_id'] === 'leaf'))[0];
        $this->assertSame('no_downstream_consumer', $leafCond['reason']);
    }

    public function test_cyclic_tasks_appear_as_terminal_with_cycle_detected_reason(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'x', 'capabilities' => ['cap_x'], 'dependencies' => ['cap_y'], 'unlocks' => []],
            ['id' => 'y', 'capabilities' => ['cap_y'], 'dependencies' => ['cap_x'], 'unlocks' => []],
        ]);

        $cycleTerminals = array_filter($result['terminal_stop_conditions'], fn (array $t): bool => $t['reason'] === 'cycle_detected');
        $this->assertCount(2, array_values($cycleTerminals));
    }

    public function test_provider_task_with_a_consumer_is_not_terminal(): void
    {
        $result = $this->compiler()->compile([
            ['id' => 'provider', 'capabilities' => ['cap_x'], 'dependencies' => [], 'unlocks' => []],
            ['id' => 'consumer', 'capabilities' => ['cap_y'], 'dependencies' => ['cap_x'], 'unlocks' => []],
        ]);

        $terminalIds = array_column($result['terminal_stop_conditions'], 'task_id');
        $this->assertNotContains('provider', $terminalIds);
        $this->assertContains('consumer', $terminalIds);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_produces_identical_output(): void
    {
        $specs = [
            ['id' => 'b', 'capabilities' => ['cap_b'], 'dependencies' => ['cap_a'], 'unlocks' => []],
            ['id' => 'a', 'capabilities' => ['cap_a'], 'dependencies' => [], 'unlocks' => []],
        ];

        $r1 = $this->compiler()->compile($specs);
        $r2 = $this->compiler()->compile($specs);

        $this->assertSame($r1, $r2);
    }
}
