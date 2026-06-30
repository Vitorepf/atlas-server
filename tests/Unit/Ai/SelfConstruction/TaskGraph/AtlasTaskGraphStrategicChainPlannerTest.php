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

    // ── outcome-aware ordering ───────────────────────────────────────────────────

    public function test_output_has_outcome_guards_key(): void
    {
        $r = $this->planner()->plan([]);
        $this->assertArrayHasKey('outcome_guards', $r);
        $this->assertSame([], $r['outcome_guards']);
    }

    public function test_high_success_family_task_ordered_before_neutral_family_at_same_tier(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'neutral', 'family' => 'neutral_family']),
                $this->task(['id' => 'boosted', 'family' => 'winning_family']),
            ],
            'task_outcome_facts' => [
                'winning_family' => ['recent_success' => true],
            ],
        ]);

        $allIds = array_merge(...$r['chains']);
        $boostedPos = array_search('boosted', $allIds, true);
        $neutralPos = array_search('neutral', $allIds, true);
        $this->assertLessThan($neutralPos, $boostedPos,
            'a task from a recently successful family must be ordered before an equal-risk/payoff neutral task');
    }

    public function test_high_success_rate_also_boosts_ordering(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'neutral', 'family' => 'neutral_family']),
                $this->task(['id' => 'boosted', 'family' => 'reliable_family']),
            ],
            'task_outcome_facts' => [
                'reliable_family' => ['success_rate' => 0.85],
            ],
        ]);

        $allIds = array_merge(...$r['chains']);
        $this->assertLessThan(array_search('neutral', $allIds, true), array_search('boosted', $allIds, true));
    }

    // ── demotion: repeated give_back / poison / weak_green ──────────────────────

    public function test_repeated_give_back_demotes_family_and_adds_outcome_guard(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'neutral', 'family' => 'neutral_family']),
                $this->task(['id' => 'demoted', 'family' => 'flaky_family']),
            ],
            'task_outcome_facts' => [
                'flaky_family' => ['give_back_count' => 3],
            ],
        ]);

        $allIds = array_merge(...$r['chains']);
        $this->assertGreaterThan(array_search('neutral', $allIds, true), array_search('demoted', $allIds, true));
        $this->assertNotEmpty($r['outcome_guards']);
        $this->assertSame('demoted', $r['outcome_guards'][0]['task_id']);
        $this->assertContains('repeated_give_back', $r['outcome_guards'][0]['reasons']);
    }

    public function test_poison_demotes_family_and_adds_outcome_guard(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'neutral', 'family' => 'neutral_family']),
                $this->task(['id' => 'poisoned', 'family' => 'toxic_family']),
            ],
            'task_outcome_facts' => [
                'toxic_family' => ['poison_count' => 1],
            ],
        ]);

        $allIds = array_merge(...$r['chains']);
        $this->assertGreaterThan(array_search('neutral', $allIds, true), array_search('poisoned', $allIds, true));
        $reasons = array_column($r['outcome_guards'], 'reasons')[0];
        $this->assertContains('poison_detected', $reasons);
    }

    public function test_weak_green_demotes_family_and_adds_outcome_guard(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'neutral', 'family' => 'neutral_family']),
                $this->task(['id' => 'weak', 'family' => 'shallow_family']),
            ],
            'task_outcome_facts' => [
                'shallow_family' => ['weak_green_count' => 2],
            ],
        ]);

        $allIds = array_merge(...$r['chains']);
        $this->assertGreaterThan(array_search('neutral', $allIds, true), array_search('weak', $allIds, true));
        $reasons = array_column($r['outcome_guards'], 'reasons')[0];
        $this->assertContains('repeated_weak_green', $reasons);
    }

    public function test_negative_signal_overrides_positive_signal_for_same_family(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'mixed', 'family' => 'mixed_family']),
            ],
            'task_outcome_facts' => [
                'mixed_family' => ['recent_success' => true, 'poison_count' => 1],
            ],
        ]);

        $this->assertNotEmpty($r['outcome_guards'], 'a family with both success and poison signals must still be demoted, not boosted');
    }

    public function test_single_give_back_does_not_demote(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'mild', 'family' => 'mild_family']),
            ],
            'task_outcome_facts' => [
                'mild_family' => ['give_back_count' => 1],
            ],
        ]);

        $this->assertSame([], $r['outcome_guards']);
    }

    public function test_task_without_family_key_defaults_family_to_its_own_id(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'lone']),
            ],
            'task_outcome_facts' => [
                'lone' => ['poison_count' => 1],
            ],
        ]);

        $this->assertSame('lone', $r['outcome_guards'][0]['family']);
    }

    // ── AC3: prerequisite ordering and high-risk depth guard hold with outcome facts present ──

    public function test_prerequisite_ordering_holds_with_outcome_facts_present(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'proof', 'unlocks' => ['impl'], 'family' => 'demoted_family']),
                $this->task(['id' => 'impl', 'prerequisites' => ['proof'], 'family' => 'boosted_family']),
            ],
            'task_outcome_facts' => [
                'demoted_family' => ['poison_count' => 1],
                'boosted_family' => ['recent_success' => true],
            ],
        ]);

        $allIds = array_merge(...$r['chains']);
        $proofPos = array_search('proof', $allIds, true);
        $implPos = array_search('impl', $allIds, true);
        $this->assertLessThan($implPos, $proofPos,
            'prerequisite ordering must hold even when the prerequisite belongs to a demoted family');
        $this->assertEmpty($r['unresolved_tasks']);
    }

    public function test_high_risk_depth_guard_holds_with_outcome_facts_present(): void
    {
        $r = $this->planner()->plan([
            'tasks' => [
                $this->task(['id' => 'hr1', 'risk' => 0.80, 'family' => 'boosted_family']),
                $this->task(['id' => 'hr2', 'risk' => 0.90, 'family' => 'demoted_family']),
            ],
            'high_risk_threshold' => 0.70,
            'task_outcome_facts' => [
                'boosted_family' => ['recent_success' => true],
                'demoted_family' => ['poison_count' => 1],
            ],
        ]);

        $this->assertNotEmpty($r['risk_guards']);
        $this->assertSame('parallel_high_risk_disallowed', $r['risk_guards'][0]['reason']);

        $allIds = array_merge(...$r['chains']);
        $this->assertContains('hr1', $allIds);
        $this->assertContains('hr2', $allIds);
    }
}
