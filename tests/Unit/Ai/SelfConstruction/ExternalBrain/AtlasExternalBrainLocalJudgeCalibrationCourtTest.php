<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalJudgeCalibrationCourt;
use Tests\TestCase;

final class AtlasExternalBrainLocalJudgeCalibrationCourtTest extends TestCase
{
    private function court(): AtlasExternalBrainLocalJudgeCalibrationCourt
    {
        return new AtlasExternalBrainLocalJudgeCalibrationCourt();
    }

    private function successOutcome(): array
    {
        return ['commit_success' => true, 'give_back' => false, 'duplicate' => false];
    }

    private function failureOutcome(): array
    {
        return ['commit_success' => false, 'give_back' => false, 'duplicate' => false];
    }

    private function giveBackOutcome(): array
    {
        return ['commit_success' => false, 'give_back' => true, 'duplicate' => false];
    }

    private function nOutcomes(int $n, array $outcome): array
    {
        return array_fill(0, $n, $outcome);
    }

    private function judge(string $id, float $scoreAvg, array $outcomes): array
    {
        return ['judge_id' => $id, 'judge_score_avg' => $scoreAvg, 'outcomes' => $outcomes];
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->court()->calibrate([]);

        $this->assertSame(AtlasExternalBrainLocalJudgeCalibrationCourt::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->court()->calibrate([]);

        foreach (['schema', 'calibrated_judges', 'suspect_judges', 'calibration_error',
                  'sample_counts', 'recommended_weight_adjustments'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── uncalibrated ──────────────────────────────────────────────────────────

    public function test_uncalibrated_when_below_min_samples(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.80, $this->nOutcomes(3, $this->successOutcome()))],
        ]);

        $suspectIds = array_column($result['suspect_judges'], 'judge_id');
        $this->assertContains('j1', $suspectIds);
        $this->assertNotContains('j1', $result['calibrated_judges']);
    }

    public function test_uncalibrated_weight_adjustment_is_withhold(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.80, $this->nOutcomes(2, $this->successOutcome()))],
        ]);

        $this->assertSame('withhold', $result['recommended_weight_adjustments']['j1']['direction']);
    }

    public function test_uncalibrated_calibration_error_is_null(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.80, $this->nOutcomes(2, $this->successOutcome()))],
        ]);

        $this->assertNull($result['calibration_error']['j1']);
    }

    // ── calibrated ────────────────────────────────────────────────────────────

    public function test_calibrated_when_score_matches_actual_outcomes(): void
    {
        // All 5 outcomes succeed → actual_mean = 1.0; judge_score = 0.90; bias = -0.10 (within ±0.20)
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.90, $this->nOutcomes(5, $this->successOutcome()))],
        ]);

        $this->assertContains('j1', $result['calibrated_judges']);
        $this->assertEmpty(array_filter($result['suspect_judges'], fn ($s) => $s['judge_id'] === 'j1'));
    }

    public function test_calibrated_weight_adjustment_is_keep_zero(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.90, $this->nOutcomes(5, $this->successOutcome()))],
        ]);

        $this->assertSame('keep', $result['recommended_weight_adjustments']['j1']['direction']);
        $this->assertSame(0.0, $result['recommended_weight_adjustments']['j1']['magnitude']);
    }

    // ── over_optimistic ───────────────────────────────────────────────────────

    public function test_over_optimistic_when_score_much_higher_than_actual(): void
    {
        // All 5 outcomes fail → actual_mean = 0.0; judge_score = 0.90; bias = +0.90 > 0.20
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.90, $this->nOutcomes(5, $this->failureOutcome()))],
        ]);

        $suspect = current(array_filter($result['suspect_judges'], fn ($s) => $s['judge_id'] === 'j1'));
        $this->assertNotFalse($suspect);
        $this->assertSame('over_optimistic', $suspect['issue']);
    }

    public function test_over_optimistic_weight_adjustment_is_decrease(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.90, $this->nOutcomes(5, $this->failureOutcome()))],
        ]);

        $this->assertSame('decrease', $result['recommended_weight_adjustments']['j1']['direction']);
        $this->assertGreaterThan(0.0, $result['recommended_weight_adjustments']['j1']['magnitude']);
    }

    // ── under_optimistic ──────────────────────────────────────────────────────

    public function test_under_optimistic_when_score_much_lower_than_actual(): void
    {
        // All 5 outcomes succeed → actual_mean = 1.0; judge_score = 0.30; bias = -0.70 < -0.20
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.30, $this->nOutcomes(5, $this->successOutcome()))],
        ]);

        $suspect = current(array_filter($result['suspect_judges'], fn ($s) => $s['judge_id'] === 'j1'));
        $this->assertNotFalse($suspect);
        $this->assertSame('under_optimistic', $suspect['issue']);
    }

    public function test_under_optimistic_weight_adjustment_is_increase(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.30, $this->nOutcomes(5, $this->successOutcome()))],
        ]);

        $this->assertSame('increase', $result['recommended_weight_adjustments']['j1']['direction']);
    }

    // ── give_back and duplicate in outcomes ───────────────────────────────────

    public function test_give_back_counts_as_failure(): void
    {
        // 5 give_back outcomes → actual_mean = 0.0; score 0.80 → over_optimistic
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.80, $this->nOutcomes(5, $this->giveBackOutcome()))],
        ]);

        $suspect = current(array_filter($result['suspect_judges'], fn ($s) => $s['judge_id'] === 'j1'));
        $this->assertSame('over_optimistic', $suspect['issue']);
    }

    // ── sample_counts ─────────────────────────────────────────────────────────

    public function test_sample_counts_reflect_outcome_list_size(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [
                $this->judge('j1', 0.80, $this->nOutcomes(7, $this->successOutcome())),
                $this->judge('j2', 0.80, $this->nOutcomes(3, $this->successOutcome())),
            ],
        ]);

        $this->assertSame(7, $result['sample_counts']['j1']);
        $this->assertSame(3, $result['sample_counts']['j2']);
    }

    // ── calibration_error ─────────────────────────────────────────────────────

    public function test_calibration_error_is_zero_when_score_matches_perfect_outcomes(): void
    {
        // Score = 1.0, all success → MAE = |1.0 - 1.0| = 0.0
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 1.0, $this->nOutcomes(5, $this->successOutcome()))],
        ]);

        $this->assertSame(0.0, $result['calibration_error']['j1']);
    }

    public function test_calibration_error_is_positive_when_score_mismatch(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [$this->judge('j1', 0.90, $this->nOutcomes(5, $this->failureOutcome()))],
        ]);

        $this->assertGreaterThan(0.0, $result['calibration_error']['j1']);
    }

    // ── multiple judges ───────────────────────────────────────────────────────

    public function test_multiple_judges_classified_independently(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [
                $this->judge('good', 0.90, $this->nOutcomes(5, $this->successOutcome())),  // calibrated
                $this->judge('bad',  0.90, $this->nOutcomes(5, $this->failureOutcome())),  // over_optimistic
            ],
        ]);

        $this->assertContains('good', $result['calibrated_judges']);
        $suspectIds = array_column($result['suspect_judges'], 'judge_id');
        $this->assertContains('bad', $suspectIds);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'judges' => [
                $this->judge('j1', 0.80, $this->nOutcomes(6, $this->successOutcome())),
            ],
        ];

        $this->assertSame($this->court()->calibrate($input), $this->court()->calibrate($input));
    }
}
