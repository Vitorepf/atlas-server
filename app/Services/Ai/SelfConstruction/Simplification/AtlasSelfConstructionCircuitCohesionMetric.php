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

    public const RECOMMENDATION_SPLIT_OR_COLLAPSE = 'split_or_collapse';

    public const RECOMMENDATION_KEEP = 'keep';

    /** Dimensions where a HIGHER value is better. */
    private const HIGHER_IS_BETTER = ['cohesion', 'proof_density'];

    /** Dimensions where a LOWER value is better. */
    private const LOWER_IS_BETTER = ['coupling', 'duplicated_contracts', 'entrypoint_count'];

    /** cohesion below this AND coupling above LOW_COHESION_HIGH_COUPLING recommends split/collapse. */
    private const LOW_COHESION_THRESHOLD = 0.40;

    private const HIGH_COUPLING_THRESHOLD = 0.60;

    /** Weight applied to each duplicate helper when computing deletion_upside. */
    private const DUPLICATE_HELPER_WEIGHT = 10.0;

    public const COLLAPSE_NOW = 'collapse_now';

    public const COLLAPSE_SOON = 'collapse_soon';

    public const COLLAPSE_MONITOR = 'monitor';

    public const COLLAPSE_KEEP = 'keep';

    /** boundary_drift / fan_in_out_pressure at/above this are treated as a real early-warning signal. */
    private const BOUNDARY_DRIFT_THRESHOLD = 0.5;

    private const FAN_PRESSURE_THRESHOLD = 0.7;

    /** 2+ sibling circuits doing the same job is a real duplicated-responsibility signal. */
    private const DUPLICATE_RESPONSIBILITY_THRESHOLD = 2;

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
     * Single-circuit invariant: scores one circuit's cohesion-vs-coupling shape and recommends
     * split_or_collapse (bloated, low-cohesion, high-coupling) or keep (small, cohesive). Duplicate
     * helper count and removable_lines widen deletion_upside — the size of the win if collapsed —
     * but never flip a genuinely tangled circuit's recommendation to keep just because it is also
     * big: risk (cohesion/coupling) and upside (deletion_upside) are reported side by side, never
     * merged into one number that could hide the risk.
     *
     * @param  array{
     *   cohesion?:                       float,
     *   coupling?:                       float,
     *   line_count?:                     int,
     *   duplicate_helper_count?:         int,
     *   removable_lines?:                int,
     *   external_reference_count?:       int,
     *   internal_reference_count?:       int,
     *   fan_in?:                         int,
     *   fan_out?:                        int,
     *   duplicate_responsibility_count?: int,
     *   has_declared_owner?:             bool,
     * }  $circuit
     * @return array{
     *   schema: string,
     *   score: float,
     *   recommendation: string,
     *   cohesion: float,
     *   coupling: float,
     *   duplicate_helper_count: int,
     *   removable_lines: int,
     *   deletion_upside: float,
     *   boundary_drift: float,
     *   fan_in_out_pressure: float,
     *   duplicate_responsibility_count: int,
     *   owner_gap: bool,
     *   collapse_recommendation: string,
     * }
     */
    public function evaluate(array $circuit): array
    {
        $cohesion = max(0.0, min(1.0, (float) ($circuit['cohesion'] ?? 0.5)));
        $coupling = max(0.0, min(1.0, (float) ($circuit['coupling'] ?? 0.5)));
        $duplicateHelperCount = max(0, (int) ($circuit['duplicate_helper_count'] ?? 0));
        $removableLines = max(0, (int) ($circuit['removable_lines'] ?? 0));

        $score = round($cohesion - $coupling, 4);

        $lowCohesion = $cohesion < self::LOW_COHESION_THRESHOLD;
        $highCoupling = $coupling > self::HIGH_COUPLING_THRESHOLD;
        $recommendation = ($lowCohesion && $highCoupling)
            ? self::RECOMMENDATION_SPLIT_OR_COLLAPSE
            : self::RECOMMENDATION_KEEP;

        $deletionUpside = round(($duplicateHelperCount * self::DUPLICATE_HELPER_WEIGHT) + ($removableLines / 10.0), 4);

        // Boundary drift: share of this circuit's references that reach OUTSIDE its own
        // boundary — no external/internal counts supplied ⇒ no drift evidence, never assumed.
        $externalRefs = max(0, (int) ($circuit['external_reference_count'] ?? 0));
        $internalRefs = max(0, (int) ($circuit['internal_reference_count'] ?? 0));
        $totalRefs = $externalRefs + $internalRefs;
        $boundaryDrift = $totalRefs > 0 ? round($externalRefs / $totalRefs, 4) : 0.0;

        // Fan-in/fan-out pressure: how skewed the circuit's coupling direction is — a hub with
        // near-zero fan-out, or a leaf with near-zero fan-in, both signal a wrong-shaped boundary.
        $fanIn = max(0, (int) ($circuit['fan_in'] ?? 0));
        $fanOut = max(0, (int) ($circuit['fan_out'] ?? 0));
        $fanTotal = $fanIn + $fanOut;
        $fanPressure = $fanTotal > 0 ? round(abs($fanIn - $fanOut) / $fanTotal, 4) : 0.0;

        $duplicateResponsibilityCount = max(0, (int) ($circuit['duplicate_responsibility_count'] ?? 0));

        // Owner gap: no explicit owner declaration ⇒ treated as a gap, never silently assumed owned.
        $ownerGap = ! (bool) ($circuit['has_declared_owner'] ?? false);

        $collapseRecommendation = match (true) {
            $recommendation === self::RECOMMENDATION_SPLIT_OR_COLLAPSE
                && ($ownerGap || $duplicateResponsibilityCount >= self::DUPLICATE_RESPONSIBILITY_THRESHOLD || $boundaryDrift >= self::BOUNDARY_DRIFT_THRESHOLD)
                => self::COLLAPSE_NOW,
            $recommendation === self::RECOMMENDATION_SPLIT_OR_COLLAPSE => self::COLLAPSE_SOON,
            $ownerGap || $duplicateResponsibilityCount >= self::DUPLICATE_RESPONSIBILITY_THRESHOLD
                || $boundaryDrift >= self::BOUNDARY_DRIFT_THRESHOLD || $fanPressure >= self::FAN_PRESSURE_THRESHOLD
                => self::COLLAPSE_MONITOR,
            default => self::COLLAPSE_KEEP,
        };

        return [
            'schema' => self::SCHEMA,
            'score' => $score,
            'recommendation' => $recommendation,
            'cohesion' => $cohesion,
            'coupling' => $coupling,
            'duplicate_helper_count' => $duplicateHelperCount,
            'removable_lines' => $removableLines,
            'deletion_upside' => $deletionUpside,
            'boundary_drift' => $boundaryDrift,
            'fan_in_out_pressure' => $fanPressure,
            'duplicate_responsibility_count' => $duplicateResponsibilityCount,
            'owner_gap' => $ownerGap,
            'collapse_recommendation' => $collapseRecommendation,
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
