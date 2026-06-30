<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationWavePlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationWavePlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainSimplificationWavePlanner
    {
        return new AtlasExternalBrainSimplificationWavePlanner;
    }

    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'has_behavior_coverage' => true,
            'dependency_risk' => 'low',
            'line_reduction' => 50,
            'ownership_clear' => true,
            'rollback_ease' => 'easy',
        ], $overrides);
    }

    // ── AC: behavior coverage + low dependency risk → first wave ─────────────

    public function test_safe_candidate_enters_first_wave(): void
    {
        $r = $this->planner()->plan([$this->candidate('safe-1')]);

        $this->assertCount(1, $r['waves']);
        $this->assertContains('safe-1', $r['waves'][0]);
    }

    public function test_low_risk_candidates_rank_before_medium_risk_in_wave_order(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('medium-risk', ['dependency_risk' => 'medium']),
            $this->candidate('low-risk', ['dependency_risk' => 'low']),
        ]);

        $this->assertSame(['low-risk', 'medium-risk'], $r['waves'][0]);
    }

    // ── AC: missing tests or unclear ownership → deferred with required_prework ──

    public function test_missing_behavior_coverage_is_deferred_with_prework(): void
    {
        $r = $this->planner()->plan([$this->candidate('no-tests', ['has_behavior_coverage' => false])]);

        $this->assertSame([], $r['waves']);
        $this->assertCount(1, $r['deferred']);
        $this->assertSame('no-tests', $r['deferred'][0]['candidate_id']);
        $this->assertContains('add_test_coverage', $r['deferred'][0]['required_prework']);
    }

    public function test_unclear_ownership_is_deferred_with_prework(): void
    {
        $r = $this->planner()->plan([$this->candidate('no-owner', ['ownership_clear' => false])]);

        $this->assertCount(1, $r['deferred']);
        $this->assertContains('clarify_ownership', $r['deferred'][0]['required_prework']);
    }

    public function test_both_missing_signals_listed_in_required_prework(): void
    {
        $r = $this->planner()->plan([$this->candidate('double-bad', ['has_behavior_coverage' => false, 'ownership_clear' => false])]);

        $prework = $r['deferred'][0]['required_prework'];
        $this->assertContains('add_test_coverage', $prework);
        $this->assertContains('clarify_ownership', $prework);
    }

    // ── AC: wave capacity reserves room when build/repair urgent ─────────────

    public function test_capacity_halved_when_build_or_repair_urgent(): void
    {
        $candidates = [];
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = $this->candidate("c-{$i}");
        }

        $normal = $this->planner()->plan($candidates, ['wave_capacity' => 6, 'build_or_repair_urgent' => false]);
        $urgent = $this->planner()->plan($candidates, ['wave_capacity' => 6, 'build_or_repair_urgent' => true]);

        $this->assertCount(1, $normal['waves']);
        $this->assertCount(6, $normal['waves'][0]);

        $this->assertGreaterThan(1, count($urgent['waves']));
        $this->assertLessThanOrEqual(3, count($urgent['waves'][0]));
        $this->assertTrue($urgent['capacity_allocation']['build_or_repair_urgent']);
    }

    public function test_capacity_allocation_reports_base_and_effective_capacity(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')], ['wave_capacity' => 4, 'build_or_repair_urgent' => true]);

        $this->assertSame(4, $r['capacity_allocation']['base_wave_capacity']);
        $this->assertSame(2, $r['capacity_allocation']['effective_wave_capacity']);
    }

    // ── AC: output includes waves, deferred, capacity_allocation, expected_reduction_score, safety_notes ──

    public function test_output_has_all_required_keys(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);

        foreach (['waves', 'deferred', 'capacity_allocation', 'expected_reduction_score', 'safety_notes'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    public function test_expected_reduction_score_sums_eligible_line_reduction(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('a', ['line_reduction' => 30]),
            $this->candidate('b', ['line_reduction' => 70]),
            $this->candidate('deferred-one', ['line_reduction' => 999, 'has_behavior_coverage' => false]),
        ]);

        $this->assertSame(100, $r['expected_reduction_score']);
    }

    public function test_safety_notes_not_empty(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);
        $this->assertNotEmpty($r['safety_notes']);
    }

    // ── overflow into multiple waves ──────────────────────────────────────────

    public function test_eligible_candidates_beyond_capacity_spill_into_second_wave(): void
    {
        $candidates = [];
        for ($i = 0; $i < 7; $i++) {
            $candidates[] = $this->candidate("c-{$i}");
        }

        $r = $this->planner()->plan($candidates, ['wave_capacity' => 5]);

        $this->assertCount(2, $r['waves']);
        $this->assertCount(5, $r['waves'][0]);
        $this->assertCount(2, $r['waves'][1]);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $candidates = [$this->candidate('a'), $this->candidate('b', ['has_behavior_coverage' => false])];
        $a = $this->planner()->plan($candidates);
        $b = $this->planner()->plan($candidates);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_candidates_returns_empty_waves_and_deferred(): void
    {
        $r = $this->planner()->plan([]);
        $this->assertSame([], $r['waves']);
        $this->assertSame([], $r['deferred']);
        $this->assertSame(0, $r['expected_reduction_score']);
    }

    // ── output has the required AC fields ────────────────────────────────────────

    public function test_output_has_autonomy_lane_policy_and_stop_go_decision(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);

        $this->assertArrayHasKey('autonomy_lane_policy', $r);
        $this->assertArrayHasKey('stop_go_decision', $r);
        foreach (['lane', 'recurring', 'cadence', 'min_reserved_capacity', 'crowd_out_protection_active'] as $key) {
            $this->assertArrayHasKey($key, $r['autonomy_lane_policy'], "autonomy_lane_policy missing key: {$key}");
        }
        foreach (['decision', 'reasons'] as $key) {
            $this->assertArrayHasKey($key, $r['stop_go_decision'], "stop_go_decision missing key: {$key}");
        }
    }

    public function test_autonomy_lane_policy_is_recurring(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);

        $this->assertTrue($r['autonomy_lane_policy']['recurring']);
        $this->assertSame('simplification', $r['autonomy_lane_policy']['lane']);
    }

    // ── AC3: build_or_repair_urgent bounds capacity AND defers stop_go_decision ──

    public function test_urgent_build_or_repair_bounds_capacity_and_defers_simplification(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('a')],
            ['wave_capacity' => 4, 'build_or_repair_urgent' => true],
        );

        $this->assertSame(2, $r['capacity_allocation']['effective_wave_capacity']);
        $this->assertSame('defer_to_build_repair', $r['stop_go_decision']['decision']);
        $this->assertTrue($r['autonomy_lane_policy']['crowd_out_protection_active']);
    }

    // ── AC3: high complexity debt + behavior coverage → prioritize_simplification_over_new_feature ──

    public function test_high_complexity_debt_with_eligible_candidates_prioritizes_simplification(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('a', ['line_reduction' => 100])],
            ['complexity_debt_high' => true],
        );

        $this->assertSame('prioritize_simplification_over_new_feature', $r['stop_go_decision']['decision']);
        $this->assertNotEmpty($r['stop_go_decision']['reasons']);
    }

    public function test_high_complexity_debt_without_eligible_candidates_does_not_prioritize(): void
    {
        // All candidates deferred (no behavior coverage) — no real evidence to act on.
        $r = $this->planner()->plan(
            [$this->candidate('a', ['has_behavior_coverage' => false])],
            ['complexity_debt_high' => true],
        );

        $this->assertNotSame('prioritize_simplification_over_new_feature', $r['stop_go_decision']['decision']);
    }

    public function test_normal_conditions_proceed_normal(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);

        $this->assertSame('proceed_normal', $r['stop_go_decision']['decision']);
    }

    public function test_urgent_takes_priority_over_complexity_debt(): void
    {
        // Both urgent AND high debt present — urgent must win (simplification always yields).
        $r = $this->planner()->plan(
            [$this->candidate('a', ['line_reduction' => 100])],
            ['build_or_repair_urgent' => true, 'complexity_debt_high' => true],
        );

        $this->assertSame('defer_to_build_repair', $r['stop_go_decision']['decision']);
    }

    public function test_stop_go_decision_is_deterministic(): void
    {
        $candidates = [$this->candidate('a', ['line_reduction' => 100])];
        $a = $this->planner()->plan($candidates, ['complexity_debt_high' => true]);
        $b = $this->planner()->plan($candidates, ['complexity_debt_high' => true]);

        $this->assertSame(json_encode($a['stop_go_decision']), json_encode($b['stop_go_decision']));
    }
}
