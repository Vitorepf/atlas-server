<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphCollisionSafeDependencyPlanner;
use Tests\TestCase;

final class AtlasTaskGraphCollisionSafeDependencyPlannerTest extends TestCase
{
    private function planner(): AtlasTaskGraphCollisionSafeDependencyPlanner
    {
        return new AtlasTaskGraphCollisionSafeDependencyPlanner;
    }

    // ── AC: same target dependencies serialize ──

    public function test_same_target_dependencies_serialize(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 't1', 'target_family' => 'refactor'],
                ['task_id' => 't2', 'target_family' => 'refactor'],
            ],
        ]);

        $this->assertSame('serialize', $result['plan']);
        $this->assertCount(1, $result['serialized_groups']);
        $this->assertSame('refactor', $result['serialized_groups'][0]['target_family']);
    }

    // ── AC: disjoint targets parallelize ──

    public function test_disjoint_targets_parallelize(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 't1', 'target_family' => 'impl'],
                ['task_id' => 't2', 'target_family' => 'test'],
            ],
        ]);

        $this->assertSame('parallelize', $result['plan']);
        $this->assertCount(2, $result['parallelized_groups']);
        $this->assertSame([], $result['serialized_groups']);
    }

    // ── AC: collision summaries force pivot ──

    public function test_collision_summaries_force_pivot(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 't1', 'target_family' => 'impl'],
            ],
            'collision_summaries' => [
                ['target_family' => 'impl', 'severity' => 'critical'],
            ],
        ]);

        $this->assertSame('pivot', $result['plan']);
        $this->assertSame([], $result['serialized_groups']);
        $this->assertSame([], $result['parallelized_groups']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->planner()->plan([]);

        $this->assertSame(AtlasTaskGraphCollisionSafeDependencyPlanner::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('plan', $result);
        $this->assertArrayHasKey('serialized_groups', $result);
        $this->assertArrayHasKey('parallelized_groups', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'tasks' => [
                ['task_id' => 'b', 'target_family' => 'x'],
                ['task_id' => 'a', 'target_family' => 'x'],
            ],
        ];

        $a = $this->planner()->plan($input);
        $b = $this->planner()->plan($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
