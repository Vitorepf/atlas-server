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
 *   min_distinct_categories    = 2
 *
 * REPLAY CASE CATEGORIES (per outcome, priority order — first match wins):
 *   false_green  — commit_success=true but false_green=true (judge said pass, later proven wrong)
 *   proxy        — proxy=true
 *   give_back    — give_back=true
 *   high_value   — high_value=true
 *   normal       — none of the above
 *
 * PROMOTION DECISION (per judge, first match wins):
 *   escalate — sample_count < min_samples, OR any false_green outcome in the replay set
 *   shadow   — enough samples but fewer than min_distinct_categories replay categories covered
 *              (parity is never proven on narrow, undiverse replay evidence)
 *   demote   — suspect (over_optimistic, under_optimistic, or calibration_error over ceiling)
 *   promote  — calibrated, diverse replay evidence, zero false_green outcomes
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
 *       proxy?:          bool   (default false)
 *       false_green?:    bool   (default false)
 *       high_value?:     bool   (default false)
 *     }>
 *   }>
 *   thresholds?: { min_samples, over_optimism_threshold, under_optimism_threshold,
 *                  calibration_error_ceiling, min_distinct_categories }
 *
 * OUTPUT:
 *   { schema, calibrated_judges, suspect_judges, calibration_error,
 *     sample_counts, recommended_weight_adjustments, judge_results }
 *   judge_results[judge_id] adds: promotion_decision, promotion_reason,
 *   category_breakdown, distinct_categories_covered.
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

    public const DECISION_PROMOTE  = 'promote';
    public const DECISION_SHADOW   = 'shadow';
    public const DECISION_DEMOTE   = 'demote';
    public const DECISION_ESCALATE = 'escalate';

    public const CATEGORY_FALSE_GREEN = 'false_green';
    public const CATEGORY_PROXY       = 'proxy';
    public const CATEGORY_GIVE_BACK   = 'give_back';
    public const CATEGORY_HIGH_VALUE  = 'high_value';
    public const CATEGORY_NORMAL      = 'normal';

    private const DEFAULT_MIN_SAMPLES               = 5;
    private const DEFAULT_OVER_OPTIMISM_THRESHOLD   = 0.20;
    private const DEFAULT_UNDER_OPTIMISM_THRESHOLD  = -0.20;
    private const DEFAULT_CALIBRATION_ERROR_CEILING = 0.15;
    private const DEFAULT_MIN_DISTINCT_CATEGORIES   = 2;

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
        $minDistinctCategories  = (int) ($thresholds['min_distinct_categories'] ?? self::DEFAULT_MIN_DISTINCT_CATEGORIES);

        $calibratedJudges           = [];
        $suspectJudges              = [];
        $calibrationError           = [];
        $sampleCounts               = [];
        $recommendedWeightAdjustments = [];
        $judgeResults               = [];

        foreach ($judges as $judge) {
            if (! is_array($judge) || ! isset($judge['judge_id'])) {
                continue;
            }

            $judgeId       = (string) $judge['judge_id'];
            $judgeScore    = (float) ($judge['judge_score_avg'] ?? 0.0);
            $outcomes      = is_array($judge['outcomes'] ?? null) ? $judge['outcomes'] : [];
            $sampleCount   = count($outcomes);

            $sampleCounts[$judgeId] = $sampleCount;

            $categoryBreakdown = $this->categoryBreakdown($outcomes);
            $distinctCategories = count(array_filter($categoryBreakdown));
            $falseGreenCount = $categoryBreakdown[self::CATEGORY_FALSE_GREEN];

            if ($sampleCount < $minSamples) {
                $adj = ['direction' => 'withhold', 'magnitude' => 0.0];
                $calibrationError[$judgeId]             = null;
                $recommendedWeightAdjustments[$judgeId] = $adj;
                $judgeResults[$judgeId] = [
                    'judge_id'                      => $judgeId,
                    'judge_bias_class'              => self::STATUS_UNCALIBRATED,
                    'calibration_error'             => null,
                    'outcome_sample_count'          => $sampleCount,
                    'recommended_weight_adjustment' => $adj,
                    'withhold_until_min_samples'    => true,
                    'category_breakdown'            => $categoryBreakdown,
                    'distinct_categories_covered'   => $distinctCategories,
                    'promotion_decision'            => self::DECISION_ESCALATE,
                    'promotion_reason'               => 'uncalibrated_insufficient_samples',
                ];
                $suspectJudges[] = ['judge_id' => $judgeId, 'issue' => self::STATUS_UNCALIBRATED];
                continue;
            }

            // Compute per-outcome actuals and MAE.
            // give_back, proxy and false_green all count as failure (AC2).
            $actuals = [];
            foreach ($outcomes as $outcome) {
                $commitSuccess = (bool) ($outcome['commit_success'] ?? false);
                $giveBack      = (bool) ($outcome['give_back'] ?? false);
                $proxy         = (bool) ($outcome['proxy'] ?? false);
                $duplicate     = (bool) ($outcome['duplicate'] ?? false);
                $falseGreen    = (bool) ($outcome['false_green'] ?? false);
                $actuals[]     = ($commitSuccess && ! $giveBack && ! $proxy && ! $duplicate && ! $falseGreen) ? 1.0 : 0.0;
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
            $adj = ['direction' => $direction, 'magnitude' => $direction === 'keep' ? 0.0 : $magnitude];
            $recommendedWeightAdjustments[$judgeId] = $adj;

            [$promotionDecision, $promotionReason] = match (true) {
                $falseGreenCount > 0 => [self::DECISION_ESCALATE, 'false_green_detected'],
                $distinctCategories < $minDistinctCategories => [self::DECISION_SHADOW, 'insufficient_diverse_replay_evidence'],
                $isSuspect => [self::DECISION_DEMOTE, $issue],
                default => [self::DECISION_PROMOTE, 'calibrated_with_diverse_evidence'],
            };

            $judgeResults[$judgeId] = [
                'judge_id'                      => $judgeId,
                'judge_bias_class'              => $status,
                'calibration_error'             => round($mae, 6),
                'outcome_sample_count'          => $sampleCount,
                'recommended_weight_adjustment' => $adj,
                'withhold_until_min_samples'    => false,
                'category_breakdown'            => $categoryBreakdown,
                'distinct_categories_covered'   => $distinctCategories,
                'promotion_decision'            => $promotionDecision,
                'promotion_reason'               => $promotionReason,
            ];
        }

        return [
            'schema'                         => self::SCHEMA,
            'calibrated_judges'              => $calibratedJudges,
            'suspect_judges'                 => $suspectJudges,
            'calibration_error'              => $calibrationError,
            'sample_counts'                  => $sampleCounts,
            'recommended_weight_adjustments' => $recommendedWeightAdjustments,
            'judge_results'                  => $judgeResults,
            'calibrated_thresholds'          => $this->buildCalibratedThresholds($thresholds, $calibratedJudges, $suspectJudges),
            'failed_examples'               => $this->buildFailedExamples($judges),
            'next_gate_repair_hint'          => $this->buildNextGateRepairHint($suspectJudges, $calibratedJudges),
        ];
    }

    /**
     * Categorizes each replay outcome (first-match-wins priority: false_green > proxy >
     * give_back > high_value > normal) so promotion decisions never rest on parity proven
     * against a single narrow replay category.
     *
     * @param  list<array<string,mixed>>  $outcomes
     * @return array<string,int>
     */
    private function categoryBreakdown(array $outcomes): array
    {
        $counts = [
            self::CATEGORY_FALSE_GREEN => 0,
            self::CATEGORY_PROXY       => 0,
            self::CATEGORY_GIVE_BACK   => 0,
            self::CATEGORY_HIGH_VALUE  => 0,
            self::CATEGORY_NORMAL      => 0,
        ];

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            $category = match (true) {
                (bool) ($outcome['false_green'] ?? false) => self::CATEGORY_FALSE_GREEN,
                (bool) ($outcome['proxy'] ?? false) => self::CATEGORY_PROXY,
                (bool) ($outcome['give_back'] ?? false) => self::CATEGORY_GIVE_BACK,
                (bool) ($outcome['high_value'] ?? false) => self::CATEGORY_HIGH_VALUE,
                default => self::CATEGORY_NORMAL,
            };
            $counts[$category]++;
        }

        return $counts;
    }

    /**
     * Build calibrated_thresholds from the current thresholds and calibration results.
     *
     * @param  array<string, mixed>  $thresholds
     * @param  list<string>  $calibratedJudges
     * @param  list<array<string, string>>  $suspectJudges
     * @return array{min_samples:int,over_optimism_threshold:float,under_optimism_threshold:float,calibration_error_ceiling:float,calibrated_count:int,suspect_count:int}
     */
    private function buildCalibratedThresholds(array $thresholds, array $calibratedJudges, array $suspectJudges): array
    {
        return [
            'min_samples' => (int) ($thresholds['min_samples'] ?? self::DEFAULT_MIN_SAMPLES),
            'over_optimism_threshold' => (float) ($thresholds['over_optimism_threshold'] ?? self::DEFAULT_OVER_OPTIMISM_THRESHOLD),
            'under_optimism_threshold' => (float) ($thresholds['under_optimism_threshold'] ?? self::DEFAULT_UNDER_OPTIMISM_THRESHOLD),
            'calibration_error_ceiling' => (float) ($thresholds['calibration_error_ceiling'] ?? self::DEFAULT_CALIBRATION_ERROR_CEILING),
            'calibrated_count' => count($calibratedJudges),
            'suspect_count' => count($suspectJudges),
        ];
    }

    /**
     * Build failed_examples: outcomes where the judge approved poison/proxy or rejected known-good.
     *
     * @param  list<array<string, mixed>>  $judges
     * @return list<array{judge_id:string,outcome_category:string,issue:string}>
     */
    private function buildFailedExamples(array $judges): array
    {
        $failed = [];
        foreach ($judges as $judge) {
            if (! is_array($judge) || ! isset($judge['judge_id'])) {
                continue;
            }
            $judgeId = (string) $judge['judge_id'];
            foreach ((array) ($judge['outcomes'] ?? []) as $outcome) {
                if ((bool) ($outcome['false_green'] ?? false)) {
                    $failed[] = ['judge_id' => $judgeId, 'outcome_category' => 'false_green', 'issue' => 'gate_approved_poison'];
                } elseif ((bool) ($outcome['proxy'] ?? false)) {
                    $failed[] = ['judge_id' => $judgeId, 'outcome_category' => 'proxy', 'issue' => 'gate_approved_proxy'];
                } elseif ((bool) ($outcome['give_back'] ?? false)) {
                    $failed[] = ['judge_id' => $judgeId, 'outcome_category' => 'give_back', 'issue' => 'gate_approved_low_value'];
                }
            }
        }

        return $failed;
    }

    /**
     * Build next_gate_repair_hint based on suspect judges.
     *
     * @param  list<array<string, string>>  $suspectJudges
     * @param  list<string>  $calibratedJudges
     * @return string
     */
    private function buildNextGateRepairHint(array $suspectJudges, array $calibratedJudges): string
    {
        if ($suspectJudges === []) {
            return 'no_repair_needed_all_judges_calibrated';
        }

        $issues = array_column($suspectJudges, 'issue');
        $uniqueIssues = array_unique($issues);

        if (in_array(self::STATUS_OVER_OPTIMISTIC, $uniqueIssues, true)) {
            return 'decrease_judge_weight: over_optimistic judges approving poison or proxy tasks';
        }

        if (in_array(self::STATUS_UNDER_OPTIMISTIC, $uniqueIssues, true)) {
            return 'increase_judge_weight: under_optimistic judges rejecting known-good tasks';
        }

        if (in_array(self::STATUS_UNCALIBRATED, $uniqueIssues, true)) {
            return 'collect_more_samples: judges need more outcome data before calibration';
        }

        return 'review_suspect_judges: calibration_error exceeds ceiling';
    }
}
