<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure metric: proves whether a proposed consolidation actually improves the
 * circuit rather than just deleting lines. Compares before/after cohesion,
 * coupling, duplicated contracts, entrypoint count, and proof density.
 *
 * consolidation_improves_circuit is true only when the composite score improves
 * AND no individual dimension regressed — a consolidation that shrinks line count
 * while coupling, entrypoints, or proof density gets worse is refused, since
 * fewer lines with worse structure is not simplification.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasSelfConstructionCircuitCohesionMetric
{
    public const SCHEMA = 'atlas.self_construction.circuit_cohesion_metric.v1';

    /** Dimensions where a HIGHER value is better. */
    private const HIGHER_IS_BETTER = ['cohesion', 'proof_density'];

    /** Dimensions where a LOWER value is better. */
    private const LOWER_IS_BETTER = ['coupling', 'duplicated_contracts', 'entrypoint_count'];

    /**
     * @param  array{
     *   before?: array<string, float|int>,
     *   after?: array<string, float|int>,
     * }  $comparison
     * @return array{
     *   schema: string,
     *   before_score: float,
     *   after_score: float,
     *   delta: float,
     *   drivers: list<string>,
     *   regressions: list<string>,
     *   consolidation_improves_circuit: bool,
     * }
     */
    public function measure(array $comparison): array
    {
        $before = (array) ($comparison['before'] ?? []);
        $after = (array) ($comparison['after'] ?? []);

        $beforeScore = $this->compositeScore($before);
        $afterScore = $this->compositeScore($after);
        $delta = round($afterScore - $beforeScore, 4);

        $drivers = [];
        $regressions = [];

        foreach (array_merge(self::HIGHER_IS_BETTER, self::LOWER_IS_BETTER) as $dimension) {
            $beforeValue = (float) ($before[$dimension] ?? 0.0);
            $afterValue = (float) ($after[$dimension] ?? 0.0);

            if ($beforeValue === $afterValue) {
                continue;
            }

            $higherIsBetter = in_array($dimension, self::HIGHER_IS_BETTER, true);
            $improved = $higherIsBetter ? $afterValue > $beforeValue : $afterValue < $beforeValue;

            if ($improved) {
                $drivers[] = $dimension;
            } else {
                $regressions[] = $dimension;
            }
        }

        sort($drivers);
        sort($regressions);

        return [
            'schema' => self::SCHEMA,
            'before_score' => $beforeScore,
            'after_score' => $afterScore,
            'delta' => $delta,
            'drivers' => $drivers,
            'regressions' => $regressions,
            'consolidation_improves_circuit' => $delta > 0.0 && $regressions === [],
        ];
    }

    /**
     * @param  array<string, float|int>  $metrics
     */
    private function compositeScore(array $metrics): float
    {
        $score = 0.0;

        foreach (self::HIGHER_IS_BETTER as $dimension) {
            $score += (float) ($metrics[$dimension] ?? 0.0);
        }

        foreach (self::LOWER_IS_BETTER as $dimension) {
            $score -= (float) ($metrics[$dimension] ?? 0.0);
        }

        return round($score, 4);
    }
}
