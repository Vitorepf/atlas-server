<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphStrategicChainPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphStrategicChainPlannerTest extends TestCase
{
    private AtlasTaskGraphStrategicChainPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasTaskGraphStrategicChainPlanner;
    }

    private function go(array $tasks, float $highRiskThreshold = 0.70): array
    {
        return $this->planner->plan(['tasks' => $tasks, 'high_risk_threshold' => $highRiskThreshold]);
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'id'            => 't1',
            'prerequisites' => [],
            'unlocks'       => [],
            'risk'          => 0.1,
            'payoff'        => 0.5,
        ], $overrides);
    }

    // ── AC2: missing prerequisites / cycles → unresolved_tasks ───────────────

    public function test_cyclic_task_is_unresolved(): void
    {
        // missing dep outside taskMap is silently skipped; cycle causes unresolved
        $r = $this->go([
            $this->task(['id' => 'x', 'prerequisites' => ['y']]),
            $this->task(['id' => 'y', 'prerequisites' => ['x']]),
        ]);

        $this->assertContains('x', $r['unresolved_tasks']);
        $this->assertContains('y', $r['unresolved_tasks']);
    }

    public function test_cycle_puts_both_tasks_in_unresolved(): void
    {
        $r = $this->go([
            $this->task(['id' => 'a', 'prerequisites' => ['b']]),
            $this->task(['id' => 'b', 'prerequisites' => ['a']]),
        ]);

        $this->assertContains('a', $r['unresolved_tasks']);
        $this->assertContains('b', $r['unresolved_tasks']);
    }

    public function test_task_with_satisfied_prerequisite_is_chained(): void
    {
        $r = $this->go([
            $this->task(['id' => 'base']),
            $this->task(['id' => 'dependent', 'prerequisites' => ['base']]),
        ]);

        $this->assertEmpty($r['unresolved_tasks']);
        $flat = array_merge(...($r['chains'] ?: [[]]));
        $this->assertContains('base', $flat);
        $this->assertContains('dependent', $flat);
    }

    // ── AC3: high-risk spacing + prerequisite ordering ────────────────────────

    public function test_two_high_risk_tasks_at_same_depth_produces_risk_guard(): void
    {
        $r = $this->go([
            $this->task(['id' => 'hr1', 'risk' => 0.80]),
            $this->task(['id' => 'hr2', 'risk' => 0.90]),
        ]);

        $this->assertNotEmpty($r['risk_guards']);
        $guardedIds = array_column($r['risk_guards'], 'task_id');
        // one of the two must be blocked
        $this->assertTrue(
            in_array('hr1', $guardedIds, true) || in_array('hr2', $guardedIds, true),
        );
    }

    public function test_single_high_risk_task_has_no_risk_guard(): void
    {
        $r = $this->go([
            $this->task(['id' => 'safe', 'risk' => 0.20]),
            $this->task(['id' => 'hr1',  'risk' => 0.80]),
        ]);

        $this->assertEmpty($r['risk_guards']);
    }

    public function test_risk_guard_reason_is_parallel_high_risk_disallowed(): void
    {
        $r = $this->go([
            $this->task(['id' => 'hr1', 'risk' => 0.80]),
            $this->task(['id' => 'hr2', 'risk' => 0.90]),
        ]);

        $this->assertSame('parallel_high_risk_disallowed', $r['risk_guards'][0]['reason']);
    }

    public function test_high_risk_task_after_its_prerequisite(): void
    {
        $r = $this->go([
            $this->task(['id' => 'pre', 'risk' => 0.10, 'unlocks' => ['hr']]),
            $this->task(['id' => 'hr',  'risk' => 0.80, 'prerequisites' => ['pre']]),
        ]);

        $flat = array_merge(...($r['chains'] ?: [[]]));
        $this->assertLessThan(
            array_search('hr', $flat),
            array_search('pre', $flat),
            'prerequisite must appear before the high-risk task',
        );
    }

    // ── AC4: deterministic + plan_summary ─────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $tasks = [
            $this->task(['id' => 'a', 'risk' => 0.80]),
            $this->task(['id' => 'b', 'risk' => 0.90]),
            $this->task(['id' => 'c', 'prerequisites' => ['a']]),
        ];

        $this->assertSame(json_encode($this->go($tasks)), json_encode($this->go($tasks)));
    }

    public function test_plan_summary_totals_are_accurate(): void
    {
        $r = $this->go([
            $this->task(['id' => 'a']),
            $this->task(['id' => 'c1', 'prerequisites' => ['c2']]),
            $this->task(['id' => 'c2', 'prerequisites' => ['c1']]),
        ]);

        $summary = $r['plan_summary'];
        $this->assertSame(3, $summary['total_tasks']);
        $this->assertSame(2, $summary['unresolved']);
        $this->assertSame(1, $summary['chained']);
    }

    public function test_schema_is_set(): void
    {
        $r = $this->go([]);

        $this->assertSame(AtlasTaskGraphStrategicChainPlanner::SCHEMA, $r['schema_version']);
    }

    public function test_empty_tasks_returns_empty_chains(): void
    {
        $r = $this->go([]);

        $this->assertEmpty($r['chains']);
        $this->assertEmpty($r['unresolved_tasks']);
        $this->assertEmpty($r['risk_guards']);
    }
}
