<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalJudgeCalibrationCourt;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalJudgeCalibrationCourtTest extends TestCase
{
    private function court(): AtlasExternalBrainLocalJudgeCalibrationCourt
    {
        return new AtlasExternalBrainLocalJudgeCalibrationCourt;
    }

    private function successOutcome(): array
    {
        return ['commit_success' => true];
    }

    private function failureOutcome(): array
    {
        return ['commit_success' => false];
    }

    // ── AC2: judges below min_samples are uncalibrated, suspect, withheld ────

    public function test_judge_below_min_samples_is_uncalibrated_suspect_and_withheld(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [[
                'judge_id' => 'j1',
                'judge_score_avg' => 0.9,
                'outcomes' => [$this->successOutcome(), $this->successOutcome()],
            ]],
        ]);

        $judgeResult = $result['judge_results']['j1'];
        $this->assertSame(AtlasExternalBrainLocalJudgeCalibrationCourt::STATUS_UNCALIBRATED, $judgeResult['judge_bias_class']);
        $this->assertTrue($judgeResult['withhold_until_min_samples']);
        $this->assertSame('withhold', $result['recommended_weight_adjustments']['j1']['direction']);
        $this->assertContains(['judge_id' => 'j1', 'issue' => 'uncalibrated'], $result['suspect_judges']);
        $this->assertNotContains('j1', $result['calibrated_judges']);
    }

    // ── AC3: over/under-optimistic detected from bias between judge_score_avg and actual ──

    public function test_over_optimistic_judge_is_detected(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [[
                'judge_id' => 'over-1',
                'judge_score_avg' => 0.95,
                'outcomes' => array_fill(0, 5, $this->failureOutcome()),
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLocalJudgeCalibrationCourt::STATUS_OVER_OPTIMISTIC, $result['judge_results']['over-1']['judge_bias_class']);
        $this->assertContains('over-1', array_column($result['suspect_judges'], 'judge_id'));
    }

    public function test_under_optimistic_judge_is_detected(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [[
                'judge_id' => 'under-1',
                'judge_score_avg' => 0.1,
                'outcomes' => array_fill(0, 5, $this->successOutcome()),
            ]],
        ]);

        $this->assertSame(AtlasExternalBrainLocalJudgeCalibrationCourt::STATUS_UNDER_OPTIMISTIC, $result['judge_results']['under-1']['judge_bias_class']);
    }

    // ── AC4: calibration_error/suspect/weight_adjustments are deterministic ──

    public function test_high_calibration_error_marks_judge_suspect_even_without_directional_bias(): void
    {
        // Alternating success/failure with a mid-range judge score keeps bias near
        // zero (not classified over/under optimistic) while MAE stays high.
        $result = $this->court()->calibrate([
            'judges' => [[
                'judge_id' => 'noisy-1',
                'judge_score_avg' => 0.5,
                'outcomes' => [
                    $this->successOutcome(), $this->failureOutcome(),
                    $this->successOutcome(), $this->failureOutcome(),
                    $this->successOutcome(), $this->failureOutcome(),
                ],
            ]],
            'thresholds' => ['calibration_error_ceiling' => 0.01],
        ]);

        $this->assertSame(AtlasExternalBrainLocalJudgeCalibrationCourt::STATUS_CALIBRATED, $result['judge_results']['noisy-1']['judge_bias_class']);
        $this->assertContains(['judge_id' => 'noisy-1', 'issue' => 'calibration_error_too_high'], $result['suspect_judges']);
    }

    public function test_weight_adjustments_are_deterministic_decrease_increase_keep_withhold(): void
    {
        $result = $this->court()->calibrate([
            'judges' => [
                ['judge_id' => 'decrease', 'judge_score_avg' => 0.95, 'outcomes' => array_fill(0, 5, $this->failureOutcome())],
                ['judge_id' => 'increase', 'judge_score_avg' => 0.05, 'outcomes' => array_fill(0, 5, $this->successOutcome())],
                ['judge_id' => 'keep', 'judge_score_avg' => 1.0, 'outcomes' => array_fill(0, 5, $this->successOutcome())],
                ['judge_id' => 'withhold', 'judge_score_avg' => 0.9, 'outcomes' => [$this->successOutcome()]],
            ],
        ]);

        $this->assertSame('decrease', $result['recommended_weight_adjustments']['decrease']['direction']);
        $this->assertSame('increase', $result['recommended_weight_adjustments']['increase']['direction']);
        $this->assertSame('keep', $result['recommended_weight_adjustments']['keep']['direction']);
        $this->assertSame(0.0, $result['recommended_weight_adjustments']['keep']['magnitude']);
        $this->assertSame('withhold', $result['recommended_weight_adjustments']['withhold']['direction']);
        $this->assertContains('keep', $result['calibrated_judges']);
    }
}
