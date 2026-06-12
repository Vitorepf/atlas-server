<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P3 predictive twin: scores the forward model's predicted evolution
 * outcomes against the measured actual outcomes and emits twin_accuracy.
 *
 * The twin predicts, for each candidate evolution, the direction its target
 * metric will move (improve / regress / neutral) together with a confidence in
 * that call. The actual outcome reports the measured metric delta. Predictions
 * and outcomes are joined on a shared candidate_id; only joined pairs count as
 * samples. Accuracy is the fraction of joined pairs whose predicted direction
 * matches the actual direction, and calibration_error is the Brier-style mean
 * squared gap between stated confidence and realised correctness.
 *
 * "Prediction quality is measured, not trusted by default": with fewer than the
 * minimum number of joined samples the result is blocked, and a model whose
 * measured accuracy falls below the staleness floor is flagged stale_model so it
 * cannot silently steer selection.
 *
 * Pure: every returned field is computed from the method inputs through real
 * arithmetic. No I/O, clock, randomness, provider call or external state.
 * Identical inputs always yield identical output.
 */
final class L8SystemEvolutionTwinPredictionScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.system_evolution_twin_prediction_score.v1';

    /**
     * Minimum number of joined prediction/outcome pairs before any accuracy is
     * trusted; below this the scorer blocks rather than reporting a number drawn
     * from too few samples.
     */
    private const MIN_SAMPLE_COUNT = 3;

    /**
     * Measured accuracy at or above this floor keeps the model live; below it the
     * twin is flagged stale_model because it no longer tracks reality.
     */
    private const STALE_ACCURACY_THRESHOLD = 0.6;

    /**
     * Half-width of the neutral band on a metric delta: an absolute delta at or
     * below this magnitude is treated as the "neutral" direction rather than an
     * improvement or a regression.
     */
    private const NEUTRAL_EPSILON = 1.0e-9;

    /**
     * @param  list<array{candidate_id?: mixed, predicted_direction?: mixed, predicted_delta?: mixed, confidence?: mixed}>  $predictions
     * @param  list<array{candidate_id?: mixed, actual_delta?: mixed}>  $outcomes
     * @return array{
     *     schema_version: string,
     *     blocked: bool,
     *     sample_count: int,
     *     accuracy: float,
     *     twin_accuracy: float,
     *     calibration_error: float,
     *     stale_model: bool,
     *     correct_count: int,
     *     scored_candidate_ids: list<string>
     * }
     */
    public function score(array $predictions, array $outcomes): array
    {
        $actualByCandidate = $this->indexOutcomes($outcomes);

        $scoredIds = [];
        $correctFlags = [];
        $squaredCalibrationGaps = [];

        foreach ($predictions as $prediction) {
            if (! is_array($prediction)) {
                continue;
            }

            $candidateId = AreaFocusScalarNormalizer::trimmedStringOnly($prediction['candidate_id'] ?? '');
            if ($candidateId === '' || ! array_key_exists($candidateId, $actualByCandidate)) {
                continue;
            }

            // Each candidate is scored at most once, on its first prediction.
            if (in_array($candidateId, $scoredIds, true)) {
                continue;
            }

            $predictedDirection = $this->resolvePredictedDirection($prediction);
            $actualDirection = $this->directionFromDelta($actualByCandidate[$candidateId]);
            $confidence = $this->clamp(
                AreaFocusScalarNormalizer::numberOrDefault($prediction['confidence'] ?? 1.0, 1.0),
                0.0,
                1.0,
            );

            $isCorrect = $predictedDirection === $actualDirection;
            $correctValue = $isCorrect ? 1.0 : 0.0;

            $scoredIds[] = $candidateId;
            $correctFlags[] = $isCorrect;
            $gap = $confidence - $correctValue;
            $squaredCalibrationGaps[] = $gap * $gap;
        }

        $sampleCount = count($scoredIds);
        $correctCount = count(array_filter($correctFlags, static fn (bool $flag): bool => $flag));

        $accuracyRatio = $sampleCount === 0
            ? 0.0
            : $this->clamp($correctCount / $sampleCount, 0.0, 1.0);

        $accuracy = round($accuracyRatio, 4);

        $calibrationError = $sampleCount === 0
            ? 0.0
            : round($this->clamp(array_sum($squaredCalibrationGaps) / $sampleCount, 0.0, 1.0), 4);

        $blocked = $sampleCount < self::MIN_SAMPLE_COUNT;

        // A blocked model has no trustworthy accuracy, so it is not asserted live;
        // once enough samples exist, accuracy below the floor marks it stale. The
        // staleness gate compares the unrounded ratio so a sub-floor accuracy that
        // only rounds up to the floor (e.g. 0.59995 -> 0.6) still fails closed rather
        // than silently re-acquiring the right to steer selection.
        $staleModel = $blocked || $accuracyRatio < self::STALE_ACCURACY_THRESHOLD;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'blocked' => $blocked,
            'sample_count' => $sampleCount,
            'accuracy' => $accuracy,
            'twin_accuracy' => $accuracy,
            'calibration_error' => $calibrationError,
            'stale_model' => $staleModel,
            'correct_count' => $correctCount,
            'scored_candidate_ids' => array_values($scoredIds),
        ];
    }

    /**
     * @param  list<array{candidate_id?: mixed, actual_delta?: mixed}>  $outcomes
     * @return array<string, float>
     */
    private function indexOutcomes(array $outcomes): array
    {
        $indexed = [];
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            $candidateId = AreaFocusScalarNormalizer::trimmedStringOnly($outcome['candidate_id'] ?? '');
            if ($candidateId === '') {
                continue;
            }

            // First outcome wins; later duplicates for the same candidate are ignored.
            if (array_key_exists($candidateId, $indexed)) {
                continue;
            }

            $indexed[$candidateId] = AreaFocusScalarNormalizer::numberOrDefault($outcome['actual_delta'] ?? 0.0, 0.0);
        }

        return $indexed;
    }

    /**
     * Resolve the predicted direction from either an explicit predicted_direction
     * label or, failing that, the sign of a numeric predicted_delta.
     *
     * @param  array{predicted_direction?: mixed, predicted_delta?: mixed}  $prediction
     */
    private function resolvePredictedDirection(array $prediction): string
    {
        $explicit = $this->normaliseDirection($prediction['predicted_direction'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        if (array_key_exists('predicted_delta', $prediction)
            && (is_int($prediction['predicted_delta'])
                || is_float($prediction['predicted_delta'])
                || (is_string($prediction['predicted_delta']) && is_numeric(trim($prediction['predicted_delta']))))) {
            return $this->directionFromDelta((float) $prediction['predicted_delta']);
        }

        return 'neutral';
    }

    private function normaliseDirection(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        return match ($value) {
            'improve', 'improved', 'up', 'positive' => 'improve',
            'regress', 'regressed', 'down', 'negative' => 'regress',
            'neutral', 'flat', 'none', 'no_change' => 'neutral',
            default => null,
        };
    }

    private function directionFromDelta(float $delta): string
    {
        if ($delta > self::NEUTRAL_EPSILON) {
            return 'improve';
        }

        if ($delta < -self::NEUTRAL_EPSILON) {
            return 'regress';
        }

        return 'neutral';
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
