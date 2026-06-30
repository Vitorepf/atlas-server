<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Calibrates deterministic local judges against later muscle outcomes, preventing the
 * amplifier from optimising for a judge that does not predict real commits.
 *
 * JUDGE CLASSIFICATION (per judge):
 *   uncalibrated    — sample_count < min_samples
 *   over_optimistic — mean(judge_score - actual_success_rate) > over_optimism_threshold
 *   under_optimistic — mean(judge_score - actual_success_rate) < under_optimism_threshold
 *   calibrated      — all other judges
 *
 * ACTUAL SUCCESS RATE per outcome:
 *   actual = 1.0 if commit_success=true AND give_back=false AND duplicate=false
 *            else 0.0
 *
 * CALIBRATION ERROR (MAE):
 *   mean |judge_score_avg - actual| per outcome, using the per-judge judge_score_avg
 *
 * SUSPECT JUDGES:
 *   over_optimistic, under_optimistic, or calibration_error > calibration_error_ceiling
 *
 * WEIGHT ADJUSTMENT:
 *   over_optimistic  → direction=decrease, magnitude proportional to bias
 *   under_optimistic → direction=increase, magnitude proportional to bias
 *   calibrated       → direction=keep, magnitude=0.0
 *   uncalibrated     → direction=withhold, magnitude=0.0
 *
 * DEFAULT THRESHOLDS:
 *   min_samples                = 5
 *   over_optimism_threshold    = 0.20
 *   under_optimism_threshold   = -0.20   (signed, so negative means judge is pessimistic)
 *   calibration_error_ceiling  = 0.15
 *
 * INPUT:
 *   judges: list<{
 *     judge_id:        string
 *     judge_score_avg: float   (average local score this judge assigns)
 *     outcomes: list<{
 *       commit_success: bool
 *       give_back?:     bool   (default false)
 *       value_proof?:   bool   (default false)
 *       duplicate?:     bool   (default false)
 *     }>
 *   }>
 *   thresholds?: { min_samples, over_optimism_threshold, under_optimism_threshold,
 *                  calibration_error_ceiling }
 *
 * OUTPUT:
 *   { schema, calibrated_judges, suspect_judges, calibration_error,
 *     sample_counts, recommended_weight_adjustments }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainLocalJudgeCalibrationCourt
{
    public const SCHEMA = 'atlas.external_brain.local_judge_calibration_court.v1';

    public const STATUS_CALIBRATED      = 'calibrated';
    public const STATUS_OVER_OPTIMISTIC = 'over_optimistic';
    public const STATUS_UNDER_OPTIMISTIC = 'under_optimistic';
    public const STATUS_UNCALIBRATED    = 'uncalibrated';

    private const DEFAULT_MIN_SAMPLES               = 5;
    private const DEFAULT_OVER_OPTIMISM_THRESHOLD   = 0.20;
    private const DEFAULT_UNDER_OPTIMISM_THRESHOLD  = -0.20;
    private const DEFAULT_CALIBRATION_ERROR_CEILING = 0.15;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function calibrate(array $input): array
    {
        $judges     = is_array($input['judges'] ?? null) ? $input['judges'] : [];
        $thresholds = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];

        $minSamples             = (int) ($thresholds['min_samples'] ?? self::DEFAULT_MIN_SAMPLES);
        $overThreshold          = (float) ($thresholds['over_optimism_threshold'] ?? self::DEFAULT_OVER_OPTIMISM_THRESHOLD);
        $underThreshold         = (float) ($thresholds['under_optimism_threshold'] ?? self::DEFAULT_UNDER_OPTIMISM_THRESHOLD);
        $calibrationErrCeiling  = (float) ($thresholds['calibration_error_ceiling'] ?? self::DEFAULT_CALIBRATION_ERROR_CEILING);

        $calibratedJudges           = [];
        $suspectJudges              = [];
        $calibrationError           = [];
        $sampleCounts               = [];
        $recommendedWeightAdjustments = [];

        foreach ($judges as $judge) {
            if (! is_array($judge) || ! isset($judge['judge_id'])) {
                continue;
            }

            $judgeId       = (string) $judge['judge_id'];
            $judgeScore    = (float) ($judge['judge_score_avg'] ?? 0.0);
            $outcomes      = is_array($judge['outcomes'] ?? null) ? $judge['outcomes'] : [];
            $sampleCount   = count($outcomes);

            $sampleCounts[$judgeId] = $sampleCount;

            if ($sampleCount < $minSamples) {
                $calibrationError[$judgeId]           = null;
                $recommendedWeightAdjustments[$judgeId] = ['direction' => 'withhold', 'magnitude' => 0.0];
                $suspectJudges[] = ['judge_id' => $judgeId, 'issue' => self::STATUS_UNCALIBRATED];
                continue;
            }

            // Compute per-outcome actuals and MAE.
            $actuals = [];
            foreach ($outcomes as $outcome) {
                $commitSuccess = (bool) ($outcome['commit_success'] ?? false);
                $giveBack      = (bool) ($outcome['give_back'] ?? false);
                $duplicate     = (bool) ($outcome['duplicate'] ?? false);
                $actuals[]     = ($commitSuccess && ! $giveBack && ! $duplicate) ? 1.0 : 0.0;
            }

            $actualMean = array_sum($actuals) / $sampleCount;
            $mae        = 0.0;
            foreach ($actuals as $actual) {
                $mae += abs($judgeScore - $actual);
            }
            $mae /= $sampleCount;

            $bias = $judgeScore - $actualMean;

            $calibrationError[$judgeId] = round($mae, 6);

            // Classify.
            if ($bias > $overThreshold) {
                $status = self::STATUS_OVER_OPTIMISTIC;
            } elseif ($bias < $underThreshold) {
                $status = self::STATUS_UNDER_OPTIMISTIC;
            } else {
                $status = self::STATUS_CALIBRATED;
            }

            // Suspect if classified as biased OR error exceeds ceiling.
            $isSuspect = $status !== self::STATUS_CALIBRATED || $mae > $calibrationErrCeiling;

            if ($isSuspect) {
                $issue = $status !== self::STATUS_CALIBRATED
                    ? $status
                    : 'calibration_error_too_high';
                $suspectJudges[] = ['judge_id' => $judgeId, 'issue' => $issue];
            } else {
                $calibratedJudges[] = $judgeId;
            }

            // Weight adjustment.
            $magnitude = round(min(1.0, abs($bias) * 2), 4);
            $direction = match ($status) {
                self::STATUS_OVER_OPTIMISTIC  => 'decrease',
                self::STATUS_UNDER_OPTIMISTIC => 'increase',
                default                       => 'keep',
            };
            $recommendedWeightAdjustments[$judgeId] = [
                'direction' => $direction,
                'magnitude' => $direction === 'keep' ? 0.0 : $magnitude,
            ];
        }

        return [
            'schema'                       => self::SCHEMA,
            'calibrated_judges'            => $calibratedJudges,
            'suspect_judges'               => $suspectJudges,
            'calibration_error'            => $calibrationError,
            'sample_counts'                => $sampleCounts,
            'recommended_weight_adjustments' => $recommendedWeightAdjustments,
        ];
    }
}
