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
 *
 * proof_strength / runtime_evidence (optional per-task): tasks may carry proof_strength
 * (float, 0..1) and runtime_evidence_present (bool). When supplied, they are bucketed into
 * proof_strength_calibration (weak vs strong) and runtime_evidence_calibration (with vs without)
 * so a wave-planner can see whether weak proof or absent runtime evidence correlates with worse
 * calibration error, independent of task family.
 *
 * originator_pattern (optional per-task): tasks may carry an originator_pattern id. Any pattern
 * whose overclaim_rate reaches ORIGINATOR_OVERCLAIM_RATE_FOR_PENALTY is reported in
 * originator_pattern_penalties, and every family/originator with sample data gets an
 * impact_weight_suggestions entry (suggested_weight in [0.1, 1.5], 1.0=neutral, <1.0=discount an
 * overclaiming source, >1.0=boost an underclaiming one) directly usable by Task Fabric admission.
 */
final class AtlasExternalBrainImpactBacktestHarness
{
    public const SCHEMA = 'atlas.external_brain.impact_backtest_harness.v1';

    public const OVERCLAIM_THRESHOLD = 2.0;

    public const CALIBRATION_GOOD_THRESHOLD = 1.0;

    public const HIGH_LEVERAGE_FLOOR = 5.0;

    public const MIN_OVERCLAIM_RATE_FOR_HINT = 0.30;

    public const PENALTY_OUTCOMES = ['give_back', 'proxy_implementation', 'no_capability_delta', 'quarantine'];

    public const PROOF_STRENGTH_STRONG_FLOOR = 0.5;

