<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Support\AiValueNormalizer;

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
     * An optional max_run budget caps how many above-threshold dimensions may
     * actually run: only the highest-scoring ones up to the budget run, the
     * rest are marked skipped_by_budget. The budget is clamped to at least 1
     * so the fanout never resolves to zero retrievers; a budget at or above
     * the run-set size has no effect.
     *
     * @param  array<string,mixed>  $scores
     * @return array{run:array<int,string>, skipped:array<int,string>, reasons:array<string,string>}
     */
    public function gate(array $scores, float $threshold = 0.5, ?int $maxRun = null): array
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

        if ($maxRun !== null) {
            $clampedMaxRun = max(1, $maxRun);

            if ($clampedMaxRun < count($run)) {
                $budgetedOut = array_slice($run, $clampedMaxRun);
                $run = array_slice($run, 0, $clampedMaxRun);

                foreach ($budgetedOut as $dimension) {
                    $skipped[] = $dimension;
                    $reasons[$dimension] = 'skipped_by_budget';
                }

                usort(
                    $skipped,
                    fn (string $left, string $right): int => $this->dimensionRank($left) <=> $this->dimensionRank($right),
                );
            }
        }

        return [
            'run' => array_values($run),
            'skipped' => array_values($skipped),
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
        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function clampUnit(float $value): float
    {
        return AiValueNormalizer::clampUnit($value);
    }
}
