<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueGateBacktestReplay;
use Tests\TestCase;

final class AtlasExternalBrainValueGateBacktestReplayTest extends TestCase
{
    private function replay(): AtlasExternalBrainValueGateBacktestReplay
    {
        return new AtlasExternalBrainValueGateBacktestReplay();
    }

    private function entry(string $outcome, float $impact = 0.80, float $risk = 0.20): array
    {
        return [
            'candidate' => [
                'target'                => 'AtlasFooService',
                'compound_impact_score' => $impact,
                'give_back_risk_score'  => $risk,
            ],
            'outcome' => $outcome,
        ];
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->replay()->replay(['candidates' => []]);
        $this->assertSame(AtlasExternalBrainValueGateBacktestReplay::SCHEMA, $result['schema']);
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_candidates_all_zeros(): void
    {
        $result = $this->replay()->replay(['candidates' => []]);

        $this->assertSame(0, $result['would_admit']);
        $this->assertSame(0, $result['false_reject_green']);
        $this->assertSame(0, $result['true_reject_poison']);
        $this->assertSame(0, $result['missed_poison']);
        $this->assertSame([], $result['recommended_threshold_adjustments']);
    }

    // ── would_admit ───────────────────────────────────────────────────────────

    public function test_would_admit_counts_gate_passing_candidates(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),  // admitted
            $this->entry('commit_success', 0.80, 0.20),  // admitted
            $this->entry('commit_success', 0.10, 0.20),  // rejected (low impact)
        ]]);

        $this->assertSame(2, $result['would_admit']);
    }

    // ── false_reject_green ────────────────────────────────────────────────────

    public function test_false_reject_green_counts_rejected_successful_tasks(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            // impact below floor (0.30) but outcome green → false reject
            $this->entry('commit_success', 0.10, 0.20),
        ]]);

        $this->assertSame(1, $result['false_reject_green']);
        $this->assertSame(0, $result['would_admit']);
    }

    // ── true_reject_poison ────────────────────────────────────────────────────

    public function test_true_reject_poison_on_give_back(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            // rejected (low impact) AND give_back → correct rejection
            $this->entry('give_back', 0.10, 0.20),
        ]]);

        $this->assertSame(1, $result['true_reject_poison']);
        $this->assertSame(0, $result['missed_poison']);
    }

    public function test_true_reject_poison_on_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('poison', 0.10, 0.80),
        ]]);

        $this->assertSame(1, $result['true_reject_poison']);
    }

    // ── missed_poison ─────────────────────────────────────────────────────────

    public function test_missed_poison_when_admitted_but_give_back(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            // admitted (impact ok, risk low) but gave back → missed
            $this->entry('give_back', 0.80, 0.20),
        ]]);

        $this->assertSame(1, $result['missed_poison']);
        $this->assertSame(1, $result['would_admit']);
    }

    public function test_missed_poison_when_admitted_but_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('poison', 0.80, 0.20),
        ]]);

        $this->assertSame(1, $result['missed_poison']);
    }

    // ── threshold adjustments — tighten risk ceiling ──────────────────────────

    public function test_recommends_lower_risk_ceiling_when_missed_poison_rate_high(): void
    {
        // 4 admitted → 2 give_back → missed rate = 0.50 > 0.30
        $candidates = array_merge(
            array_fill(0, 2, $this->entry('give_back', 0.80, 0.20)),
            array_fill(0, 2, $this->entry('commit_success', 0.80, 0.20)),
        );

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $thresholds = array_column($result['recommended_threshold_adjustments'], 'threshold');
        $this->assertContains('give_back_risk_ceiling', $thresholds);
    }

    public function test_recommended_risk_ceiling_is_lower_than_current(): void
    {
        $candidates = array_fill(0, 4, $this->entry('give_back', 0.80, 0.20));

        $result = $this->replay()->replay([
            'candidates'          => $candidates,
            'current_thresholds'  => ['give_back_risk_ceiling' => 0.70],
        ]);

        $adj = array_filter(
            $result['recommended_threshold_adjustments'],
            fn(array $a): bool => $a['threshold'] === 'give_back_risk_ceiling'
        );
        $adj = array_values($adj)[0] ?? null;

        $this->assertNotNull($adj);
        $this->assertLessThan($adj['current_value'], $adj['recommended_value']);
    }

    // ── threshold adjustments — loosen impact floor ───────────────────────────

    public function test_recommends_lower_impact_floor_when_false_reject_rate_high(): void
    {
        // 5 entries: 2 false rejects (green but rejected) → rate = 0.40 > 0.20
        $candidates = array_merge(
            array_fill(0, 2, $this->entry('commit_success', 0.10, 0.20)), // rejected green
            array_fill(0, 3, $this->entry('commit_success', 0.80, 0.20)), // admitted green
        );

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $thresholds = array_column($result['recommended_threshold_adjustments'], 'threshold');
        $this->assertContains('compound_impact_floor', $thresholds);
    }

    public function test_no_adjustment_when_metrics_within_tolerances(): void
    {
        // All succeed and all admitted → no adjustments needed
        $candidates = array_fill(0, 5, $this->entry('commit_success', 0.80, 0.20));

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $this->assertSame([], $result['recommended_threshold_adjustments']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_thresholds_used_for_admission(): void
    {
        // With risk_ceiling=0.10, risk=0.20 → rejected even though default would admit
        $result = $this->replay()->replay([
            'candidates'         => [$this->entry('commit_success', 0.80, 0.20)],
            'current_thresholds' => ['give_back_risk_ceiling' => 0.10],
        ]);

        $this->assertSame(0, $result['would_admit']);
        $this->assertSame(1, $result['false_reject_green']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = ['candidates' => [
            $this->entry('commit_success'),
            $this->entry('give_back', 0.80, 0.20),
        ]];

        $this->assertSame(
            $this->replay()->replay($input),
            $this->replay()->replay($input),
        );
    }

    // ── new output fields: confusion-matrix breakdown ─────────────────────────

    public function test_output_has_new_calibration_fields(): void
    {
        $result = $this->replay()->replay(['candidates' => [$this->entry('commit_success')]]);

        foreach (['admitted_green', 'rejected_green', 'admitted_poison', 'rejected_poison', 'precision', 'recall', 'estimated_token_waste_avoided'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing field: {$key}");
        }
    }

    public function test_confusion_matrix_cells_are_correct(): void
    {
        // 2 admitted green, 1 rejected green, 1 admitted poison, 1 rejected poison
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),  // admitted green
            $this->entry('commit_success', 0.80, 0.20),  // admitted green
            $this->entry('commit_success', 0.10, 0.20),  // rejected green
            $this->entry('give_back',      0.80, 0.20),  // admitted poison
            $this->entry('poison',         0.10, 0.20),  // rejected poison
        ]]);

        $this->assertSame(2, $result['admitted_green']);
        $this->assertSame(1, $result['rejected_green']);
        $this->assertSame(1, $result['admitted_poison']);
        $this->assertSame(1, $result['rejected_poison']);
    }

    public function test_admitted_poison_mirrors_missed_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('give_back', 0.80, 0.20),
        ]]);

        $this->assertSame($result['missed_poison'], $result['admitted_poison']);
    }

    public function test_rejected_green_mirrors_false_reject_green(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.10, 0.20),
        ]]);

        $this->assertSame($result['false_reject_green'], $result['rejected_green']);
    }

    public function test_rejected_poison_mirrors_true_reject_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('poison', 0.10, 0.20),
        ]]);

        $this->assertSame($result['true_reject_poison'], $result['rejected_poison']);
    }

    // ── precision ─────────────────────────────────────────────────────────────

    public function test_precision_is_admitted_green_over_total_admitted(): void
    {
        // 3 admitted green + 1 admitted poison → precision = 3/4 = 0.75
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),
            $this->entry('commit_success', 0.80, 0.20),
            $this->entry('commit_success', 0.80, 0.20),
            $this->entry('give_back',      0.80, 0.20),
        ]]);

        $this->assertEqualsWithDelta(0.75, $result['precision'], 0.000001);
    }

    public function test_precision_is_zero_when_no_candidates(): void
    {
        $result = $this->replay()->replay(['candidates' => []]);

        $this->assertEqualsWithDelta(0.0, $result['precision'], 0.000001);
    }

    public function test_precision_is_one_when_all_admitted_are_green(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),
            $this->entry('commit_success', 0.80, 0.20),
        ]]);

        $this->assertEqualsWithDelta(1.0, $result['precision'], 0.000001);
    }

    // ── recall ────────────────────────────────────────────────────────────────

    public function test_recall_is_admitted_green_over_total_green(): void
    {
        // 2 admitted green + 1 rejected green → recall = 2/3
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),  // admitted
            $this->entry('commit_success', 0.80, 0.20),  // admitted
            $this->entry('commit_success', 0.10, 0.20),  // rejected (below floor)
        ]]);

        $this->assertEqualsWithDelta(2.0 / 3.0, $result['recall'], 0.000001);
    }

    public function test_recall_is_zero_when_no_candidates(): void
    {
        $result = $this->replay()->replay(['candidates' => []]);

        $this->assertEqualsWithDelta(0.0, $result['recall'], 0.000001);
    }

    public function test_recall_is_one_when_all_green_are_admitted(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),
            $this->entry('commit_success', 0.80, 0.20),
        ]]);

        $this->assertEqualsWithDelta(1.0, $result['recall'], 0.000001);
    }

    // ── estimated_token_waste_avoided ─────────────────────────────────────────

    public function test_estimated_token_waste_avoided_uses_default_cost_of_1000(): void
    {
        // 3 rejected poison → 3 × 1000 = 3000
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('poison', 0.10, 0.20),
            $this->entry('poison', 0.10, 0.20),
            $this->entry('poison', 0.10, 0.20),
        ]]);

        $this->assertSame(3000, $result['estimated_token_waste_avoided']);
    }

    public function test_custom_token_cost_per_task_scales_waste_estimate(): void
    {
        $result = $this->replay()->replay([
            'candidates'          => [
                $this->entry('poison', 0.10, 0.20),
                $this->entry('poison', 0.10, 0.20),
            ],
            'token_cost_per_task' => 500,
        ]);

        $this->assertSame(1000, $result['estimated_token_waste_avoided']);
    }

    public function test_estimated_token_waste_avoided_is_zero_when_no_rejected_poison(): void
    {
        $result = $this->replay()->replay(['candidates' => [
            $this->entry('commit_success', 0.80, 0.20),
        ]]);

        $this->assertSame(0, $result['estimated_token_waste_avoided']);
    }

    // ── calibration: tighten when admitted_poison dominates ───────────────────

    public function test_recommends_tighten_when_admitted_poison_dominates_over_admitted_green(): void
    {
        // ag=2, ap=5 (dominates), rp=11 → total=18, missed_rate≈0.278≤0.30 (rate doesn't trigger)
        // admitted_poison_dominates=true → should_tighten=true
        // rejected_dominates=true BUT precision=2/7≈0.286<0.40 → NOT exempt → TIGHTEN
        $candidates = array_merge(
            array_fill(0, 2,  $this->entry('commit_success', 0.80, 0.20)),  // admitted green
            array_fill(0, 5,  $this->entry('give_back',      0.80, 0.20)),  // admitted poison
            array_fill(0, 11, $this->entry('poison',         0.10, 0.20)),  // rejected poison
        );

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $this->assertSame(2, $result['admitted_green']);
        $this->assertSame(5, $result['admitted_poison']);
        $thresholds = array_column($result['recommended_threshold_adjustments'], 'threshold');
        $this->assertContains('give_back_risk_ceiling', $thresholds);
    }

    // ── calibration: no tighten when rejected_poison dominates + precision ok ─

    public function test_no_tighten_when_rejected_poison_dominates_and_precision_is_healthy(): void
    {
        // ag=4, ap=5 (barely dominates) → admitted_poison_dominates=true → should_tighten=true
        // total=17, missed_rate=5/17≈0.294≤0.30 → rate doesn't trigger independently
        // rp=8 > ap=5 → rejected_dominates=true
        // precision=4/9≈0.444 ≥ 0.40 → tighten_exempt=true → NO TIGHTEN
        $candidates = array_merge(
            array_fill(0, 4, $this->entry('commit_success', 0.80, 0.20)),  // admitted green
            array_fill(0, 5, $this->entry('give_back',      0.80, 0.20)),  // admitted poison
            array_fill(0, 8, $this->entry('poison',         0.10, 0.20)),  // rejected poison
        );

        $result = $this->replay()->replay(['candidates' => $candidates]);

        $this->assertSame(4, $result['admitted_green']);
        $this->assertSame(5, $result['admitted_poison']);
        $this->assertSame(8, $result['rejected_poison']);
        $thresholds = array_column($result['recommended_threshold_adjustments'], 'threshold');
        $this->assertNotContains('give_back_risk_ceiling', $thresholds);
    }
}
