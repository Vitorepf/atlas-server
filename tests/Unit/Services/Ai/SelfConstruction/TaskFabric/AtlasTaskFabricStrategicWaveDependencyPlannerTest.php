<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricStrategicWaveDependencyPlanner;
use Tests\TestCase;

final class AtlasTaskFabricStrategicWaveDependencyPlannerTest extends TestCase
{
    private function planner(): AtlasTaskFabricStrategicWaveDependencyPlanner
    {
        return new AtlasTaskFabricStrategicWaveDependencyPlanner;
    }

    private function waveOf(array $result, int $waveNumber): ?array
    {
        foreach ($result['waves'] as $wave) {
            if ($wave['wave'] === $waveNumber) {
                return $wave;
            }
        }

        return null;
    }

    // ── foundation/integration/verification/simplification/unknown wave assignment ──

    public function test_known_task_types_keep_the_documented_wave_order(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'foundation'],
                ['task_id' => 'b', 'type' => 'integration'],
                ['task_id' => 'c', 'type' => 'verification'],
                ['task_id' => 'd', 'type' => 'simplification'],
            ],
        ]);

        $waveNumbers = array_column($result['waves'], 'wave');
        self::assertSame([0, 1, 2, 3], $waveNumbers);

        self::assertSame('a', $this->waveOf($result, 0)['tasks'][0]['task_id']);
        self::assertSame('b', $this->waveOf($result, 1)['tasks'][0]['task_id']);
        self::assertSame('c', $this->waveOf($result, 2)['tasks'][0]['task_id']);
        self::assertSame('d', $this->waveOf($result, 3)['tasks'][0]['task_id']);
    }

    public function test_unknown_task_type_lands_in_wave_99(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [['task_id' => 'x', 'type' => 'exotic_new_type']],
        ]);

        self::assertSame([99], array_column($result['waves'], 'wave'));
        self::assertSame('exotic_new_type', $this->waveOf($result, 99)['tasks'][0]['type']);
    }

    // ── depends_on_edges creation ─────────────────────────────────────────────────

    public function test_depends_on_creates_a_prerequisite_edge_per_capability(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'integration', 'depends_on' => ['cap_x', 'cap_y']],
            ],
            'available_capabilities' => ['cap_x', 'cap_y'],
        ]);

        self::assertCount(2, $result['depends_on_edges']);
        self::assertSame(['from' => 'a', 'to' => 'cap_x', 'type' => 'prerequisite'], $result['depends_on_edges'][0]);
        self::assertSame(['from' => 'a', 'to' => 'cap_y', 'type' => 'prerequisite'], $result['depends_on_edges'][1]);
    }

    // ── missing capability blocked_edges vs forbidden_scope_blocked ──────────────

    public function test_missing_capability_produces_capability_not_implemented_blocked_edge(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'integration', 'depends_on' => ['missing_cap']],
            ],
            'available_capabilities' => [],
        ]);

        self::assertCount(1, $result['blocked_edges']);
        self::assertSame('a', $result['blocked_edges'][0]['task_id']);
        self::assertSame('missing_cap', $result['blocked_edges'][0]['missing_capability']);
        self::assertSame('capability_not_implemented_or_queued', $result['blocked_edges'][0]['reason']);
    }

    public function test_blocked_scope_produces_forbidden_scope_blocked_edge_with_null_capability(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'foundation', 'blocked_scope' => true],
            ],
        ]);

        self::assertCount(1, $result['blocked_edges']);
        self::assertSame('a', $result['blocked_edges'][0]['task_id']);
        self::assertNull($result['blocked_edges'][0]['missing_capability']);
        self::assertSame('forbidden_scope_blocked', $result['blocked_edges'][0]['reason']);
    }

    public function test_missing_capability_and_blocked_scope_both_emit_distinct_blocked_edges_for_same_task(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'integration', 'depends_on' => ['missing_cap'], 'blocked_scope' => true],
            ],
            'available_capabilities' => [],
        ]);

        self::assertCount(2, $result['blocked_edges']);
        self::assertSame('capability_not_implemented_or_queued', $result['blocked_edges'][0]['reason']);
        self::assertSame('forbidden_scope_blocked', $result['blocked_edges'][1]['reason']);
    }

    public function test_available_capability_does_not_produce_a_blocked_edge(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'integration', 'depends_on' => ['present_cap']],
            ],
            'available_capabilities' => ['present_cap'],
        ]);

        self::assertSame([], $result['blocked_edges']);
        self::assertSame([], $result['recommended_reorderings']);
    }

    // ── recommended_reorderings for tasks with missing prerequisites ────────────

    public function test_recommended_reordering_is_emitted_for_task_with_missing_capability(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'integration', 'depends_on' => ['missing_cap']],
            ],
            'available_capabilities' => [],
        ]);

        self::assertCount(1, $result['recommended_reorderings']);
        $r = $result['recommended_reorderings'][0];
        self::assertSame('a', $r['task_id']);
        self::assertSame(1, $r['current_wave']);
        self::assertSame('defer_until_missing_capabilities_available', $r['recommended_action']);
        self::assertSame(['missing_cap'], $r['missing_capabilities']);
    }

    public function test_no_recommended_reordering_when_all_capabilities_available_and_scope_not_blocked(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'foundation', 'depends_on' => ['ok_cap']],
            ],
            'available_capabilities' => ['ok_cap'],
        ]);

        self::assertSame([], $result['recommended_reorderings']);
    }

    public function test_blocked_scope_alone_without_missing_capability_does_not_trigger_reordering(): void
    {
        $result = $this->planner()->plan([
            'tasks' => [
                ['task_id' => 'a', 'type' => 'foundation', 'blocked_scope' => true],
            ],
        ]);

        self::assertSame([], $result['recommended_reorderings']);
    }
}
