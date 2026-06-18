<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * LEVERAGE — the heart of the autonomous producer (the "rédea").
 *
 * Answers the operator's mandate mathematically: of all candidate leaps, which is the
 * BIGGEST jump for Atlas in the LEAST time? It scores each candidate objective by
 *
 *     leverage = (strategic_impact × breadth × compounding) / (cost × risk)
 *
 * and ranks descending. Every input is a 0..1 normalized signal with a documented
 * saturation, so the score is EXPLAINABLE (a per-component rationale travels with it)
 * and ANTI-GOODHART: leverage is strategic-progress-per-effort, never diff size or any
 * writable scalar. Pure + deterministic (no DB, no provider, no clock) so it freezes
 * under test and can never be the thing that breaks a run.
 *
 * The signals are supplied by the producer from the Atlas brain (callers = breadth,
 * cyclomatic/centrality = compounding debt, memory/goals/reality = strategic_impact,
 * size/coverage/pétreo-adjacency = cost/risk). This class only does the math + the
 * floors; gathering the brain signals is the producer's job.
 *
 * It aligns with the loop's existing leverage convention (caller-weighted, cyclomatic
 * second — see AtlasLoopNextWorkDecider) and ADDS the missing dimension the old rédea
 * never had: strategic alignment with where Atlas is actually trying to go.
 */
final class AtlasLoopLeverageScorer
{
    /** Caller count at/above which "breadth of unblock" saturates to 1.0. */
    private const BREADTH_SATURATION = 20.0;

    /** Worst-method cyclomatic at/above which structural-debt compounding saturates. */
    private const DEBT_SATURATION = 30.0;

    /** Cost/risk are clamped into [FLOOR, 1] so a near-zero denominator can't explode the score. */
    private const COST_RISK_FLOOR = 0.15;

    /**
     * Strategic impact assumed when the brain has NO signal for a candidate. Deliberately
     * low (not zero) so unaligned work is strongly de-prioritised but a genuinely high
     * breadth+debt hub can still surface — fail-open, never fail-blind.
     */
    private const STRATEGIC_DEFAULT = 0.30;

    /**
     * Score ONE candidate. The candidate is a normalized signal packet; missing signals
     * fail-open to conservative defaults rather than throwing.
     *
     * @param  array{
     *     path?:string, shape?:string,
     *     caller_count?:int, cyclomatic?:int,
     *     strategic_impact?:float, compounding?:float,
     *     cost?:float, risk?:float, verifiable?:bool
     * }  $c
     * @return array{leverage:float, components:array<string,float>, rationale:string, verifiable:bool}
     */
    public function score(array $c): array
    {
        $callers = max(0, (int) ($c['caller_count'] ?? 0));
        $cyclomatic = max(0, (int) ($c['cyclomatic'] ?? 0));

        // breadth of unblock — how much of Atlas this leap touches/frees (saturating).
        $breadth = min(1.0, $callers / self::BREADTH_SATURATION);

        // compounding — does landing this enable FUTURE leaps? Default-derived from
        // structural centrality (a wired, complex hub compounds), or taken explicitly.
        $compounding = array_key_exists('compounding', $c)
            ? $this->clamp01((float) $c['compounding'])
            : $this->clamp01(0.5 * $breadth + 0.5 * min(1.0, $cyclomatic / self::DEBT_SATURATION));

        // strategic impact — alignment with where Atlas is actually going (from the brain).
        $impact = array_key_exists('strategic_impact', $c)
            ? $this->clamp01((float) $c['strategic_impact'])
            : self::STRATEGIC_DEFAULT;

        // cost & risk — effort and danger; clamped so they shrink, never explode, leverage.
        $cost = $this->clampCostRisk($c['cost'] ?? 0.5);
        $risk = $this->clampCostRisk($c['risk'] ?? 0.5);

        $leverage = ($impact * $breadth * $compounding) / ($cost * $risk);

        $components = [
            'strategic_impact' => round($impact, 4),
            'breadth' => round($breadth, 4),
            'compounding' => round($compounding, 4),
            'cost' => round($cost, 4),
            'risk' => round($risk, 4),
        ];

        return [
            'leverage' => round($leverage, 4),
            'components' => $components,
            'rationale' => $this->rationale($c['path'] ?? '?', $c['shape'] ?? '?', $components, $leverage),
            'verifiable' => (bool) ($c['verifiable'] ?? false),
        ];
    }

    /**
     * Rank candidates by leverage, descending. Returns each candidate's original packet
     * merged with its score under `_score`, so the producer keeps provenance.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    public function rank(array $candidates): array
    {
        $scored = array_map(function (array $c): array {
            $c['_score'] = $this->score($c);

            return $c;
        }, $candidates);

        usort($scored, static fn (array $a, array $b): int => $b['_score']['leverage'] <=> $a['_score']['leverage']);

        return $scored;
    }

    /**
     * The AMBITION FLOOR: a candidate is worth the loop's time ONLY if it is a real leap.
     * It must clear the leverage floor AND be a genuine unblock (breadth or compounding
     * present) AND be verifiable (the gate can prove it) — otherwise it is trivia/faxina
     * or a dream the gate can't certify, and is rejected by construction.
     *
     * @param  array{leverage:float, components:array<string,float>, verifiable:bool}  $score
     */
    public function passesAmbitionFloor(array $score, ?float $leverageFloor = null): bool
    {
        $floor = $leverageFloor ?? (float) config('atlas.loop.producer_leverage_floor', 0.6);
        $minBreadthOrCompounding = (float) config('atlas.loop.producer_min_unblock', 0.25);

        if (! ($score['verifiable'] ?? false)) {
            return false;
        }
        if (($score['leverage'] ?? 0.0) < $floor) {
            return false;
        }
        $unblock = max(
            (float) ($score['components']['breadth'] ?? 0.0),
            (float) ($score['components']['compounding'] ?? 0.0),
        );

        return $unblock >= $minBreadthOrCompounding;
    }

    private function rationale(string $path, string $shape, array $cmp, float $leverage): string
    {
        return sprintf(
            '%s [%s] leverage=%.2f  (impact=%.2f × breadth=%.2f × compounding=%.2f) ÷ (cost=%.2f × risk=%.2f)',
            $path, $shape, $leverage,
            $cmp['strategic_impact'], $cmp['breadth'], $cmp['compounding'], $cmp['cost'], $cmp['risk'],
        );
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private function clampCostRisk(mixed $v): float
    {
        return max(self::COST_RISK_FLOOR, min(1.0, (float) $v));
    }
}
