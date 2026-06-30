<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImpactForecastCalibrator;
use Tests\TestCase;

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
        $this->assertArrayHasKey('task_family', $cal);
        $this->assertArrayHasKey('forecast_error', $cal);
        $this->assertArrayHasKey('confidence_adjustment', $cal);
        $this->assertArrayHasKey('repeated_overclaim_flags', $cal);
        $this->assertArrayHasKey('next_ranking_hint', $cal);
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
        // predicted=low (0.1), actual=delivered+high+unlocks → well above predicted
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'low']],
            [['task_family' => 'g', 'actual_outcome' => 'delivered', 'capability_delta' => 'high', 'downstream_unlocks' => 4]],
        );

        $this->assertSame('up', $result['calibrations'][0]['next_ranking_hint']);
    }

    public function test_actual_much_lower_than_predicted_gives_down_hint(): void
    {
        // predicted=high (0.9), actual=give_back (0.0) → well below predicted
        $result = $this->calibrator()->calibrate(
            [['task_family' => 'g', 'predicted_leverage' => 'high']],
            [['task_family' => 'g', 'actual_outcome' => 'give_back']],
        );

        $this->assertSame('down', $result['calibrations'][0]['next_ranking_hint']);
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
}
