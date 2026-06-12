<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class LearningLiftAttributionScorer
{
    private const SCHEMA_VERSION = 'atlas.loop.learning_lift_attribution.v1';

    private const WEIGHT_COST = 30;

    private const WEIGHT_FLOW_QUALITY = 25;

    private const WEIGHT_TEST_PASS_RATE = 25;

    private const WEIGHT_REPAIR_LOOP = 20;

    private const SCORE_FLOOR = -100;

    private const SCORE_CEILING = 100;

    private const POSITIVE_THRESHOLD = 10;

    private const REGRESSION_THRESHOLD = -10;

    private const ATTRIBUTION_CONFIDENCE_FLOOR = 0.5;

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $after
     * @param  array<string,mixed>  $attribution
     * @return array<string,mixed>
     */
    public function score(array $baseline, array $after, array $attribution): array
    {
        $blockers = $this->detectBlockers($baseline);

        if ($blockers !== []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'lift_score' => 0,
                'verdict' => 'neutral',
                'component_deltas' => [
                    'cost' => 0.0,
                    'flow_quality' => 0.0,
                    'test_pass_rate' => 0.0,
                    'repair_loop' => 0.0,
                ],
                'attribution_confidence' => 0.0,
                'blockers' => $blockers,
            ];
        }

        $componentDeltas = [
            'cost' => $this->directionalDelta($baseline, $after, 'cost', true),
            'flow_quality' => $this->directionalDelta($baseline, $after, 'flow_quality', false),
            'test_pass_rate' => $this->directionalDelta($baseline, $after, 'test_pass_rate', false),
            'repair_loop' => $this->directionalDelta($baseline, $after, 'repair_loop', true),
        ];

        $liftScore = $this->computeLiftScore($componentDeltas);
        $attributionConfidence = $this->computeAttributionConfidence($attribution);
        $verdict = $this->resolveVerdict($liftScore, $attributionConfidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'lift_score' => $liftScore,
            'verdict' => $verdict,
            'component_deltas' => $componentDeltas,
            'attribution_confidence' => $attributionConfidence,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @return list<string>
     */
    private function detectBlockers(array $baseline): array
    {
        $blockers = [];

        if (! $this->hasMeasuredBaseline($baseline)) {
            $blockers[] = 'missing_baseline';
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $baseline
     */
    private function hasMeasuredBaseline(array $baseline): bool
    {
        foreach (['cost', 'flow_quality', 'test_pass_rate', 'repair_loop'] as $metric) {
            if ($this->hasNumeric($baseline, $metric)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Improvement-direction relative delta in [-1.0, 1.0], rounded to 4 places.
     * When $lowerIsBetter the metric improves as it shrinks (cost, repair-loop);
     * otherwise it improves as it grows (flow quality, test pass rate).
     *
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $after
     */
    private function directionalDelta(array $baseline, array $after, string $metric, bool $lowerIsBetter): float
    {
        if (! $this->hasNumeric($baseline, $metric)) {
            return 0.0;
        }

        $baselineValue = $this->numeric($baseline, $metric);
        $afterValue = $this->hasNumeric($after, $metric)
            ? $this->numeric($after, $metric)
            : $baselineValue;

        $denominator = abs($baselineValue);

        $rawChange = $lowerIsBetter
            ? ($baselineValue - $afterValue)
            : ($afterValue - $baselineValue);

        if ($denominator === 0.0) {
            return $rawChange === 0.0 ? 0.0 : ($rawChange > 0.0 ? 1.0 : -1.0);
        }

        $relative = $rawChange / $denominator;

        return round($this->clampUnit($relative), 4);
    }

    /**
     * @param  array<string,float>  $componentDeltas
     */
    private function computeLiftScore(array $componentDeltas): int
    {
        $weighted =
            ($componentDeltas['cost'] * self::WEIGHT_COST)
            + ($componentDeltas['flow_quality'] * self::WEIGHT_FLOW_QUALITY)
            + ($componentDeltas['test_pass_rate'] * self::WEIGHT_TEST_PASS_RATE)
            + ($componentDeltas['repair_loop'] * self::WEIGHT_REPAIR_LOOP);

        $rounded = (int) round($weighted);

        return max(self::SCORE_FLOOR, min(self::SCORE_CEILING, $rounded));
    }

    /**
     * @param  array<string,mixed>  $attribution
     */
    private function computeAttributionConfidence(array $attribution): float
    {
        if ($this->hasNumeric($attribution, 'confidence')) {
            return round($this->clampZeroToOne($this->numeric($attribution, 'confidence')), 4);
        }

        $total = $this->hasNumeric($attribution, 'total_samples')
            ? $this->numeric($attribution, 'total_samples')
            : 0.0;
        $attributed = $this->hasNumeric($attribution, 'attributed_samples')
            ? $this->numeric($attribution, 'attributed_samples')
            : 0.0;

        if ($total <= 0.0) {
            return 0.0;
        }

        $ratio = $this->clampZeroToOne($attributed / $total);

        if (($attribution['confounded'] ?? false) === true) {
            $ratio /= 2.0;
        }

        return round($ratio, 4);
    }

    private function resolveVerdict(int $liftScore, float $attributionConfidence): string
    {
        if ($attributionConfidence < self::ATTRIBUTION_CONFIDENCE_FLOOR) {
            return 'neutral_not_attributable';
        }

        if ($liftScore <= self::REGRESSION_THRESHOLD) {
            return 'regression';
        }

        if ($liftScore >= self::POSITIVE_THRESHOLD) {
            return 'positive';
        }

        return 'neutral';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasNumeric(array $payload, string $key): bool
    {
        return array_key_exists($key, $payload) && is_numeric($payload[$key]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function numeric(array $payload, string $key): float
    {
        return (float) $payload[$key];
    }

    private function clampUnit(float $value): float
    {
        return max(-1.0, min(1.0, $value));
    }

    private function clampZeroToOne(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
