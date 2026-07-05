<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeBackpressurePolicy;
use Tests\TestCase;

final class AtlasExternalBrainOutcomeBackpressurePolicyTest extends TestCase
{
    private function policy(): AtlasExternalBrainOutcomeBackpressurePolicy
    {
        return new AtlasExternalBrainOutcomeBackpressurePolicy;
    }

    private function outcomes(string ...$types): array
    {
        return array_map(static fn (string $t): array => ['outcome' => $t], $types);
    }

    private function evaluate(array $history, string $family = 'test-family', array $extra = []): array
    {
        return $this->policy()->evaluate(array_merge([
            'task_family'     => $family,
            'outcome_history' => $history,
        ], $extra));
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_always_present(): void
    {
        $r = $this->evaluate([]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::SCHEMA, $r['schema']);
    }

    public function test_output_keys_present(): void
    {
        $r = $this->evaluate([]);

        foreach (['schema', 'task_family', 'recommendation', 'confidence_score', 'safe_to_promote', 'outcome_summary'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    public function test_task_family_echoed(): void
    {
        $r = $this->evaluate([], 'my-family');

        $this->assertSame('my-family', $r['task_family']);
    }

    // ── AC2: repeated successful commits → promote/continue ───────────────────

    public function test_high_success_rate_recommends_promote(): void
    {
        $r = $this->evaluate($this->outcomes(
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
        ));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_PROMOTE, $r['recommendation']);
        $this->assertGreaterThanOrEqual(0.75, $r['confidence_score']);
        $this->assertTrue($r['safe_to_promote']);
    }

    public function test_confidence_score_computed_correctly(): void
    {
        // 3 success / 4 total = 0.75
        $r = $this->evaluate($this->outcomes('success', 'success', 'success', 'give_back'));

        $this->assertSame(0.75, $r['confidence_score']);
    }

    public function test_empty_history_recommends_continue(): void
    {
        $r = $this->evaluate([]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE, $r['recommendation']);
        $this->assertTrue($r['safe_to_promote']);
    }

    public function test_low_success_below_promote_threshold_recommends_continue(): void
    {
        // 2/3 success = 0.667 < custom promote_threshold=0.90
        // give_back_rate = 1/3 = 0.333 < custom respec_threshold=0.50 → no respec → continue
        $r = $this->evaluate(
            $this->outcomes('success', 'success', 'give_back'),
            'f',
            ['promote_threshold' => 0.90, 'respec_threshold' => 0.50],
        );

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE, $r['recommendation']);
        $this->assertTrue($r['safe_to_promote']);
    }

    // ── AC3: give_back → respec ───────────────────────────────────────────────

    public function test_high_give_back_rate_recommends_respec(): void
    {
        $r = $this->evaluate($this->outcomes('give_back', 'give_back', 'give_back', 'success'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_RESPEC, $r['recommendation']);
        $this->assertFalse($r['safe_to_promote']);
    }

    // ── AC3: quarantine → block ───────────────────────────────────────────────

    public function test_any_quarantine_recommends_block(): void
    {
        $r = $this->evaluate($this->outcomes('success', 'success', 'quarantine'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_BLOCK, $r['recommendation']);
        $this->assertFalse($r['safe_to_promote']);
    }

    // ── AC3: poison → retire ─────────────────────────────────────────────────

    public function test_high_poison_rate_recommends_retire(): void
    {
        $r = $this->evaluate($this->outcomes('poison', 'poison', 'success', 'success', 'success'));

        // poison_rate = 2/5 = 0.40 > 0.30 threshold → retire
        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_RETIRE, $r['recommendation']);
        $this->assertFalse($r['safe_to_promote']);
    }

    // ── decision priority ─────────────────────────────────────────────────────

    public function test_poison_beats_quarantine(): void
    {
        $r = $this->evaluate($this->outcomes('poison', 'poison', 'quarantine'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_RETIRE, $r['recommendation']);
    }

    public function test_quarantine_beats_respec(): void
    {
        $r = $this->evaluate($this->outcomes('give_back', 'give_back', 'quarantine'));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_BLOCK, $r['recommendation']);
    }

    // ── outcome_summary ───────────────────────────────────────────────────────

    public function test_outcome_summary_counts_all_types(): void
    {
        $r = $this->evaluate($this->outcomes('success', 'success', 'give_back', 'quarantine', 'poison'));

        $this->assertSame(2, $r['outcome_summary']['success']);
        $this->assertSame(1, $r['outcome_summary']['give_back']);
        $this->assertSame(1, $r['outcome_summary']['quarantine']);
        $this->assertSame(1, $r['outcome_summary']['poison']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_promote_threshold_respected(): void
    {
        // 13 success / 15 total — Wilson LB ≈ 0.62, above custom threshold 0.60
        $r = $this->evaluate(
            $this->outcomes(
                'success', 'success', 'success', 'success', 'success',
                'success', 'success', 'success', 'success', 'success',
                'success', 'success', 'success', 'give_back', 'give_back',
            ),
            'f',
            ['promote_threshold' => 0.60, 'respec_threshold' => 0.50],
        );

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_PROMOTE, $r['recommendation']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_deterministic(): void
    {
        $input = ['task_family' => 'fam', 'outcome_history' => $this->outcomes('success', 'give_back', 'poison')];

        $this->assertSame($this->policy()->evaluate($input), $this->policy()->evaluate($input));
    }

    // ── evaluateOriginationPace: continue ─────────────────────────────────────

    private function healthyPaceInput(array $overrides = []): array
    {
        return array_merge([
            'queue_depth' => 10,
            'active_muscle_capacity' => 5,
            'give_back_rate' => 0.05,
            'low_lift_commit_rate' => 0.05,
            'poison_class_count' => 0,
        ], $overrides);
    }

    public function test_pace_continue_when_all_signals_healthy(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput());

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_CONTINUE, $result['decision']);
        $this->assertSame(1.0, $result['enqueue_volume_multiplier']);
    }

    // ── stop_enqueue ───────────────────────────────────────────────────────────

    public function test_pace_stop_enqueue_when_poison_class_count_at_floor(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['poison_class_count' => 2]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_STOP_ENQUEUE, $result['decision']);
        $this->assertSame(0.0, $result['enqueue_volume_multiplier']);
    }

    public function test_pace_stop_enqueue_when_give_back_rate_at_stop_ceiling(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['give_back_rate' => 0.50]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_STOP_ENQUEUE, $result['decision']);
    }

    public function test_pace_stop_takes_priority_over_consolidate(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput([
            'poison_class_count' => 3,
            'queue_depth' => 100,
            'active_muscle_capacity' => 1,
        ]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_STOP_ENQUEUE, $result['decision']);
    }

    // ── consolidate (queue saturation) ─────────────────────────────────────────

    public function test_pace_consolidate_when_queue_saturated_relative_to_capacity(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput([
            'queue_depth' => 60,
            'active_muscle_capacity' => 5, // ratio = 12 > ceiling 5
        ]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_CONSOLIDATE, $result['decision']);
        $this->assertLessThan(1.0, $result['enqueue_volume_multiplier']);
    }

    public function test_pace_consolidate_with_zero_active_muscle_capacity_does_not_divide_by_zero(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput([
            'queue_depth' => 10,
            'active_muscle_capacity' => 0,
        ]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_CONSOLIDATE, $result['decision']);
    }

    // ── switch_lane (low lift commits) ─────────────────────────────────────────

    public function test_pace_switch_lane_when_low_lift_commit_rate_high(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['low_lift_commit_rate' => 0.45]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_SWITCH_LANE, $result['decision']);
        $this->assertLessThan(1.0, $result['enqueue_volume_multiplier']);
    }

    // ── slow_down ─────────────────────────────────────────────────────────────

    public function test_pace_slow_down_when_give_back_rate_moderate(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['give_back_rate' => 0.30]));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::PACE_SLOW_DOWN, $result['decision']);
        $this->assertSame(0.5, $result['enqueue_volume_multiplier']);
    }

    // ── AC3: deteriorating quality never leaves volume at full ────────────────

    public function test_no_deteriorating_decision_ever_keeps_full_enqueue_volume(): void
    {
        foreach ([
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_SLOW_DOWN,
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_SWITCH_LANE,
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_CONSOLIDATE,
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_STOP_ENQUEUE,
        ] as $decision) {
            $this->assertLessThan(1.0, $this->multiplierFor($decision), "decision {$decision} must never allow full enqueue volume");
        }
    }

    private function multiplierFor(string $decision): float
    {
        return match ($decision) {
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_SLOW_DOWN => $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['give_back_rate' => 0.30]))['enqueue_volume_multiplier'],
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_SWITCH_LANE => $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['low_lift_commit_rate' => 0.45]))['enqueue_volume_multiplier'],
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_CONSOLIDATE => $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['queue_depth' => 60]))['enqueue_volume_multiplier'],
            AtlasExternalBrainOutcomeBackpressurePolicy::PACE_STOP_ENQUEUE => $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['poison_class_count' => 2]))['enqueue_volume_multiplier'],
            default => 1.0,
        };
    }

    // ── evidence + metrics ──────────────────────────────────────────────────────

    public function test_pace_result_includes_evidence_and_metrics(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput(['give_back_rate' => 0.30]));

        $this->assertNotEmpty($result['evidence']);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('saturation_ratio', $result['metrics']);
    }

    public function test_pace_schema_present(): void
    {
        $result = $this->policy()->evaluateOriginationPace($this->healthyPaceInput());
        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::SCHEMA, $result['schema']);
    }

    public function test_pace_is_deterministic(): void
    {
        $input = $this->healthyPaceInput(['give_back_rate' => 0.30]);

        $this->assertSame(
            $this->policy()->evaluateOriginationPace($input),
            $this->policy()->evaluateOriginationPace($input),
        );
    }

    // ── qualityBackpressure ──

    public function test_quality_backpressure_active_when_valuable_targets_and_high_give_back(): void
    {
        $r = $this->policy()->qualityBackpressure([
            'give_back_rate' => 0.4,
            'valuable_targets_remaining' => true,
        ]);
        $this->assertSame('active', $r['quality_backpressure']);
        $this->assertTrue($r['recommend_smaller_batch']);
        $this->assertFalse($r['idle_waiting']);
    }

    public function test_idle_waiting_when_no_valuable_targets_and_queue_sufficient(): void
    {
        $r = $this->policy()->qualityBackpressure([
            'valuable_targets_remaining' => false,
            'queue_depth' => 5,
        ]);
        $this->assertTrue($r['idle_waiting']);
        $this->assertFalse($r['recommend_smaller_batch']);
        $this->assertFalse($r['recommend_better_batch']);
    }

    public function test_repair_escalation_when_poison_spike(): void
    {
        $r = $this->policy()->qualityBackpressure([
            'recent_poison_count' => 3,
            'valuable_targets_remaining' => true,
        ]);
        $this->assertTrue($r['repair_escalation']);
        $this->assertSame('repair_escalation_triggered_by_quality_signals', $r['reason']);
    }

    public function test_repair_escalation_when_low_commit_yield(): void
    {
        $r = $this->policy()->qualityBackpressure([
            'commit_yield' => 0.2,
            'valuable_targets_remaining' => true,
        ]);
        $this->assertTrue($r['repair_escalation']);
    }

    public function test_recommend_better_batch_when_severe_quality_signals(): void
    {
        $r = $this->policy()->qualityBackpressure([
            'give_back_rate' => 0.6,
            'poison_rate' => 0.4,
            'valuable_targets_remaining' => true,
        ]);
        $this->assertTrue($r['recommend_better_batch']);
        $this->assertFalse($r['recommend_smaller_batch']);
    }

    public function test_no_backpressure_when_healthy_depth_and_valuable_targets(): void
    {
        $r = $this->policy()->qualityBackpressure([
            'give_back_rate' => 0.1,
            'poison_rate' => 0.0,
            'commit_yield' => 0.9,
            'valuable_targets_remaining' => true,
            'queue_depth' => 5,
        ]);
        $this->assertSame('inactive', $r['quality_backpressure']);
        $this->assertFalse($r['idle_waiting']);
        $this->assertFalse($r['recommend_smaller_batch']);
        $this->assertFalse($r['recommend_better_batch']);
        $this->assertFalse($r['repair_escalation']);
    }

    // ── AC: high success rate still recommends promote with safe_to_promote true ──

    public function test_high_success_rate_recommends_promote_with_safe_to_promote_true(): void
    {
        $r = $this->evaluate($this->outcomes(
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
            'success', 'success', 'success', 'success', 'success',
        ));

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_PROMOTE, $r['recommendation']);
        $this->assertTrue($r['safe_to_promote']);
    }

    // ── AC: worker starvation facts trigger escalation even when raw claimable depth is nonzero ──

    public function test_worker_starvation_triggers_escalation_with_nonzero_claimable_depth(): void
    {
        $r = $this->policy()->evaluateWorkerStarvationEscalation([
            'recent_outcomes' => [['outcome' => 'no_claimable_task']],
            'claimable_per_active_worker' => 1.0,
            'historical_success_rate' => 0.95,
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::ESCALATION_REPLENISH_OR_REPAIR, $r['decision']);
    }

    public function test_worker_starvation_below_floor_triggers_escalation(): void
    {
        $r = $this->policy()->evaluateWorkerStarvationEscalation([
            'claimable_per_active_worker' => 1.5,
            'claimable_per_worker_floor' => 2.0,
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::ESCALATION_REPLENISH_OR_REPAIR, $r['decision']);
    }

    public function test_worker_starvation_does_not_trigger_when_healthy(): void
    {
        $r = $this->policy()->evaluateWorkerStarvationEscalation([
            'recent_outcomes' => [['outcome' => 'success']],
            'claimable_per_active_worker' => 5.0,
        ]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::ESCALATION_WAIT_OBSERVE, $r['decision']);
    }

    // ── AC: empty history remains continue rather than fake confidence ──

    public function test_empty_history_remains_continue_not_fake_confidence(): void
    {
        $r = $this->evaluate([]);

        $this->assertSame(AtlasExternalBrainOutcomeBackpressurePolicy::RECOMMENDATION_CONTINUE, $r['recommendation']);
        $this->assertSame(0.0, $r['confidence_score']);
        $this->assertTrue($r['safe_to_promote']);
    }
}
