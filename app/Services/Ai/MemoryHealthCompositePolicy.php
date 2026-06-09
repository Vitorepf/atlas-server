<?php

namespace App\Services\Ai;

final class MemoryHealthCompositePolicy
{
    /**
     * Ordered highest-weight-first so iteration order is the deterministic
     * tie-breaker for the weighted-headroom argmax.
     *
     * @var array<string,float>
     */
    private const WEIGHTS = [
        'provider_safety' => 0.24,
        'readiness' => 0.22,
        'governance' => 0.2,
        'freshness' => 0.14,
        'feedback' => 0.1,
        'retrieval_eval' => 0.08,
        'completeness' => 0.02,
    ];

    /**
     * @param  array<string,mixed>  $dimensions
     * @return array{
     *     composite_score: int,
     *     limiting_dimension: string,
     *     limiting_headroom: float,
     *     missing_dimensions: list<string>,
     *     reason: string
     * }
     */
    public static function compose(array $dimensions): array
    {
        $clamped = [];
        $missingDimensions = [];

        foreach (self::WEIGHTS as $dimension => $weight) {
            $raw = $dimensions[$dimension] ?? null;

            if (! is_numeric($raw)) {
                $clamped[$dimension] = 0;
                $missingDimensions[] = $dimension;

                continue;
            }

            $clamped[$dimension] = self::clampScore($raw);
        }

        $composite = 0.0;
        foreach (self::WEIGHTS as $dimension => $weight) {
            $composite += $clamped[$dimension] * $weight;
        }

        $compositeScore = (int) round($composite);
        $limitingDimension = 'none';
        $limitingHeadroom = 0.0;

        foreach (self::WEIGHTS as $dimension => $weight) {
            $headroom = round((100 - $clamped[$dimension]) * $weight, 2);

            if ($headroom > $limitingHeadroom) {
                $limitingHeadroom = $headroom;
                $limitingDimension = $dimension;
            }
        }

        if ($compositeScore >= 100) {
            $limitingDimension = 'none';
            $limitingHeadroom = 0.0;
        }

        if ($limitingDimension === 'none') {
            $limitingHeadroom = 0.0;
        } else {
            $limitingHeadroom = round($limitingHeadroom, 2);
        }

        if ($limitingDimension === 'none' && $missingDimensions === []) {
            $reason = 'all_dimensions_at_ceiling';
        } else {
            $reason = sprintf(
                'limiting_dimension_%s_headroom_%s',
                $limitingDimension,
                round($limitingHeadroom, 2),
            );
        }

        return [
            'composite_score' => $compositeScore,
            'limiting_dimension' => $limitingDimension,
            'limiting_headroom' => $limitingHeadroom,
            'missing_dimensions' => $missingDimensions,
            'reason' => $reason,
        ];
    }

    private static function clampScore(mixed $value): int
    {
        $float = (float) $value;

        if (is_nan($float)) {
            return 0;
        }

        $bounded = min(max($float, 0.0), 100.0);

        return (int) round($bounded);
    }
}
