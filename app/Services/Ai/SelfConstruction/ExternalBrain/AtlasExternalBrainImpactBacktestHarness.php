<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure harness: compares predicted leverage against actual muscle outcomes to
 * surface calibration gaps, overclaim patterns, and scorer adjustment hints.
 *
 * Input: scored_tasks — each with predicted_leverage, actual_leverage, actual_outcome.
 *
 * Outcomes that trigger penalty when predicted_leverage >= 5.0:
 *   give_back, proxy_implementation, no_capability_delta
 *
 * Overclaim: predicted - actual >= OVERCLAIM_THRESHOLD (2.0).
 * Calibration score: fraction of tasks where abs(predicted - actual) < 1.0.
 * Hints emitted when overclaim_rate >= 30%, or give_back / proxy penalty > 0.
 */
final class AtlasExternalBrainImpactBacktestHarness
{
    public const SCHEMA = 'atlas.external_brain.impact_backtest_harness.v1';

    public const OVERCLAIM_THRESHOLD = 2.0;

    public const CALIBRATION_GOOD_THRESHOLD = 1.0;

    public const HIGH_LEVERAGE_FLOOR = 5.0;

    public const MIN_OVERCLAIM_RATE_FOR_HINT = 0.30;

    public const PENALTY_OUTCOMES = ['give_back', 'proxy_implementation', 'no_capability_delta'];

    /**
     * @param  array<string,mixed>  $input  scored_tasks list
     * @return array<string,mixed>
     */
    public function backtest(array $input): array
    {
        $tasks = is_array($input['scored_tasks'] ?? null) ? $input['scored_tasks'] : [];

        if (count($tasks) === 0) {
            return [
                'schema_version' => self::SCHEMA,
                'calibration_buckets' => [],
                'overclaim_warnings' => [],
                'scorer_adjustment_hints' => [],
                'penalized_tasks' => [],
                'calibration_score' => null,
                'total_tasks' => 0,
            ];
        }

        $byOutcome = [];
        $overclaims = [];
        $penalized = [];
        $wellCalibrated = 0;

        foreach ($tasks as $t) {
            $id = (string) ($t['task_id'] ?? 'unknown');
            $predicted = (float) ($t['predicted_leverage'] ?? 0.0);
            $actual = (float) ($t['actual_leverage'] ?? 0.0);
            $outcome = (string) ($t['actual_outcome'] ?? 'unknown');
            $delta = $predicted - $actual;

            $byOutcome[$outcome][] = ['predicted' => $predicted, 'actual' => $actual, 'delta' => $delta];

            if ($delta >= self::OVERCLAIM_THRESHOLD) {
                $overclaims[] = [
                    'task_id' => $id,
                    'predicted_leverage' => $predicted,
                    'actual_leverage' => $actual,
                    'overclaim_delta' => $delta,
                    'actual_outcome' => $outcome,
                ];
            }

            if (in_array($outcome, self::PENALTY_OUTCOMES, true) && $predicted >= self::HIGH_LEVERAGE_FLOOR) {
                $penalized[] = [
                    'task_id' => $id,
                    'predicted_leverage' => $predicted,
                    'actual_outcome' => $outcome,
                    'penalty_reason' => 'high_predicted_leverage_with_negative_outcome',
                ];
            }

            if (abs($delta) < self::CALIBRATION_GOOD_THRESHOLD) {
                $wellCalibrated++;
            }
        }

        $calibrationBuckets = [];
        foreach ($byOutcome as $outcome => $entries) {
            $count = count($entries);
            $avgPredicted = array_sum(array_column($entries, 'predicted')) / $count;
            $avgActual = array_sum(array_column($entries, 'actual')) / $count;
            $avgDelta = $avgPredicted - $avgActual;

            $calibrationBuckets[$outcome] = [
                'count' => $count,
                'avg_predicted_leverage' => round($avgPredicted, 2),
                'avg_actual_leverage' => round($avgActual, 2),
                'avg_overclaim_delta' => round($avgDelta, 2),
                'calibration_status' => $this->calibrationStatus($avgDelta),
            ];
        }

        $totalTasks = count($tasks);
        $calibrationScore = round($wellCalibrated / $totalTasks, 2);
        $overclaimeRate = count($overclaims) / $totalTasks;

        $hints = [];
        if ($overclaimeRate >= self::MIN_OVERCLAIM_RATE_FOR_HINT) {
            $hints[] = [
                'hint' => 'reduce_predicted_leverage',
                'reason' => sprintf('overclaim_rate=%.0f%%', $overclaimeRate * 100),
            ];
        }

        $giveBackCount = count(array_filter($penalized, static fn (array $p): bool => $p['actual_outcome'] === 'give_back'));
        if ($giveBackCount > 0) {
            $hints[] = [
                'hint' => 'downweight_give_back_prone_predictions',
                'reason' => sprintf('%d high-predicted tasks resulted in give_back', $giveBackCount),
            ];
        }

        $proxyCount = count(array_filter($penalized, static fn (array $p): bool => $p['actual_outcome'] === 'proxy_implementation'));
        if ($proxyCount > 0) {
            $hints[] = [
                'hint' => 'penalize_proxy_implementation_patterns',
                'reason' => sprintf('%d high-predicted tasks delivered only proxy implementation', $proxyCount),
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'calibration_buckets' => $calibrationBuckets,
            'overclaim_warnings' => $overclaims,
            'scorer_adjustment_hints' => $hints,
            'penalized_tasks' => $penalized,
            'calibration_score' => $calibrationScore,
            'total_tasks' => $totalTasks,
        ];
    }

    private function calibrationStatus(float $avgDelta): string
    {
        if ($avgDelta >= self::OVERCLAIM_THRESHOLD) {
            return 'overclaimed';
        }
        if ($avgDelta <= -self::OVERCLAIM_THRESHOLD) {
            return 'underclaimed';
        }
        if (abs($avgDelta) < self::CALIBRATION_GOOD_THRESHOLD) {
            return 'well_calibrated';
        }
        return 'minor_miss';
    }
}
