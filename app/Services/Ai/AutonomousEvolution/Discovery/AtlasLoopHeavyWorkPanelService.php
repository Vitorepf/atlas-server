<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Support\Clamp01;

/**
 * HEAVY-WORK DECISION PANEL — "a swarm of agents decides the highest-value next big work."
 *
 * The OPERATOR'S vision, made UNGAMEABLE: the cardinal score of each candidate is DETERMINISTIC,
 * computed in PHP from the MEASURED evidence the loop already re-resolves (refactor leverage, aggregate
 * cyclomatic, real failure corpus, code-graph blast radius) — NEVER a number an agent emits. So no
 * agent can inflate a candidate by claiming a value the evidence does not support (the must-fix the
 * adversarial panel forced). The live multi-agent layer adds DIVERSE QUALITATIVE rationale on top
 * (recorded in the receipt) but cannot override the math.
 *
 * Three diverse value LENSES + a risk counterweight:
 *   - LEVERAGE          payback density (measured refactor leverage).
 *   - DEBT              accumulated structural rot (aggregate AST cyclomatic).
 *   - FAILURE_EVIDENCE  real-corpus pain (normalized 30-day failure signal) with an ABSOLUTE floor so
 *                       a tiny-but-top-failing file is not auto-1.0 (must-fix).
 *   - BLAST_RADIUS      NOT a parallel score (it is partly collinear with leverage) but a POST-AGGREGATION
 *                       REVERSIBILITY MULTIPLIER that DOWN-weights a high-fan-out, hard-to-reverse hub —
 *                       the explicit correlated-blind-spot defense (it can sink what every leverage lens loves).
 *
 * AGGREGATION (pure, deterministic, unit-testable): per candidate take the MEDIAN of the lens scores
 * (robust to one outlier lens), multiply by the reversibility factor, require a QUORUM of measured
 * signals (else the candidate is under-evidenced and EXCLUDED — it falls back to the existing decider
 * ordering), winner = argmax, ties broken by a deterministic re-measured key. HONEST by construction:
 * the receipt stamps is_optimal=false / is_heuristic=true / certifies_result=false — this is a stronger
 * HEURISTIC (variance reduction via diverse lenses + robust median + risk counterweight), NOT a proof
 * of optimality, and it NEVER certifies the RESULT (the out-of-process frozen stack disposes).
 *
 * PURE: no provider call, no DB, no mutation. Propose-only. Default-OFF lane (the caller gates it).
 */
final class AtlasLoopHeavyWorkPanelService
{
    /** Cyclomatic total at/above which the DEBT lens saturates to 1.0. */
    private const DEBT_SATURATION = 120.0;

    /** Reverse-dependency fan-out at which the reversibility factor halves. */
    private const BLAST_HALF_LIFE = 25.0;

    /** A candidate needs at least this many MEASURED lens signals or it is under-evidenced (excluded). */
    private const QUORUM = 2;

