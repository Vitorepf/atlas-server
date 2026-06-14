<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * AMBITION-WEIGHTED decider — the operator's explicit strategy: ALWAYS choose the work that lets Atlas
 * evolve the MOST per commit, preferring the BIG, high-return leap over the small safe one, EVEN at
 * lower landing probability. In a week this compounds into evolution that would otherwise take years.
 *
 * WHY THIS IS MATHEMATICALLY SOUND (not reckless): the loop's failures are CHEAP, REVERSIBLE, and
 * QUALITY-PRESERVING — a failed obra is discarded, main is byte-identical, the frozen gates NEVER let a
 * bad result merge. So the payoff is CONVEX (a barbell): bounded downside (a wasted attempt) + huge
 * upside (a giant leap). Under a convex payoff you MAXIMISE THE MAGNITUDE, not the probability — a few
 * rare huge wins dominate the portfolio. Classic risk-neutral EV under-bets the big leap; this decider
 * corrects that with a risk-tolerance dial.
 *
 *     score = leap_magnitude · P(land)^riskTolerance − cost
 *
 *   - leap_magnitude  the size of the evolution IF it lands — UNCAPPED (a giant feature ≫ a small
 *                     refactor). The DOMINANT term: ambition is the objective.
 *   - P(land)         the Bayesian landing probability, dampened by riskTolerance ∈ [0,1]:
 *                     1 = risk-neutral EV; →0 = pure magnitude (max risk-seeking). Default 0.35 — P
 *                     barely dampens, so the big risky leap wins (the operator's #3-over-#2).
 *   - cost            a small attempt-spend penalty (the only real downside).
 *
 * THE HONEST COUPLING (what keeps risk-seeking SAFE, not gambling): residual UNDETECTED risk grows with
 * size — the bigger/more-coupled the change, the more a verification blind spot could slip through. So
 * the bigger the chosen leap, the STRONGER the verification it MUST clear ({@see requiredVerification}).
 * Risk-seeking on AMBITION is only sound when paired with risk-AVERSION on the GATE: attempt the
 * biggest leaps, but merge only what passes proportionally stronger proof. The decider chooses WHAT to
 * attempt; the frozen out-of-process stack still disposes. Pure: no provider, no DB, no mutation.
 */
final class AtlasLoopAmbitionDecider
{
    /** Default risk tolerance: low => P barely dampens => the big leap is preferred (the operator's intent). */
    public const DEFAULT_RISK_TOLERANCE = 0.35;

    private const COST_WEIGHT = 0.05;   // attempt-spend penalty: small, so it never sinks a big leap

    /**
     * @param  list<array<string,mixed>>  $candidates  each: {candidateId, leap_magnitude:float (uncapped),
     *     p_land:float [0,1], cost?:float}
     * @return array{schema_version:string, risk_tolerance:float, winner:?array<string,mixed>,
     *               ranked:list<array<string,mixed>>, strategy:string, bounded_downside_assumption:string}
     */
    public function rank(array $candidates, ?float $riskTolerance = null): array
    {
        $rt = $riskTolerance ?? self::DEFAULT_RISK_TOLERANCE;
        $rt = max(0.0, min(1.0, $rt));

        $ranked = [];
        foreach ($candidates as $c) {
            $id = trim((string) ($c['candidateId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $mag = max(0.0, (float) ($c['leap_magnitude'] ?? 0.0));
            $p = max(0.0, min(1.0, (float) ($c['p_land'] ?? 0.0)));
            $cost = max(0.0, (float) ($c['cost'] ?? 0.0));

            // P^riskTolerance: with rt<1 the probability is SOFTENED — a low P is not a near-veto, so a
            // 10x-magnitude leap at 30% beats a 1x leap at 95% (the convex-payoff bet).
            $pFactor = $p === 0.0 ? 0.0 : $p ** $rt;
            $score = round($mag * $pFactor - self::COST_WEIGHT * $cost, 6);

            $ranked[] = [
                'candidateId' => $id,
                'leap_magnitude' => round($mag, 4),
                'p_land' => round($p, 4),
                'p_factor' => round($pFactor, 4),
                'score' => $score,
                'required_verification' => $this->requiredVerification($mag),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['score'], $b['leap_magnitude'], $a['candidateId']] <=> [$a['score'], $a['leap_magnitude'], $b['candidateId']]);

        return [
            'schema_version' => 'atlas.loop.ambition_decision.v1',
            'risk_tolerance' => round($rt, 4),
            'winner' => $ranked[0] ?? null,
            'ranked' => $ranked,
            'strategy' => 'risk_seeking_on_ambition_risk_averse_on_the_gate',
            // The strategy is sound ONLY because this holds — and the loop enforces it by construction.
            'bounded_downside_assumption' => 'failures are discarded, main byte-identical, frozen gates never merge a bad result; bigger leaps require proportionally stronger verification',
        ];
    }

    /**
     * The verification a chosen leap MUST clear, scaling with its magnitude — the honest coupling that
     * makes accepting landing-risk safe (the residual undetected-risk grows with size).
     */
    private function requiredVerification(float $magnitude): array
    {
        if ($magnitude >= 7.0) {
            // ENORMOUS leap: every gate + a proven trust level before any autonomous merge.
            return ['tier' => 'maximal', 'gates' => ['frozen_tests', 'aggregate_complexity_or_behaviour', 'l4_10_real', 'broader_regression', 'mutation_adequacy', 'cross_file_consumer'], 'autonomous_merge_requires' => 'trust_ladder_autonomous'];
        }
        if ($magnitude >= 3.0) {
            return ['tier' => 'strong', 'gates' => ['frozen_tests', 'aggregate_complexity_or_behaviour', 'l4_10_real', 'broader_regression'], 'autonomous_merge_requires' => 'trust_ladder_autonomous'];
        }

        return ['tier' => 'standard', 'gates' => ['frozen_tests', 'aggregate_complexity_or_behaviour', 'l4_10_real'], 'autonomous_merge_requires' => 'operator_review'];
    }
}
