<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S140 — Compounding Axis Coverage Scorer.
 *
 * Pure, deterministic, zero-dependency. Given a set of completed cycle scores it
 * answers: across the six canonical compounding axes, which axes did the cycle's
 * leaps actually move, which axis is most neglected, and was every axis covered?
 *
 * A "leap" is a cycle score with `counts_as_leap === true`. Each leap carries an
 * `impact_axes` map (axis => 0..1); an axis is moved by a leap when its impact is
 * strictly greater than 0.0. Per-axis coverage is the fraction of leaps that moved
 * that axis, rounded to 4 decimal places.
 *
 * No I/O, no provider, no clock, no randomness. Every returned field is computed
 * purely from the method input.
 */
final class CompoundingAxisCoverageScorer
{
    /**
     * The six canonical compounding axes (NAMES only; mirrors
     * CycleQualityScoreService::IMPACT_AXES). Order is canonical: context first.
     */
    private const AXES = ['context', 'memory', 'quality', 'agents', 'speed', 'robustness'];

    /**
     * @param  array<int|string,mixed>  $cycleScores
     * @return array{axes:array<string,float>, leap_count:int, most_neglected_axis:string|null, fully_covered:bool}
     */
    public function score(array $cycleScores): array
    {
        $leaps = $this->leaps($cycleScores);
        $leapCount = count($leaps);

        $axes = [];
        foreach (self::AXES as $axis) {
            if ($leapCount === 0) {
                $axes[$axis] = 0.0;

                continue;
            }

            $covered = 0;
            foreach ($leaps as $leap) {
                if ($this->axisImpact($leap, $axis) > 0.0) {
                    $covered++;
                }
            }

            $axes[$axis] = round($covered / $leapCount, 4);
        }

        return [
            'axes' => $axes,
            'leap_count' => $leapCount,
            'most_neglected_axis' => $leapCount === 0 ? null : $this->mostNeglectedAxis($axes),
            'fully_covered' => $this->fullyCovered($axes),
        ];
    }

    /**
     * @param  array<int|string,mixed>  $cycleScores
     * @return array<int,array<string,mixed>>
     */
    private function leaps(array $cycleScores): array
    {
        $leaps = [];
        foreach ($cycleScores as $cycleScore) {
            if (! is_array($cycleScore)) {
                continue;
            }

            if (($cycleScore['counts_as_leap'] ?? false) === true) {
                $leaps[] = $cycleScore;
            }
        }

        return $leaps;
    }

    /**
     * @param  array<string,mixed>  $leap
     */
    private function axisImpact(array $leap, string $axis): float
    {
        $impactAxes = $leap['impact_axes'] ?? [];
        if (! is_array($impactAxes)) {
            return 0.0;
        }

        $impact = $impactAxes[$axis] ?? 0.0;
        if (! is_int($impact) && ! is_float($impact)) {
            return 0.0;
        }

        return (float) $impact;
    }

    /**
     * Lowest coverage wins; ties broken by canonical axis order (context first).
     *
     * @param  array<string,float>  $axes
     */
    private function mostNeglectedAxis(array $axes): ?string
    {
        $neglected = null;
        $lowest = null;
        foreach (self::AXES as $axis) {
            $coverage = $axes[$axis];
            if ($lowest === null || $coverage < $lowest) {
                $lowest = $coverage;
                $neglected = $axis;
            }
        }

        return $neglected;
    }

    /**
     * @param  array<string,float>  $axes
     */
    private function fullyCovered(array $axes): bool
    {
        foreach (self::AXES as $axis) {
            if ($axes[$axis] <= 0.0) {
                return false;
            }
        }

        return true;
    }
}
