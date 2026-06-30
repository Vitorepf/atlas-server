<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Ranks brain opportunities by compounding leverage instead of ease.
 *
 * Scoring dimensions (weights sum to 1.0):
 *   - capability_unlock       (0.30) New architectural capability unlocked.
 *   - dependency_unblock      (0.20) Downstream tasks / capabilities directly unblocked.
 *   - implementation_evidence (0.20) Evidence that this is feasible and well-scoped.
 *   - repeated_pain           (0.15) Recurrence — recurring problems compound leverage.
 *   - blast_radius_safety     (0.15) Containment (high = safe, low = wide blast radius).
 *
 * Anti-proxy penalties (additive, capped at 1.0, then applied multiplicatively):
 *   - cosmetic_cli        -0.25  CLI wrapper adding no new capability behind it.
 *   - one_test_microtask  -0.20  Single-test scope with no systemic reach.
 *   - duplicated_target   -0.30  Target already covered by another open task.
 *   - already_satisfied   -0.35  Capability already verified as present.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainLeverageScorer
{
    public const SCHEMA = 'atlas.external_brain.leverage_scorer.v1';

    private const WEIGHTS = [
        'capability_unlock'       => 0.30,
        'dependency_unblock'      => 0.20,
        'implementation_evidence' => 0.20,
        'repeated_pain'           => 0.15,
        'blast_radius_safety'     => 0.15,
    ];

    private const PENALTY_FACTORS = [
        'cosmetic_cli'       => 0.25,
        'one_test_microtask' => 0.20,
        'duplicated_target'  => 0.30,
        'already_satisfied'  => 0.35,
    ];

    /**
     * Score a single opportunity.
     *
     * @param  array<string, mixed>  $opportunity
     * @return array{schema:string, label:string, weighted_sum:float, penalty:float, final_score:float, dimension_scores:array<string,float>, triggered_penalties:list<string>}
     */
    public function score(array $opportunity): array
    {
        $label = (string) ($opportunity['label'] ?? '');

        $dimensionScores = [];
        $weightedSum = 0.0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $clamped = max(0.0, min(1.0, (float) ($opportunity[$dim] ?? 0.0)));
            $dimensionScores[$dim] = $clamped;
            $weightedSum += $clamped * $weight;
        }

        $penalty = 0.0;
        $triggeredPenalties = [];
        foreach (self::PENALTY_FACTORS as $flag => $factor) {
            if ((bool) ($opportunity[$flag] ?? false)) {
                $penalty += $factor;
                $triggeredPenalties[] = $flag;
            }
        }
        $penalty = min(1.0, $penalty);

        return [
            'schema'              => self::SCHEMA,
            'label'               => $label,
            'weighted_sum'        => round($weightedSum, 4),
            'penalty'             => round($penalty, 4),
            'final_score'         => round($weightedSum * (1.0 - $penalty), 4),
            'dimension_scores'    => $dimensionScores,
            'triggered_penalties' => $triggeredPenalties,
        ];
    }

    /**
     * Score and rank opportunities by final_score descending.
     *
     * @param  list<array<string,mixed>>  $opportunities
     * @return list<array<string,mixed>>
     */
    public function rank(array $opportunities): array
    {
        $scored = array_map(fn (array $opp): array => $this->score($opp), $opportunities);
        usort($scored, static fn (array $a, array $b): int => $b['final_score'] <=> $a['final_score']);

        return array_values($scored);
    }

    /** @return list<array{dimension:string,weight:float}> */
    public function dimensions(): array
    {
        return array_map(
            static fn (string $dim, float $w): array => ['dimension' => $dim, 'weight' => $w],
            array_keys(self::WEIGHTS),
            array_values(self::WEIGHTS),
        );
    }

    /** @return list<array{penalty:string,factor:float}> */
    public function penalties(): array
    {
        return array_map(
            static fn (string $p, float $f): array => ['penalty' => $p, 'factor' => $f],
            array_keys(self::PENALTY_FACTORS),
            array_values(self::PENALTY_FACTORS),
        );
    }
}
