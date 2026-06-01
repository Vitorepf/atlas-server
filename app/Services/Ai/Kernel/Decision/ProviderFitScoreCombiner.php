<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Decision;

final class ProviderFitScoreCombiner
{
    private const SCHEMA_VERSION = 'atlas.aaeos.provider_fit_score.v1';

    private const FACTORS = ['quality', 'latency', 'cost', 'sample'];

    private const EQUAL_WEIGHT = 0.25;

    private const MIN_TRUSTED_SAMPLE = 5;

    private const STRONG_THRESHOLD = 80;

    private const VIABLE_THRESHOLD = 60;

    private const NEUTRAL_ANCHOR = 50;

    /**
     * Combine four already-normalized 0..100 factor sub-scores with a 0..1 weight
     * vector and the candidate sample size into one provider fit score and band.
     *
     * @param  array<string,mixed>  $factorScores
     * @param  array<string,mixed>  $weights
     * @return array{
     *     schema_version: string,
     *     score: int,
     *     band: string,
     *     confidence_dampened: bool,
     *     effective_weights: array{quality: float, latency: float, cost: float, sample: float}
     * }
     */
    public function score(array $factorScores, array $weights, int $sampleSize): array
    {
        $clampedFactors = $this->clampFactors($factorScores);
        $effectiveWeights = $this->normalizeWeights($weights);

        $weightedMean = 0.0;
        foreach (self::FACTORS as $factor) {
            $weightedMean += $clampedFactors[$factor] * $effectiveWeights[$factor];
        }

        $dampened = $sampleSize < self::MIN_TRUSTED_SAMPLE;
        $dampenFactor = $this->dampenFactor($sampleSize);
        $adjustedMean = self::NEUTRAL_ANCHOR + ($weightedMean - self::NEUTRAL_ANCHOR) * $dampenFactor;

        $score = (int) round($adjustedMean);
        $score = min(max($score, 0), 100);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $score,
            'band' => $this->band($score, $sampleSize),
            'confidence_dampened' => $dampened,
            'effective_weights' => $effectiveWeights,
        ];
    }

    /**
     * @param  array<string,mixed>  $factorScores
     * @return array{quality: float, latency: float, cost: float, sample: float}
     */
    private function clampFactors(array $factorScores): array
    {
        $clamped = [];
        foreach (self::FACTORS as $factor) {
            $value = $this->floatValue($factorScores, $factor);
            $clamped[$factor] = min(max($value, 0.0), 100.0);
        }

        return $clamped;
    }

    /**
     * @param  array<string,mixed>  $weights
     * @return array{quality: float, latency: float, cost: float, sample: float}
     */
    private function normalizeWeights(array $weights): array
    {
        $raw = [];
        $total = 0.0;
        foreach (self::FACTORS as $factor) {
            $value = max($this->floatValue($weights, $factor), 0.0);
            $raw[$factor] = $value;
            $total += $value;
        }

        if ($total <= 0.0) {
            return [
                'quality' => self::EQUAL_WEIGHT,
                'latency' => self::EQUAL_WEIGHT,
                'cost' => self::EQUAL_WEIGHT,
                'sample' => self::EQUAL_WEIGHT,
            ];
        }

        $normalized = [];
        foreach (self::FACTORS as $factor) {
            $normalized[$factor] = $raw[$factor] / $total;
        }

        return $normalized;
    }

    private function dampenFactor(int $sampleSize): float
    {
        if ($sampleSize >= self::MIN_TRUSTED_SAMPLE) {
            return 1.0;
        }

        if ($sampleSize <= 0) {
            return 0.0;
        }

        return $sampleSize / self::MIN_TRUSTED_SAMPLE;
    }

    private function band(int $score, int $sampleSize): string
    {
        if ($sampleSize <= 0) {
            return 'untrusted';
        }

        if ($score >= self::STRONG_THRESHOLD) {
            return 'strong';
        }

        if ($score >= self::VIABLE_THRESHOLD) {
            return 'viable';
        }

        return 'weak';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function floatValue(array $payload, string $key): float
    {
        $value = $payload[$key] ?? 0;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