    public const ORIGINATOR_OVERCLAIM_RATE_FOR_PENALTY = 0.30;

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
                'calibration_error' => null,
                'total_tasks' => 0,
                'family_calibration' => [],
                'proof_strength_calibration' => [],
                'runtime_evidence_calibration' => [],
                'originator_pattern_penalties' => [],
                'impact_weight_suggestions' => [],
                'calibration_update' => [
                    'overestimate_count' => 0,
                    'underestimate_count' => 0,
                    'calibrated_count' => 0,
                    'calibration_score' => null,
                    'calibration_error' => null,
                ],
                'task_family_adjustment' => [],
            ];
        }

        $byOutcome = [];
        $byFamily = [];
        $byProofBand = [];
        $byEvidenceBand = [];
        $byOriginator = [];
        $overclaims = [];
        $penalized = [];
        $wellCalibrated = 0;
        $absDeltaSum = 0.0;

        foreach ($tasks as $t) {
            $id = (string) ($t['task_id'] ?? 'unknown');
            $predicted = (float) ($t['predicted_leverage'] ?? 0.0);
            $actual = (float) ($t['actual_leverage'] ?? 0.0);
            $outcome = (string) ($t['actual_outcome'] ?? 'unknown');
            $family = (string) ($t['task_family'] ?? 'unassigned');
            $downstreamUnlocks = array_key_exists('downstream_unlocks', $t) ? (int) $t['downstream_unlocks'] : null;
            $delta = $predicted - $actual;
            $absDeltaSum += abs($delta);

            $byOutcome[$outcome][] = ['predicted' => $predicted, 'actual' => $actual, 'delta' => $delta];
            $byFamily[$family][] = ['predicted' => $predicted, 'actual' => $actual, 'delta' => $delta, 'outcome' => $outcome];

            if (array_key_exists('proof_strength', $t)) {
                $band = (float) $t['proof_strength'] >= self::PROOF_STRENGTH_STRONG_FLOOR ? 'strong' : 'weak';
                $byProofBand[$band][] = ['predicted' => $predicted, 'actual' => $actual, 'delta' => $delta];
            }
            if (array_key_exists('runtime_evidence_present', $t)) {
                $band = ((bool) $t['runtime_evidence_present']) ? 'with_runtime_evidence' : 'without_runtime_evidence';
                $byEvidenceBand[$band][] = ['predicted' => $predicted, 'actual' => $actual, 'delta' => $delta];
            }
            $originatorPattern = trim((string) ($t['originator_pattern'] ?? ''));
            if ($originatorPattern !== '') {
                $byOriginator[$originatorPattern][] = ['predicted' => $predicted, 'actual' => $actual, 'delta' => $delta];
            }

            if ($delta >= self::OVERCLAIM_THRESHOLD) {
                $overclaims[] = [
                    'task_id' => $id,
                    'predicted_leverage' => $predicted,
                    'actual_leverage' => $actual,
                    'overclaim_delta' => $delta,
                    'actual_outcome' => $outcome,
                ];
            }

            $isHighPredicted = $predicted >= self::HIGH_LEVERAGE_FLOOR;

            if (in_array($outcome, self::PENALTY_OUTCOMES, true) && $isHighPredicted) {
                $penalized[] = [
                    'task_id' => $id,
                    'predicted_leverage' => $predicted,
                    'actual_outcome' => $outcome,
                    'penalty_reason' => 'high_predicted_leverage_with_negative_outcome',
                ];
            } elseif ($isHighPredicted && $downstreamUnlocks === 0) {
                $penalized[] = [
                    'task_id' => $id,
                    'predicted_leverage' => $predicted,
                    'actual_outcome' => $outcome,
                    'penalty_reason' => 'no_downstream_unlock_despite_high_predicted_leverage',
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

        $quarantineCount = count(array_filter($penalized, static fn (array $p): bool => $p['actual_outcome'] === 'quarantine'));
        if ($quarantineCount > 0) {
            $hints[] = [
                'hint' => 'downweight_quarantine_prone_predictions',
                'reason' => sprintf('%d high-predicted tasks were quarantined', $quarantineCount),
            ];
        }

        $noUnlockCount = count(array_filter($penalized, static fn (array $p): bool => $p['penalty_reason'] === 'no_downstream_unlock_despite_high_predicted_leverage'));
        if ($noUnlockCount > 0) {
            $hints[] = [
                'hint' => 'discount_leverage_without_downstream_unlocks',
                'reason' => sprintf('%d high-predicted tasks produced zero downstream unlocks', $noUnlockCount),
            ];
        }

        $noCapabilityDeltaCount = count(array_filter($penalized, static fn (array $p): bool => $p['actual_outcome'] === 'no_capability_delta'));
        if ($noCapabilityDeltaCount > 0) {
            $hints[] = [
                'hint' => 'downweight_no_capability_delta_predictions',
                'reason' => sprintf('%d high-predicted tasks delivered no capability delta', $noCapabilityDeltaCount),
            ];
        }

        $familyCalibration = [];
        foreach ($byFamily as $family => $entries) {
            $count = count($entries);
            $avgPredicted = array_sum(array_column($entries, 'predicted')) / $count;
            $avgActual = array_sum(array_column($entries, 'actual')) / $count;
            $avgError = round(array_sum(array_map(static fn (array $e): float => abs($e['delta']), $entries)) / $count, 4);
            $avgDelta = $avgPredicted - $avgActual;
            $hasPenaltyOutcome = array_any($entries, static fn (array $e): bool => in_array($e['outcome'], self::PENALTY_OUTCOMES, true));

            $correctionDirection = match (true) {
                $hasPenaltyOutcome, $avgDelta >= self::CALIBRATION_GOOD_THRESHOLD => 'down',
                $avgDelta <= -self::CALIBRATION_GOOD_THRESHOLD => 'up',
                default => 'none',
            };

            $familyCalibration[] = [
                'task_family' => $family,
                'average_predicted' => round($avgPredicted, 2),
                'average_actual' => round($avgActual, 2),
                'average_error' => $avgError,
                'correction_direction' => $correctionDirection,
                'sample_count' => $count,
            ];
        }

        // AC2: proof_strength / runtime_evidence calibration bands (only populated when at
        // least one task carried the corresponding optional field).
        $bandCalibration = function (array $byBand): array {
            $out = [];
            foreach ($byBand as $band => $entries) {
                $count = count($entries);
                $avgPredicted = array_sum(array_column($entries, 'predicted')) / $count;
                $avgActual = array_sum(array_column($entries, 'actual')) / $count;
                $avgError = round(array_sum(array_map(static fn (array $e): float => abs($e['delta']), $entries)) / $count, 4);
                $out[$band] = [
                    'count' => $count,
                    'avg_predicted_leverage' => round($avgPredicted, 2),
                    'avg_actual_leverage' => round($avgActual, 2),
                    'avg_error' => $avgError,
                ];
            }

            return $out;
        };
        $proofStrengthCalibration = $bandCalibration($byProofBand);
        $runtimeEvidenceCalibration = $bandCalibration($byEvidenceBand);

        // AC3: originator-pattern overclaim penalty. AC4: impact-weight suggestions.
        $suggestedWeight = static fn (float $avgDelta): float => round(max(0.1, min(1.5, 1.0 - ($avgDelta / 10.0))), 2);

        $originatorPatternPenalties = [];
        $impactWeightSuggestions = [];
        foreach ($byOriginator as $originator => $entries) {
            $count = count($entries);
            $overclaimRate = count(array_filter($entries, static fn (array $e): bool => $e['delta'] >= self::OVERCLAIM_THRESHOLD)) / $count;
            $avgPredicted = array_sum(array_column($entries, 'predicted')) / $count;
            $avgActual = array_sum(array_column($entries, 'actual')) / $count;
            $avgDelta = $avgPredicted - $avgActual;

            if ($overclaimRate >= self::ORIGINATOR_OVERCLAIM_RATE_FOR_PENALTY) {
                $originatorPatternPenalties[] = [
                    'originator_pattern' => $originator,
                    'overclaim_rate' => round($overclaimRate, 2),
                    'sample_count' => $count,
                    'penalty_reason' => 'overclaim_rate_exceeds_threshold',
                ];
            }

            $impactWeightSuggestions[] = [
                'scope' => 'originator_pattern',
                'scope_id' => $originator,
                'suggested_weight' => $suggestedWeight($avgDelta),
                'rationale' => sprintf('avg_overclaim_delta=%.2f sample_count=%d', $avgDelta, $count),
            ];
        }
        foreach ($familyCalibration as $fc) {
            $avgDelta = $fc['average_predicted'] - $fc['average_actual'];
            $impactWeightSuggestions[] = [
                'scope' => 'task_family',
                'scope_id' => $fc['task_family'],
                'suggested_weight' => $suggestedWeight($avgDelta),
                'rationale' => sprintf('avg_overclaim_delta=%.2f sample_count=%d', $avgDelta, $fc['sample_count']),
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'calibration_buckets' => $calibrationBuckets,
            'overclaim_warnings' => $overclaims,
            'scorer_adjustment_hints' => $hints,
            'penalized_tasks' => $penalized,
            'calibration_score' => $calibrationScore,
            'calibration_error' => round($absDeltaSum / $totalTasks, 4),
            'total_tasks' => $totalTasks,
            'family_calibration' => $familyCalibration,
            'proof_strength_calibration' => $proofStrengthCalibration,
            'runtime_evidence_calibration' => $runtimeEvidenceCalibration,
            'originator_pattern_penalties' => $originatorPatternPenalties,
            'impact_weight_suggestions' => $impactWeightSuggestions,
            'calibration_update' => [
                'overestimate_count' => count(array_filter($calibrationBuckets, static fn (array $b): bool => $b['calibration_status'] === 'overclaimed')),
                'underestimate_count' => count(array_filter($calibrationBuckets, static fn (array $b): bool => $b['calibration_status'] === 'underclaimed')),
                'calibrated_count' => count(array_filter($calibrationBuckets, static fn (array $b): bool => $b['calibration_status'] === 'well_calibrated')),
                'calibration_score' => $calibrationScore,
                'calibration_error' => round($absDeltaSum / $totalTasks, 4),
            ],
            'task_family_adjustment' => array_map(static function (array $fc) {
                return [
                    'task_family' => $fc['task_family'],
                    'correction_direction' => $fc['correction_direction'],
                    'average_error' => $fc['average_error'],
                    'sample_count' => $fc['sample_count'],
                ];
            }, $familyCalibration),
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
