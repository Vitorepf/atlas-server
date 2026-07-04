<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImpactForecastCalibrator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainImpactForecastCalibratorTest extends TestCase
{
    private function calibrator(): AtlasExternalBrainImpactForecastCalibrator
    {
        return new AtlasExternalBrainImpactForecastCalibrator();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_key_present(): void
    {
        $result = $this->calibrator()->calibrate([], []);

        $this->assertSame(AtlasExternalBrainImpactForecastCalibrator::SCHEMA, $result['schema']);
        $this->assertArrayHasKey('calibrations', $result);
        $this->assertArrayHasKey('repeated_overclaim_flags', $result);
    }

    public function test_empty_inputs_yield_empty_calibrations(): void
    {
        $result = $this->calibrator()->calibrate([], []);

        $this->assertSame([], $result['calibrations']);
        $this->assertSame([], $result['repeated_overclaim_flags']);
    }

    // ── per-family calibration record ─────────────────────────────────────────

    public function test_calibration_record_has_required_keys(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'high']],
            [['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );

        $this->assertCount(1, $result['calibrations']);
        $cal = $result['calibrations'][0];
        foreach ([
            'task_family', 'forecast_error', 'confidence_adjustment',
            'calibration_bias', 'overclaim_rate', 'underclaim_rate',
            'next_forecast_multiplier', 'repeated_overclaim_flags', 'next_ranking_hint',
        ] as $key) {
            $this->assertArrayHasKey($key, $cal, "Missing key: {$key}");
        }
    }

    // ── AC1: calibration_bias, overclaim_rate, underclaim_rate ───────────────

    public function test_overclaim_rate_one_when_every_observation_is_overclaim(): void
    {
        // predicted=high (0.9), actual=give_back (0.0) — gap 0.9 > threshold
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'x', 'predicted_leverage' => 'high'],
                ['task_family' => 'x', 'predicted_leverage' => 'high'],
            ],
            [
                ['task_family' => 'x', 'actual_outcome' => 'give_back'],
                ['task_family' => 'x', 'actual_outcome' => 'give_back'],
            ],
        );

        $cal = $result['calibrations'][0];
        $this->assertEqualsWithDelta(1.0, $cal['overclaim_rate'], 0.01);
        $this->assertEqualsWithDelta(0.0, $cal['underclaim_rate'], 0.01);
        $this->assertGreaterThan(0.0, $cal['calibration_bias']);
    }

    public function test_underclaim_rate_one_when_every_observation_is_underclaim(): void
    {
        // predicted=low (0.1), actual=delivered+high+unlocks → well above
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'x', 'predicted_leverage' => 'low'],
                ['task_family' => 'x', 'predicted_leverage' => 'low'],
            ],
            [
                ['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4],
                ['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4],
            ],
        );

        $cal = $result['calibrations'][0];
        $this->assertEqualsWithDelta(0.0, $cal['overclaim_rate'], 0.01);
        $this->assertEqualsWithDelta(1.0, $cal['underclaim_rate'], 0.01);
        $this->assertLessThan(0.0, $cal['calibration_bias']);
    }

    // ── AC1: next_forecast_multiplier ────────────────────────────────────────

    public function test_overclaiming_reduces_next_forecast_multiplier_below_one(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'give_back']],
        );

        $this->assertLessThan(1.0, $result['calibrations'][0]['next_forecast_multiplier']);
    }

    public function test_underclaiming_raises_next_forecast_multiplier_above_one(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'low']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4]],
        );

        $this->assertGreaterThan(1.0, $result['calibrations'][0]['next_forecast_multiplier']);
    }

    public function test_accurate_prediction_keeps_multiplier_near_one(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'medium']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']],
        );

        $multiplier = $result['calibrations'][0]['next_forecast_multiplier'];
        $this->assertGreaterThanOrEqual(0.9, $multiplier);
        $this->assertLessThanOrEqual(1.1, $multiplier);
    }

    public function test_next_forecast_multiplier_clamped_between_0_5_and_1_5(): void
    {
        // extreme overclaim: predicted=high, actual=give_back multiple times
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'x', 'predicted_leverage' => 'high'],
                ['task_family' => 'x', 'predicted_leverage' => 'high'],
                ['task_family' => 'x', 'predicted_leverage' => 'high'],
            ],
            [
                ['task_family' => 'x', 'actual_outcome' => 'give_back'],
                ['task_family' => 'x', 'actual_outcome' => 'give_back'],
                ['task_family' => 'x', 'actual_outcome' => 'give_back'],
            ],
        );

        $m = $result['calibrations'][0]['next_forecast_multiplier'];
        $this->assertGreaterThanOrEqual(0.5, $m);
        $this->assertLessThanOrEqual(1.5, $m);
    }

    // ── AC2: proxy and no-delta reduce multiplier even with green_evidence ────

    public function test_proxy_with_green_evidence_still_reduces_multiplier(): void
    {
        $withGreen = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'proxy', 'green_evidence' => true]],
        );
        $noGreen = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'proxy', 'green_evidence' => false]],
        );

        // multiplier must be less than 1.0 even with green tests
        $this->assertLessThan(1.0, $withGreen['calibrations'][0]['next_forecast_multiplier']);
        // green_evidence must NOT improve the score for proxy (both should be equal)
        $this->assertEqualsWithDelta(
            $noGreen['calibrations'][0]['next_forecast_multiplier'],
            $withGreen['calibrations'][0]['next_forecast_multiplier'],
            0.001,
        );
    }

    public function test_no_delta_delivered_with_green_evidence_still_reduces_multiplier(): void
    {
        $withGreen = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'none', 'green_evidence' => true]],
        );
        $noGreen = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'none', 'green_evidence' => false]],
        );

        $this->assertLessThan(1.0, $withGreen['calibrations'][0]['next_forecast_multiplier']);
        $this->assertEqualsWithDelta(
            $noGreen['calibrations'][0]['next_forecast_multiplier'],
            $withGreen['calibrations'][0]['next_forecast_multiplier'],
            0.001,
        );
    }

    // ── forecast_error ────────────────────────────────────────────────────────

    public function test_perfect_prediction_yields_zero_forecast_error(): void
    {
        // predicted=high (0.9), actual=delivered+high (0.8) → close, small error
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'high']],
            [['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );

        $this->assertLessThan(0.2, $result['calibrations'][0]['forecast_error']);
    }

    public function test_forecast_error_is_non_negative(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'low']],
            [['task_family' => 'gate', 'actual_outcome' => 'give_back']],
        );

        $this->assertGreaterThanOrEqual(0.0, $result['calibrations'][0]['forecast_error']);
    }

    public function test_large_gap_between_prediction_and_reality_yields_high_forecast_error(): void
    {
        // predicted=high (0.9), actual=give_back (0.0) → error ≈ 0.9
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'refactor', 'predicted_leverage' => 'high']],
            [['task_family' => 'refactor', 'actual_outcome' => 'give_back']],
        );

        $this->assertGreaterThan(0.5, $result['calibrations'][0]['forecast_error']);
    }

    // ── confidence_adjustment ─────────────────────────────────────────────────

    public function test_overclaim_yields_negative_confidence_adjustment(): void
    {
        // predicted=high, actual=give_back → negative adjustment
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'scope_gap', 'predicted_leverage' => 'high']],
            [['task_family' => 'scope_gap', 'actual_outcome' => 'give_back']],
        );

        $this->assertLessThan(0.0, $result['calibrations'][0]['confidence_adjustment']);
    }

    public function test_underclaim_yields_positive_confidence_adjustment(): void
    {
        // predicted=low, actual=delivered+high+unlocks → positive adjustment
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'low']],
            [['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 3]],
        );

        $this->assertGreaterThan(0.0, $result['calibrations'][0]['confidence_adjustment']);
    }

    public function test_confidence_adjustment_clamped_to_minus_one_to_one(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'give_back']],
        );

        $adj = $result['calibrations'][0]['confidence_adjustment'];
        $this->assertGreaterThanOrEqual(-1.0, $adj);
        $this->assertLessThanOrEqual(1.0, $adj);
    }

    // ── actual leverage scoring ───────────────────────────────────────────────

    public function test_downstream_unlocks_increase_actual_leverage_score(): void
    {
        $noUnlocks = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'medium']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']],
        );
        $withUnlocks = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'medium']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium', 'downstream_unlocks' => 4]],
        );

        // With unlocks → closer to or above prediction → lower forecast_error or higher actual
        $this->assertGreaterThanOrEqual(
            $noUnlocks['calibrations'][0]['confidence_adjustment'],
            $withUnlocks['calibrations'][0]['confidence_adjustment'],
        );
    }

    public function test_green_evidence_improves_actual_leverage(): void
    {
        $noGreen = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'high']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']],
        );
        $withGreen = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'high']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium', 'green_evidence' => true]],
        );

        // green_evidence should reduce the negative confidence_adjustment (or make it less negative)
        $this->assertGreaterThanOrEqual(
            $noGreen['calibrations'][0]['confidence_adjustment'],
            $withGreen['calibrations'][0]['confidence_adjustment'],
        );
    }

    public function test_give_back_churn_lowers_actual_leverage(): void
    {
        $noChurn = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'medium']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );
        $highChurn = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'medium']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'give_back_churn' => 3]],
        );

        // Churn → lower actual → lower (or more negative) confidence_adjustment
        $this->assertLessThanOrEqual(
            $noChurn['calibrations'][0]['confidence_adjustment'],
            $highChurn['calibrations'][0]['confidence_adjustment'],
        );
    }

    public function test_delivered_with_no_capability_delta_scores_lower_than_delivered_with_high(): void
    {
        $noDelta = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'medium']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'none']],
        );
        $highDelta = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'medium']],
            [['task_family' => 'x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );

        // none-delta should be more overclaimed (more negative adjustment) than high-delta
        $this->assertLessThan(
            $highDelta['calibrations'][0]['confidence_adjustment'],
            $noDelta['calibrations'][0]['confidence_adjustment'],
        );
    }

    public function test_proxy_outcome_scores_low(): void
    {
        $proxy = $this->calibrator()->calibrate(
            [['task_family' => 'x', 'predicted_leverage' => 'high']],
            [['task_family' => 'x', 'actual_outcome' => 'proxy']],
        );

        // proxy → very low actual → large negative confidence_adjustment
        $this->assertLessThan(0.0, $proxy['calibrations'][0]['confidence_adjustment']);
        $this->assertGreaterThan(0.5, $proxy['calibrations'][0]['forecast_error']);
    }

    // ── next_ranking_hint ─────────────────────────────────────────────────────

    public function test_actual_much_higher_than_predicted_gives_up_hint(): void
    {
        // 3 observations (meets sample-size floor): predicted=low (0.1), actual=delivered+high+unlocks → well above predicted
        $result = $this->calibrator()->calibrate(
            array_fill(0, 3, ['task_family' => 'g', 'predicted_leverage' => 'low']),
            array_fill(0, 3, ['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4]),
        );

        $this->assertSame('up', $result['calibrations'][0]['next_ranking_hint']);
        $this->assertSame(AtlasExternalBrainImpactForecastCalibrator::ADJUSTMENT_STATUS_SUFFICIENT, $result['calibrations'][0]['adjustment_status']);
    }

    public function test_actual_much_lower_than_predicted_gives_down_hint(): void
    {
        // 3 observations (meets sample-size floor): predicted=high (0.9), actual=give_back (0.0) → well below predicted
        $result = $this->calibrator()->calibrate(
            array_fill(0, 3, ['task_family' => 'g', 'predicted_leverage' => 'high']),
            array_fill(0, 3, ['task_family' => 'g', 'actual_outcome' => 'give_back']),
        );

        $this->assertSame('down', $result['calibrations'][0]['next_ranking_hint']);
        $this->assertSame(AtlasExternalBrainImpactForecastCalibrator::ADJUSTMENT_STATUS_SUFFICIENT, $result['calibrations'][0]['adjustment_status']);
    }

    // ── AC2: sample-size floor suppresses aggressive hints ────────────────────

    public function test_single_observation_never_gives_aggressive_hint(): void
    {
        // Same as the up/down cases above, but with only 1 observation — must hold, not chase.
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'high']],
            [['task_family' => 'g', 'actual_outcome' => 'give_back']],
        );

        $cal = $result['calibrations'][0];
        $this->assertSame('hold', $cal['next_ranking_hint']);
        $this->assertSame(AtlasExternalBrainImpactForecastCalibrator::ADJUSTMENT_STATUS_INSUFFICIENT, $cal['adjustment_status']);
        $this->assertSame(1, $cal['sample_size']);
    }

    public function test_single_overperforming_outcome_keeps_hold_with_insufficient_evidence(): void
    {
        // AC2 (overperforming side): single observation where actual >> predicted — must hold, not chase.
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'low']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4]],
        );

        $cal = $result['calibrations'][0];
        $this->assertSame('hold', $cal['next_ranking_hint']);
        $this->assertSame(AtlasExternalBrainImpactForecastCalibrator::ADJUSTMENT_STATUS_INSUFFICIENT, $cal['adjustment_status']);
        $this->assertSame(1, $cal['sample_size']);
    }

    public function test_confidence_band_shrinks_with_sample_size_and_stays_bounded(): void
    {
        // AC4: confidence_band shrinks as sample_size increases and remains bounded between 0 and 1.
        $make = function (int $n): array {
            $forecasts = array_fill(0, $n, ['task_family' => 'g', 'predicted_leverage' => 'medium']);
            $outcomes  = array_fill(0, $n, ['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']);

            return $this->calibrator()->calibrate($forecasts, $outcomes)['calibrations'][0];
        };

        $one  = $make(1);
        $four = $make(4);
        $hundred = $make(100);

        // Bounded [0, 1] for all sample sizes.
        foreach ([$one, $four, $hundred] as $cal) {
            $this->assertGreaterThan(0, $cal['confidence_band']);
            $this->assertLessThanOrEqual(1, $cal['confidence_band']);
        }

        // Shrinks monotonically: 1/sqrt(n) → 1.0, 0.5, 0.1.
        $this->assertEqualsWithDelta(1.0, $one['confidence_band'], 0.001);
        $this->assertEqualsWithDelta(0.5, $four['confidence_band'], 0.001);
        $this->assertEqualsWithDelta(0.1, $hundred['confidence_band'], 0.001);
        $this->assertGreaterThan($four['confidence_band'], $one['confidence_band']);
        $this->assertGreaterThan($hundred['confidence_band'], $four['confidence_band']);
    }

    public function test_calibration_includes_sample_size_confidence_band_and_status(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'medium']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']],
        );

        $cal = $result['calibrations'][0];
        foreach (['sample_size', 'confidence_band', 'confidence_adjustment', 'adjustment_status'] as $k) {
            $this->assertArrayHasKey($k, $cal, "Missing field: {$k}");
        }
    }

    public function test_accurate_prediction_gives_hold_hint(): void
    {
        // predicted=medium (0.5), actual=delivered+medium (0.5) → hold
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'medium']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']],
        );

        $this->assertSame('hold', $result['calibrations'][0]['next_ranking_hint']);
    }

    // ── repeated_overclaim_flags ──────────────────────────────────────────────

    public function test_repeated_overclaim_detected_for_family_with_multiple_overclaims(): void
    {
        $forecasts = [
            ['task_family' => 'scope_gap', 'predicted_leverage' => 'high'],
            ['task_family' => 'scope_gap', 'predicted_leverage' => 'high'],
        ];
        $outcomes = [
            ['task_family' => 'scope_gap', 'actual_outcome' => 'give_back'],
            ['task_family' => 'scope_gap', 'actual_outcome' => 'give_back'],
        ];

        $result = $this->calibrator()->calibrate($forecasts, $outcomes);

        $this->assertNotEmpty($result['repeated_overclaim_flags']);
        $flags = $result['calibrations'][0]['repeated_overclaim_flags'];
        $this->assertNotEmpty($flags);
        $this->assertStringContainsString('repeated_overclaim:scope_gap', $flags[0]);
    }

    public function test_single_observation_does_not_trigger_repeated_overclaim(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'high']],
            [['task_family' => 'gate', 'actual_outcome' => 'give_back']],
        );

        $this->assertSame([], $result['calibrations'][0]['repeated_overclaim_flags']);
        $this->assertSame([], $result['repeated_overclaim_flags']);
    }

    public function test_accurate_family_does_not_appear_in_repeated_overclaim_flags(): void
    {
        $forecasts = [
            ['task_family' => 'gate', 'predicted_leverage' => 'medium'],
            ['task_family' => 'gate', 'predicted_leverage' => 'medium'],
        ];
        $outcomes = [
            ['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium'],
            ['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium'],
        ];

        $result = $this->calibrator()->calibrate($forecasts, $outcomes);

        $this->assertSame([], $result['repeated_overclaim_flags']);
    }

    // ── multi-family ─────────────────────────────────────────────────────────

    public function test_multiple_families_produce_separate_calibration_records(): void
    {
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'alpha', 'predicted_leverage' => 'high'],
                ['task_family' => 'beta', 'predicted_leverage' => 'low'],
            ],
            [
                ['task_family' => 'alpha', 'actual_outcome' => 'delivered', 'capability_delta' => 'high'],
                ['task_family' => 'beta', 'actual_outcome' => 'give_back'],
            ],
        );

        $this->assertCount(2, $result['calibrations']);
        $families = array_column($result['calibrations'], 'task_family');
        $this->assertContains('alpha', $families);
        $this->assertContains('beta', $families);
    }

    public function test_calibrations_are_sorted_by_task_family(): void
    {
        $result = $this->calibrator()->calibrate(
            [
                ['task_family' => 'zzz', 'predicted_leverage' => 'medium'],
                ['task_family' => 'aaa', 'predicted_leverage' => 'medium'],
            ],
            [],
        );

        $families = array_column($result['calibrations'], 'task_family');
        $this->assertSame(['aaa', 'zzz'], $families);
    }

    public function test_outcomes_only_family_produces_calibration_with_zero_predicted_score_default(): void
    {
        // No forecast for 'unknown' family → uses default predicted=0.5
        $result = $this->calibrator()->calibrate(
            [],
            [['task_family' => 'unknown', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );

        $this->assertCount(1, $result['calibrations']);
        $this->assertSame('unknown', $result['calibrations'][0]['task_family']);
        // actual > 0.5 default → up hint or hold
        $this->assertContains($result['calibrations'][0]['next_ranking_hint'], ['up', 'hold']);
    }

    // ── next_batch_constraints ───────────────────────────────────────────────

    public function test_calibration_record_includes_next_batch_constraints_key(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'high']],
            [['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );

        $this->assertArrayHasKey('next_batch_constraints', $result['calibrations'][0]);
        $this->assertNotEmpty($result['calibrations'][0]['next_batch_constraints']);
    }

    public function test_repeated_overclaim_requires_stronger_evidence_next_batch(): void
    {
        $forecasts = array_fill(0, 2, ['task_family' => 'overclaimer', 'predicted_leverage' => 'high']);
        $outcomes = array_fill(0, 2, ['task_family' => 'overclaimer', 'actual_outcome' => 'give_back']);

        $result = $this->calibrator()->calibrate($forecasts, $outcomes);

        $cal = $result['calibrations'][0];
        $this->assertContains('require_stronger_evidence_before_high_leverage_claim', $cal['next_batch_constraints']);
    }

    public function test_down_ranking_hint_caps_next_batch_size(): void
    {
        $result = $this->calibrator()->calibrate(
            array_fill(0, 3, ['task_family' => 'falling', 'predicted_leverage' => 'high']),
            array_fill(0, 3, ['task_family' => 'falling', 'actual_outcome' => 'give_back']),
        );

        $cal = $result['calibrations'][0];
        $this->assertSame('down', $cal['next_ranking_hint']);
        $this->assertContains('cap_batch_size:1', $cal['next_batch_constraints']);
    }

    public function test_up_ranking_hint_with_strong_multiplier_allows_increased_batch_size(): void
    {
        $result = $this->calibrator()->calibrate(
            array_fill(0, 3, ['task_family' => 'rising', 'predicted_leverage' => 'low']),
            array_fill(0, 3, ['task_family' => 'rising', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4, 'green_evidence' => true]),
        );

        $cal = $result['calibrations'][0];
        $this->assertSame('up', $cal['next_ranking_hint']);
        $this->assertContains('eligible_for_increased_batch_size', $cal['next_batch_constraints']);
    }

    // ── AC3: per-source calibration (repeated overclaim traced to a source) ──

    public function test_schema_includes_source_calibration_keys(): void
    {
        $result = $this->calibrator()->calibrate([], []);

        $this->assertArrayHasKey('source_calibrations', $result);
        $this->assertArrayHasKey('repeated_overclaim_sources', $result);
        $this->assertSame([], $result['source_calibrations']);
        $this->assertSame([], $result['repeated_overclaim_sources']);
    }

    public function test_forecasts_without_source_field_produce_no_source_calibrations(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'gate', 'predicted_leverage' => 'high']],
            [['task_family' => 'gate', 'actual_outcome' => 'delivered', 'capability_delta' => 'high']],
        );

        $this->assertSame([], $result['source_calibrations']);
    }

    public function test_repeated_overclaiming_source_is_flagged_and_penalized(): void
    {
        $forecasts = [
            ['task_family' => 'a', 'source' => 'model-x', 'predicted_leverage' => 'high'],
            ['task_family' => 'b', 'source' => 'model-x', 'predicted_leverage' => 'high'],
        ];
        $outcomes = [
            ['task_family' => 'a', 'source' => 'model-x', 'actual_outcome' => 'give_back'],
            ['task_family' => 'b', 'source' => 'model-x', 'actual_outcome' => 'give_back'],
        ];

        $result = $this->calibrator()->calibrate($forecasts, $outcomes);

        $this->assertCount(1, $result['source_calibrations']);
        $sourceCal = $result['source_calibrations'][0];
        $this->assertSame('model-x', $sourceCal['source']);
        $this->assertLessThan(1.0, $sourceCal['next_forecast_multiplier']);
        $this->assertNotEmpty($result['repeated_overclaim_sources']);
        $this->assertStringContainsString('repeated_overclaim:model-x', $result['repeated_overclaim_sources'][0]);
    }

    public function test_accurate_source_does_not_appear_in_repeated_overclaim_sources(): void
    {
        $forecasts = [
            ['task_family' => 'a', 'source' => 'model-y', 'predicted_leverage' => 'medium'],
            ['task_family' => 'b', 'source' => 'model-y', 'predicted_leverage' => 'medium'],
        ];
        $outcomes = [
            ['task_family' => 'a', 'source' => 'model-y', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium'],
            ['task_family' => 'b', 'source' => 'model-y', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium'],
        ];

        $result = $this->calibrator()->calibrate($forecasts, $outcomes);

        $this->assertSame([], $result['repeated_overclaim_sources']);
    }

    public function test_multiple_sources_produce_separate_source_calibration_records(): void
    {
        $forecasts = [
            ['task_family' => 'a', 'source' => 'model-x', 'predicted_leverage' => 'high'],
            ['task_family' => 'a', 'source' => 'model-y', 'predicted_leverage' => 'low'],
        ];
        $outcomes = [
            ['task_family' => 'a', 'source' => 'model-x', 'actual_outcome' => 'delivered', 'capability_delta' => 'high'],
            ['task_family' => 'a', 'source' => 'model-y', 'actual_outcome' => 'delivered', 'capability_delta' => 'high'],
        ];

        $result = $this->calibrator()->calibrate($forecasts, $outcomes);

        $this->assertCount(2, $result['source_calibrations']);
        $sources = array_column($result['source_calibrations'], 'source');
        $this->assertContains('model-x', $sources);
        $this->assertContains('model-y', $sources);
    }

    public function test_accurate_family_has_no_constraint(): void
    {
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'steady', 'predicted_leverage' => 'medium']],
            [['task_family' => 'steady', 'actual_outcome' => 'delivered', 'capability_delta' => 'medium']],
        );

        $cal = $result['calibrations'][0];
        $this->assertSame(['no_constraint'], $cal['next_batch_constraints']);
    }
}
