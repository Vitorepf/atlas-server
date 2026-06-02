<?php

declare(strict_types=1);

namespace App\Services\Ai\MemoryGovernance;

final class MemoryHealthCompositeScorer
{
    private const SCHEMA_VERSION = 'atlas.memory_governance.health_composite.v1';

    /**
     * Canonical weights summing to 1.0, mirroring
     * AtlasMemoryQualityService::weightedScore byte-for-byte.
     * Ordered highest-weight-first so iteration order doubles as the
     * deterministic tie-break order for the weighted-headroom argmax.
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
     *     schema_version: string,
     *     composite_score: int,
     *     limiting_dimension: string,
     *     limiting_headroom: float,
     *     missing_dimensions: list<string>,
     *     reason: string
     * }
     */
    public function compose(array $dimensions): array
    {
        $clamped = [];
        $missingDimensions = [];

        // R1: fail-closed — absent or non-numeric is treated as 0 and the key
        // is recorded as missing; present values clamp to int in [0,100].
        foreach (self::WEIGHTS as $dimension => $weight) {
            $raw = $dimensions[$dimension] ?? null;

            if (! is_numeric($raw)) {
                $clamped[$dimension] = 0;
                $missingDimensions[] = $dimension;

                continue;
            }

            $clamped[$dimension] = $this->clampScore($raw);
        }

        // R2: composite_score = rounded weighted sum of clamped scores.
        $composite = 0.0;
        foreach (self::WEIGHTS as $dimension => $weight) {
            $composite += $clamped[$dimension] * $weight;
        }
        $compositeScore = (int) round($composite);

        // R3 + R4: per-dimension weighted headroom; the limiting dimension is
        // the single largest positive weighted headroom, ties broken by the
        // highest-weight-first iteration order above.
        $limitingDimension = 'none';
        $limitingHeadroom = 0.0;

        foreach (self::WEIGHTS as $dimension => $weight) {
            // Compare at the spec's declared 2-decimal precision: the weighted
            // headroom is a rounded percentage-point quantity, so two equal
            // headrooms must compare exactly. Without this, IEEE-754 drift
            // (e.g. 22*0.24 = 5.2799…93 vs 24*0.22 = 5.2800…02) silently breaks
            // the doc's "ties broken by highest-weight-first order" guarantee,
            // letting a later, lower-weight dimension win a true tie. Rounding
            // never reorders spec-distinct headrooms (verified exhaustively over
            // the 7 fixed weights x integer clamps).
            $headroom = round((100 - $clamped[$dimension]) * $weight, 2);

            if ($headroom > $limitingHeadroom) {
                $limitingHeadroom = $headroom;
                $limitingDimension = $dimension;
            }
        }

        // R5: a saturated composite leaves no axis to improve.
        if ($compositeScore >= 100) {
            $limitingDimension = 'none';
            $limitingHeadroom = 0.0;
        }

        // R6: no dimension carries positive headroom -> nothing limits.
        if ($limitingDimension === 'none') {
            $limitingHeadroom = 0.0;
        } else {
            $limitingHeadroom = round($limitingHeadroom, 2);
        }

        // R7: reason narrative.
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
            'schema_version' => self::SCHEMA_VERSION,
            'composite_score' => $compositeScore,
            'limiting_dimension' => $limitingDimension,
            'limiting_headroom' => $limitingHeadroom,
            'missing_dimensions' => $missingDimensions,
            'reason' => $reason,
        ];
    }

    private function clampScore(mixed $value): int
    {
        $float = (float) $value;

        // NaN is not a valid score; fail-closed to the floor.
        if (is_nan($float)) {
            return 0;
        }

        // Clamp on the float BEFORE the int cast. Casting an out-of-[0,100]
        // value straight to int first lets huge positives (PHP_INT_MAX, INF,
        // 1e400, PHP_FLOAT_MAX — all is_numeric() === true) wrap to PHP_INT_MIN
        // or 0, which then min(max(...)) pins to 0: a maximal "healthy" signal
        // silently inverts into the floor and becomes the limiting dimension.
        // Bounding the float to [0.0, 100.0] first guarantees the subsequent
        // round/cast is always representable and saturates correctly.
        $bounded = min(max($float, 0.0), 100.0);

        return (int) round($bounded);
    }
}