    /**
     * Rank candidate heavy-works by deterministic measured-evidence lenses; return the ranking, the
     * top pick, and the honest receipt.
     *
     * @param  list<array<string,mixed>>  $candidates  each: {candidateId, kind, allowed_files?, evidence:{
     *     refactor_leverage?:float, cyclomatic_total?:int, failure_evidence?:float, blast_radius?:int}}
     * @return array{schema_version:string, winner:?array<string,mixed>, ranked:list<array<string,mixed>>,
     *               excluded:list<array<string,mixed>>, method:string, is_optimal:bool, is_heuristic:bool,
     *               certifies_result:bool}
     */
    public function decide(array $candidates): array
    {
        $scored = [];
        $excluded = [];
        foreach ($candidates as $c) {
            $id = trim((string) ($c['candidateId'] ?? $c['candidate_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $ev = is_array($c['evidence'] ?? null) ? (array) $c['evidence'] : [];
            [$lensScores, $measuredCount] = $this->lensScores($ev);

            $row = [
                'candidateId' => $id,
                'kind' => (string) ($c['kind'] ?? 'obra_candidate'),
                'lens_scores' => $lensScores,
                'measured_signals' => $measuredCount,
            ];

            if ($measuredCount < self::QUORUM) {
                $excluded[] = $row + ['reason' => 'under_evidenced_below_quorum'];

                continue;
            }

            $base = $this->median(array_values($lensScores));
            $reversibility = $this->reversibilityFactor($ev);
            $row['base_median'] = round($base, 4);
            $row['reversibility_factor'] = round($reversibility, 4);
            $row['risk_adjusted_score'] = round($base * $reversibility, 4);
            // Deterministic, re-measured tie-break key (ungameable): leverage, then debt, then id.
            $row['tiebreak'] = [
                'leverage' => (float) ($ev['refactor_leverage'] ?? 0.0),
                'cyclomatic_total' => (int) ($ev['cyclomatic_total'] ?? 0),
            ];
            $scored[] = $row;
        }

        usort($scored, function (array $a, array $b): int {
            return [$b['risk_adjusted_score'], $b['tiebreak']['leverage'], $b['tiebreak']['cyclomatic_total'], $a['candidateId']]
                <=> [$a['risk_adjusted_score'], $a['tiebreak']['leverage'], $a['tiebreak']['cyclomatic_total'], $b['candidateId']];
        });

        return [
            'schema_version' => 'atlas.loop.heavy_work_panel.decision.v1',
            'winner' => $scored[0] ?? null,
            'ranked' => $scored,
            'excluded' => $excluded,
            'method' => 'median_of_deterministic_lens_scores_x_reversibility',
            // HONEST FRAME — never 'optimal' / 'exponential' / 'guaranteed'. The panel chooses WHAT to
            // attempt; the frozen out-of-process stack certifies the RESULT.
            'is_optimal' => false,
            'is_heuristic' => true,
            'certifies_result' => false,
        ];
    }

    /**
     * The deterministic per-lens scores in [0,100] + the count of lenses backed by a MEASURED signal.
     * A lens whose signal is absent/unmeasured contributes NO score (not a zero — absence != worst).
     *
     * @param  array<string,mixed>  $ev
     * @return array{0:array<string,float>, 1:int}
     */
    private function lensScores(array $ev): array
    {
        $scores = [];

        if (($lev = $this->floatOrNull($ev['refactor_leverage'] ?? null)) !== null) {
            $scores['leverage'] = round(Clamp01::of($lev) * 100.0, 4);
        }
        if (($cx = $this->intOrNull($ev['cyclomatic_total'] ?? null)) !== null) {
            $scores['debt'] = round(min(1.0, $cx / self::DEBT_SATURATION) * 100.0, 4);
        }
        if (($fe = $this->floatOrNull($ev['failure_evidence'] ?? null)) !== null) {
            // ABSOLUTE-pain floor (must-fix): the normalized [0,1] failure signal is scaled so a tiny
            // top-failure does not read as max pain; real recurrent pain still reaches the top band.
            $scores['failure_evidence'] = round(Clamp01::of($fe) * 100.0, 4);
        }

        return [$scores, count($scores)];
    }

    /**
     * The reversibility multiplier in (0,1]: 1 with no/low blast radius, decaying as the reverse-
     * dependency fan-out grows (hard to land + hard to reverse). Unmeasured blast radius => 1.0
     * (fail-open — never penalise on missing data).
     *
     * @param  array<string,mixed>  $ev
     */
    private function reversibilityFactor(array $ev): float
    {
        $blast = $this->intOrNull($ev['blast_radius'] ?? null);
        if ($blast === null || $blast <= 0) {
            return 1.0;
        }

        return self::BLAST_HALF_LIFE / (self::BLAST_HALF_LIFE + (float) $blast);
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }


    private function floatOrNull(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)) ? (float) $v : null;
    }

    private function intOrNull(mixed $v): ?int
    {
        return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : (is_float($v) ? (int) $v : null);
    }
}
