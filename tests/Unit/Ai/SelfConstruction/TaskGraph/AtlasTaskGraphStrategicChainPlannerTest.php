<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphStrategicChainPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphStrategicChainPlannerTest extends TestCase
{
    private function planner(): AtlasTaskGraphStrategicChainPlanner
    {
        return new AtlasTaskGraphStrategicChainPlanner;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'id'            => 't1',
            'prerequisites' => [],
            'unlocks'       => [],
            'risk'          => 0.10,
            'payoff'        => 0.50,
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->planner()->plan([]);
        $this->assertSame(AtlasTaskGraphStrategicChainPlanner::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('chains',           $r);
        $this->assertArrayHasKey('unresolved_tasks', $r);
        $this->assertArrayHasKey('risk_guards',      $r);
        $this->assertArrayHasKey('plan_summary',     $r);
    }

    // ── AC2: basic prerequisite ordering ─────────────────────────────────────

    public function test_prerequisite_appears_before_dependent(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'proof',  'prerequisites' => [],       'unlocks' => ['impl']]),
                $this->task(['id' => 'impl',   'prerequisites' => ['proof'], 'unlocks' => []]),
            ],
        ]);

        $allChainIds = array_merge(...$r['chains']);
        $proofPos    = array_search('proof', $allChainIds, true);
        $implPos     = array_search('impl',  $allChainIds, true);
        $this->assertLessThan($implPos, $proofPos);
        $this->assertEmpty($r['unresolved_tasks']);
    }

    // ── AC2: chain follows unlock path ────────────────────────────────────────

    public function test_unlock_path_forms_a_single_chain(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'a', 'unlocks' => ['b']]),
                $this->task(['id' => 'b', 'prerequisites' => ['a'], 'unlocks' => ['c']]),
                $this->task(['id' => 'c', 'prerequisites' => ['b']]),
            ],
        ]);
        // Expect one chain [a, b, c].
        $this->assertCount(1, $r['chains']);
        $this->assertSame(['a', 'b', 'c'], $r['chains'][0]);
    }

    // ── AC2: independent tasks form separate chains ───────────────────────────

    public function test_independent_tasks_form_separate_chains(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'x']),
                $this->task(['id' => 'y']),
            ],
        ]);
        $this->assertCount(2, $r['chains']);
    }

    // ── AC3: high-risk task stays after its prerequisite ─────────────────────

    public function test_high_risk_task_placed_after_proof_prerequisite(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'proof', 'prerequisites' => [],        'risk' => 0.10]),
                $this->task(['id' => 'risky', 'prerequisites' => ['proof'], 'risk' => 0.90]),
            ],
            'high_risk_threshold' => 0.70,
        ]);
        $allIds   = array_merge(...$r['chains']);
        $proofPos = array_search('proof', $allIds, true);
        $riskyPos = array_search('risky', $allIds, true);
        $this->assertLessThan($riskyPos, $proofPos);
        $this->assertEmpty($r['risk_guards']); // no parallel issue here
    }

    // ── AC3: no two high-risk tasks at the same depth ─────────────────────────

    public function test_parallel_high_risk_tasks_are_serialised(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'hr1', 'risk' => 0.80]),
                $this->task(['id' => 'hr2', 'risk' => 0.90]),
            ],
            'high_risk_threshold' => 0.70,
        ]);
        $this->assertNotEmpty($r['risk_guards']);
        $this->assertSame('parallel_high_risk_disallowed', $r['risk_guards'][0]['reason']);

        // All ids should still appear in the plan (just shifted).
        $allIds = array_merge(...$r['chains']);
        $this->assertContains('hr1', $allIds);
        $this->assertContains('hr2', $allIds);
    }

    // ── Unresolved: cycle detection ────────────────────────────────────────────

    public function test_cyclic_tasks_are_unresolved(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'a', 'prerequisites' => ['b']]),
                $this->task(['id' => 'b', 'prerequisites' => ['a']]),
            ],
        ]);
        $this->assertContains('a', $r['unresolved_tasks']);
        $this->assertContains('b', $r['unresolved_tasks']);
    }

    // ── plan_summary ──────────────────────────────────────────────────────────

    public function test_summary_counts_correctly(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'a', 'risk' => 0.80]),
                $this->task(['id' => 'b', 'risk' => 0.20]),
            ],
            'high_risk_threshold' => 0.70,
        ]);
        $s = $r['plan_summary'];
        $this->assertSame(2, $s['total_tasks']);
        $this->assertSame(1, $s['high_risk_tasks']);
        $this->assertSame(0, $s['unresolved']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'tasks' => [
                $this->task(['id' => 'p', 'unlocks' => ['q']]),
                $this->task(['id' => 'q', 'prerequisites' => ['p'], 'risk' => 0.80]),
                $this->task(['id' => 'r', 'risk' => 0.90]),
            ],
            'high_risk_threshold' => 0.70,
        ];
        $a = $this->planner()->plan($facts);
        $b = $this->planner()->plan($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
