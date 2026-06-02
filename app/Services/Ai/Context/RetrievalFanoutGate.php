<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class RetrievalFanoutGate
{
    /**
     * Canonical retrieval dimensions evaluated by the fanout gate.
     *
     * @var array<int,string>
     */
    private const DIMENSIONS = ['memory', 'code', 'docs'];

    /**
     * Decide which retrieval dimensions to run versus skip for a recall fanout.
     *
     * Each dimension runs iff its numeric score is at or above the clamped
     * threshold; otherwise it is skipped. A missing or non-numeric score is
     * treated as 0.0 and therefore skipped. When every dimension falls below
     * the threshold the single highest-scoring dimension is forced into the
     * run set so the fanout never resolves to zero retrievers. The run set is
     * ordered by descending score.
     *
     * @param  array<string,mixed>  $scores
     * @return array{run:array<int,string>, skipped:array<int,string>, reasons:array<string,string>}
     */
    public function gate(array $scores, float $threshold = 0.5): array
    {
        $clampedThreshold = $this->clampUnit($threshold);

        $resolvedScores = [];
        foreach (self::DIMENSIONS as $dimension) {
            $resolvedScores[$dimension] = $this->numericScore($scores[$dimension] ?? null);
        }

        $run = [];
        $skipped = [];
        $reasons = [];

        foreach (self::DIMENSIONS as $dimension) {
            $score = $resolvedScores[$dimension];

            if ($score >= $clampedThreshold) {
                $run[] = $dimension;
                $reasons[$dimension] = 'above_threshold';

                continue;
            }

            $skipped[] = $dimension;
            $reasons[$dimension] = 'below_threshold:'.(string) $score;
        }

        if ($run === []) {
            $forced = $this->highestScoringDimension($resolvedScores);

            $skipped = array_values(array_filter(
                $skipped,
                static fn (string $dimension): bool => $dimension !== $forced,
            ));

            $run[] = $forced;
            $reasons[$forced] = 'forced_top_relevance';
        }

        usort(
            $run,
            fn (string $left, string $right): int => $this->compareByDescendingScore($resolvedScores, $left, $right),
        );

        return [
            'run' => array_values($run),
            'skipped' => $skipped,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string,float>  $resolvedScores
     */
    private function highestScoringDimension(array $resolvedScores): string
    {
        $top = self::DIMENSIONS[0];

        foreach (self::DIMENSIONS as $dimension) {
            if ($resolvedScores[$dimension] > $resolvedScores[$top]) {
                $top = $dimension;
            }
        }

        return $top;
    }

    /**
     * @param  array<string,float>  $resolvedScores
     */
    private function compareByDescendingScore(array $resolvedScores, string $left, string $right): int
    {
        $delta = $resolvedScores[$right] <=> $resolvedScores[$left];

        if ($delta !== 0) {
            return $delta;
        }

        return $this->dimensionRank($left) <=> $this->dimensionRank($right);
    }

    private function dimensionRank(string $dimension): int
    {
        $rank = array_search($dimension, self::DIMENSIONS, true);

        return $rank === false ? count(self::DIMENSIONS) : $rank;
    }

    private function numericScore(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function clampUnit(float $value): float
    {
        return min(max($value, 0.0), 1.0);
    }
}
