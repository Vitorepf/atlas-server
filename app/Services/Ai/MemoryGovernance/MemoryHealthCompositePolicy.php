<?php

namespace App\Services\Ai\MemoryGovernance;

final class MemoryHealthCompositePolicy
{
    /**
     * Ordered highest-weight-first so iteration order is the deterministic
     * tie-breaker for the weighted-headroom argmax.
     *
     * @var array<string,float>
     */
    private const WEIGHTS = [
        // D5 (Obra #18) — recalibrated so the score is driven by the CRUDE quality
        // numbers (structural honesty, rationale, relation density, feedback fill),
        // not by mere presence. These four collectively carry ~52% of the weight, so
        // the composite rises only when D1-D4 move the raw counts. Presence
        // (readiness) and safety are necessary but no longer dominate.
        'structural_honesty' => 0.14,
        'rationale' => 0.14,
        'provider_safety' => 0.14,
        'relation_density' => 0.12,
        'feedback' => 0.12,
        'governance' => 0.1,
        'readiness' => 0.08,
        'freshness' => 0.08,
        'retrieval_eval' => 0.06,
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
