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

    // ── worker-floor guard ────────────────────────────────────────────────────

    public function test_low_queue_pressure_below_worker_floor_caps_capacity_at_one(): void
    {
        $candidates = [
            $this->candidate('a', ['line_reduction' => 10]),
            $this->candidate('b', ['line_reduction' => 10]),
            $this->candidate('c', ['line_reduction' => 10]),
        ];
        $r = $this->planner()->plan($candidates, [
            'queue_pressure' => 'low',
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertTrue($r['worker_floor_guarded']);
        $this->assertSame(1, $r['capacity_allocation']['effective_wave_capacity']);
        $this->assertCount(1, $r['waves'][0]);
    }

    public function test_healthy_queue_depth_keeps_worker_floor_guarded_false(): void
    {
        $candidates = [
            $this->candidate('a', ['line_reduction' => 10]),
            $this->candidate('b', ['line_reduction' => 10]),
        ];
        $r = $this->planner()->plan($candidates, [
            'queue_pressure' => 'normal',
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertFalse($r['worker_floor_guarded']);
        $this->assertSame(5, $r['capacity_allocation']['effective_wave_capacity']);
    }

    public function test_low_pressure_above_worker_floor_is_not_guarded(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')], [
            'queue_pressure' => 'low',
            'claimable_per_active_worker' => 5.0,
        ]);

        $this->assertFalse($r['worker_floor_guarded']);
    }

    // ── AC1: deletion-first circuit consolidation ranks ahead of similar additive cleanup ──

    public function test_deletion_first_circuit_consolidation_ranks_ahead_of_additive_cleanup_with_similar_reduction(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('additive-cleanup', ['line_reduction' => 55]),
            $this->candidate('deletion-first', ['line_reduction' => 50, 'is_deletion_first' => true]),
        ]);

        $this->assertSame(['deletion-first', 'additive-cleanup'], $r['waves'][0]);
    }

    // ── AC2: missing consumer impact / rollback proof / knowledge sync defers with precise prework ──

    public function test_missing_consumer_impact_defers_with_precise_prework(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('unsafe-consumer', ['consumer_impact_safe' => false]),
        ]);

        $this->assertSame([], $r['waves']);
        $this->assertContains('resolve_consumer_impact', $r['deferred'][0]['required_prework']);
    }

    public function test_missing_rollback_proof_defers_with_precise_prework(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('no-rollback-proof', ['has_rollback_proof' => false]),
        ]);

        $this->assertSame([], $r['waves']);
        $this->assertContains('provide_rollback_proof', $r['deferred'][0]['required_prework']);
    }

    public function test_missing_knowledge_sync_evidence_defers_with_precise_prework(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('no-knowledge-sync', ['has_knowledge_sync_evidence' => false]),
        ]);

        $this->assertSame([], $r['waves']);
        $this->assertContains('provide_knowledge_sync_evidence', $r['deferred'][0]['required_prework']);
    }

    // ── AC4: consolidation_score exposed alongside expected_reduction_score ────

    public function test_consolidation_score_counts_deletion_first_and_circuit_consolidating_candidates(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('a', ['is_deletion_first' => true]),
            $this->candidate('b', ['consolidates_circuit' => true]),
            $this->candidate('c'),
        ]);

        $this->assertSame(2, $r['consolidation_score']);
        $this->assertArrayHasKey('expected_reduction_score', $r);
        $this->assertArrayHasKey('safety_notes', $r);
    }

    public function test_consolidation_score_zero_when_no_deletion_or_consolidation_candidates(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);

        $this->assertSame(0, $r['consolidation_score']);
    }

    // ── proof-first ordering: parity proof + consumer safety beats raw line reduction ──

    public function test_behavior_parity_proof_with_consumer_impact_safe_ranks_ahead_of_larger_unproven_candidate(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('big-unproven', ['line_reduction' => 500]),
            $this->candidate('small-proven', [
                'line_reduction' => 10,
                'behavior_parity_proof' => true,
                'consumer_impact_safe' => true,
            ]),
        ]);

        $this->assertSame(['small-proven', 'big-unproven'], $r['waves'][0]);
    }

    public function test_behavior_parity_proof_without_consumer_impact_safe_does_not_get_priority(): void
    {
        // proof alone, without consumer_impact_safe explicitly true, must not jump the queue.
        $r = $this->planner()->plan([
            $this->candidate('big-unproven', ['line_reduction' => 500]),
            $this->candidate('proof-only', [
                'line_reduction' => 10,
                'behavior_parity_proof' => true,
                'consumer_impact_safe' => false,
            ]),
        ]);

        // proof-only is deferred (consumer_impact_safe=false), so only big-unproven is eligible.
        $this->assertSame(['big-unproven'], $r['waves'][0]);
    }

    // ── AC2 regression: still defers on missing rollback/consumer/knowledge-sync evidence ──

    public function test_deferred_candidates_still_list_precise_required_prework_alongside_proof_priority(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('proven-safe', ['behavior_parity_proof' => true, 'consumer_impact_safe' => true]),
            $this->candidate('missing-rollback', ['has_rollback_proof' => false]),
            $this->candidate('missing-knowledge-sync', ['has_knowledge_sync_evidence' => false]),
        ]);

        $this->assertContains('proven-safe', $r['waves'][0]);
        $this->assertCount(2, $r['deferred']);
        $deferredIds = array_column($r['deferred'], 'candidate_id');
        $this->assertContains('missing-rollback', $deferredIds);
        $this->assertContains('missing-knowledge-sync', $deferredIds);
    }

    // ── AC3 regression: stop_go_decision only prioritizes with high debt AND eligible safe candidates ──

    public function test_stop_go_decision_still_requires_both_high_debt_and_eligible_candidates_with_proof_field_present(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('a', ['behavior_parity_proof' => true, 'consumer_impact_safe' => true, 'line_reduction' => 50])],
            ['complexity_debt_high' => true],
        );
        $this->assertSame('prioritize_simplification_over_new_feature', $r['stop_go_decision']['decision']);

        $rNoDebt = $this->planner()->plan(
            [$this->candidate('a', ['behavior_parity_proof' => true, 'consumer_impact_safe' => true, 'line_reduction' => 50])],
        );
        $this->assertSame('proceed_normal', $rNoDebt['stop_go_decision']['decision']);

        $rNoEligible = $this->planner()->plan(
            [$this->candidate('a', ['has_behavior_coverage' => false])],
            ['complexity_debt_high' => true],
        );
        $this->assertNotSame('prioritize_simplification_over_new_feature', $rNoEligible['stop_go_decision']['decision']);
    }

    // ── AC: high-risk or hard-rollback candidates are deferred when worker capacity is low ──

    public function test_high_risk_candidate_deferred_when_capacity_low(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('high-risk', ['dependency_risk' => 'high'])],
            ['wave_capacity' => 1, 'queue_pressure' => 'low', 'claimable_per_active_worker' => 1.0],
        );

        $deferredIds = array_column($r['deferred'], 'candidate_id');
        $this->assertContains('high-risk', $deferredIds);
    }

    public function test_hard_rollback_candidate_deferred_when_capacity_low(): void
    {
        $r = $this->planner()->plan(
            [$this->candidate('hard-rollback', ['rollback_ease' => 'hard'])],
            ['wave_capacity' => 1, 'queue_pressure' => 'low', 'claimable_per_active_worker' => 1.0],
        );

        $deferredIds = array_column($r['deferred'], 'candidate_id');
        $this->assertContains('hard-rollback', $deferredIds);
    }

    // ── AC: low-risk high-ROI candidates are ordered first within capacity ──

    public function test_low_risk_high_roi_candidates_ordered_first(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('high-risk-low-roi', ['dependency_risk' => 'high', 'line_reduction' => 10]),
            $this->candidate('low-risk-high-roi', ['dependency_risk' => 'low', 'line_reduction' => 100]),
        ]);

        $this->assertSame('low-risk-high-roi', $r['waves'][0][0]);
    }

    // ── AC: every wave emits rollback_bound, worker_capacity_used and deferred_reason ──

    public function test_output_includes_rollback_bound_and_worker_capacity_used(): void
    {
        $r = $this->planner()->plan([$this->candidate('a')]);

        $this->assertArrayHasKey('rollback_bound', $r);
        $this->assertArrayHasKey('worker_capacity_used', $r);
        $this->assertTrue($r['rollback_bound']);
        $this->assertSame(1, $r['worker_capacity_used']);
    }

    public function test_deferred_candidates_include_deferred_reason(): void
    {
        $r = $this->planner()->plan([
            $this->candidate('missing-coverage', ['has_behavior_coverage' => false]),
        ]);

        $this->assertArrayHasKey('deferred_reason', $r['deferred'][0]);
        $this->assertSame('missing_required_prework', $r['deferred'][0]['deferred_reason']);
    }
}
