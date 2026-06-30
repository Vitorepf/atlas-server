<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathStarvationDetector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPathStarvationUnblockPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPathStarvationUnblockPlannerTest extends TestCase
{
    private AtlasExternalBrainPathStarvationUnblockPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainPathStarvationUnblockPlanner;
    }

    private function allStarved(): array
    {
        return [
            'starved_paths'          => AtlasBrainPathStarvationDetector::CANONICAL_PATHS,
            'hit_paths'              => [],
            'gate_regression_active' => false,
            'max_wave_size'          => 3,
        ];
    }

    // ── Schema / envelope ─────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->planner->plan($this->allStarved());

        foreach (['schema', 'mode', 'rotation_plan', 'next_wave_paths', 'starved_count', 'gate_regression_note'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainPathStarvationUnblockPlanner::SCHEMA, $result['schema']);
    }

    // ── AC1: starved paths converted into ordered task-shape recommendations ──

    public function test_rotation_plan_covers_at_least_three_distinct_path_families(): void
    {
        $result = $this->planner->plan([
            'starved_paths'          => ['frontier-harvest', 'pattern-design', 'compounding', 'adversarial-critique'],
            'hit_paths'              => [],
            'gate_regression_active' => false,
        ]);

        $this->assertGreaterThanOrEqual(3, count($result['rotation_plan']));
        $paths = array_column($result['rotation_plan'], 'path');
        $this->assertGreaterThanOrEqual(3, count(array_unique($paths)));
    }

    public function test_each_plan_entry_has_required_fields(): void
    {
        $result = $this->planner->plan($this->allStarved());

        foreach ($result['rotation_plan'] as $entry) {
            foreach (['path', 'priority', 'starved', 'task_shape', 'rationale'] as $k) {
                $this->assertArrayHasKey($k, $entry, "Plan entry missing field: {$k}");
            }
            $this->assertIsString($entry['task_shape']);
            $this->assertNotEmpty($entry['task_shape']);
        }
    }

    public function test_starved_paths_appear_before_hit_paths_in_plan(): void
    {
        $result = $this->planner->plan([
            'starved_paths'          => ['compounding'],
            'hit_paths'              => ['frontier-harvest'],
            'gate_regression_active' => false,
        ]);

        $paths = array_column($result['rotation_plan'], 'path');
        $starvedIdx = array_search('compounding', $paths, true);
        $hitIdx     = array_search('frontier-harvest', $paths, true);

        $this->assertLessThan($hitIdx, $starvedIdx, 'Starved path must precede hit path in the plan');
    }

    public function test_next_wave_paths_bounded_by_max_wave_size(): void
    {
        $result = $this->planner->plan(array_merge($this->allStarved(), ['max_wave_size' => 2]));

        $this->assertCount(2, $result['next_wave_paths']);
    }

    public function test_next_wave_paths_only_contains_starved_paths(): void
    {
        $result = $this->planner->plan([
            'starved_paths'          => ['frontier-harvest', 'compounding'],
            'hit_paths'              => ['pattern-design'],
            'gate_regression_active' => false,
            'max_wave_size'          => 5,
        ]);

        foreach ($result['next_wave_paths'] as $path) {
            $this->assertContains($path, ['frontier-harvest', 'compounding']);
        }
    }

    public function test_starved_count_reflects_starved_path_count(): void
    {
        $result = $this->planner->plan([
            'starved_paths' => ['frontier-harvest', 'compounding', 'adversarial-critique'],
            'hit_paths'     => ['pattern-design'],
        ]);

        $this->assertSame(3, $result['starved_count']);
    }

    // ── AC2: gate_regression higher priority than path rotation ──────────────

    public function test_gate_regression_active_sets_mode_to_gate_regression_priority(): void
    {
        $result = $this->planner->plan(array_merge($this->allStarved(), ['gate_regression_active' => true]));

        $this->assertSame(
            AtlasExternalBrainPathStarvationUnblockPlanner::MODE_GATE_REGRESSION_PRIORITY,
            $result['mode'],
        );
    }

    public function test_gate_regression_active_includes_note(): void
    {
        $result = $this->planner->plan(array_merge($this->allStarved(), ['gate_regression_active' => true]));

        $this->assertNotNull($result['gate_regression_note']);
        $this->assertIsString($result['gate_regression_note']);
        $this->assertNotEmpty($result['gate_regression_note']);
    }

    public function test_gate_regression_active_still_produces_rotation_plan_for_next_cycle(): void
    {
        $result = $this->planner->plan([
            'starved_paths'          => ['frontier-harvest', 'compounding', 'adversarial-critique'],
            'hit_paths'              => [],
            'gate_regression_active' => true,
        ]);

        // Plan must still exist even when gate regression is active (for next healthy cycle)
        $this->assertNotEmpty($result['rotation_plan']);
        $this->assertGreaterThanOrEqual(3, count($result['rotation_plan']));
    }

    public function test_no_gate_regression_sets_mode_to_path_rotation(): void
    {
        $result = $this->planner->plan($this->allStarved());

        $this->assertSame(
            AtlasExternalBrainPathStarvationUnblockPlanner::MODE_PATH_ROTATION,
            $result['mode'],
        );
        $this->assertNull($result['gate_regression_note']);
    }

    // ── Unknown / non-canonical paths filtered out ───────────────────────────

    public function test_non_canonical_starved_paths_are_ignored(): void
    {
        $result = $this->planner->plan([
            'starved_paths' => ['frontier-harvest', 'totally-fake-path', 'compounding'],
            'hit_paths'     => [],
        ]);

        $this->assertSame(2, $result['starved_count']);
        $planPaths = array_column($result['rotation_plan'], 'path');
        $this->assertNotContains('totally-fake-path', $planPaths);
    }
}
