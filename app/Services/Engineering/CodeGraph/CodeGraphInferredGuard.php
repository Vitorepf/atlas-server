<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Support\Clamp01;

/**
 * AP-815 · Q-4 — Anti-over-claim guard at the EDGE level for the code graph.
 *
 * The code graph carries two grades of edge:
 *
 *   - EXTRACTED — proven by a real parser/extractor (a literal `use`, an AST call,
 *     an import). These are evidence; the graph is allowed to claim them outright.
 *   - INFERRED — guessed by a heuristic/LLM ("these two probably relate"). These
 *     are useful for recall but, unbounded, they let the graph OVER-CLAIM: a wall
 *     of low-confidence guesses drowns the proven structure and an agent reading
 *     the graph mistakes inference for fact.
 *
 * This guard enforces the canon anti-over-claim invariant at the edge boundary:
 *
 *   1. EXTRACTED edges are ALWAYS kept — never dropped, never reordered, never
 *      counted against the cap. Evidence is sacred.
 *   2. INFERRED edges below a minimum score are dropped (too weak to claim at all).
 *   3. The surviving INFERRED population is then capped so that
 *      inferred / total  ≤  max_inferred_ratio. When the cap bites, the
 *      LOWEST-score inferred edges are dropped first (keep the strongest guesses).
 *
 * The result reports exactly what was kept/dropped plus a stats block proving the
 * final `inferred_ratio` is at or under the `cap`, so the cut is auditable.
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider. Same input always
 *     yields byte-identical output: the cap drop sorts by (score asc, then original
 *     input index asc) — a total order, so ties never depend on PHP's unstable sort.
 *   - Never throws on bad data. Non-array edges are skipped; missing/garbage scores
 *     are clamped to [0,1]; an inferred edge with NO usable score is treated as the
 *     weakest possible (score 0.0) so it is the FIRST to be capped — the
 *     over-claim-safe direction (we never let an unscored guess survive ahead of a
 *     scored one). Extracted edges never need a score.
 *   - Config is read with inline default literals so it works without config edits;
 *     `$opts` overrides config per call. Bounds are clamped to sane ranges
 *     (min_score → [0,1]; max_ratio → [0,1)) so a misconfigured 1.0 cap can never
 *     disable the guard's structural ceiling.
 *
 * This is [php] by the runtime-language boundary: it GOVERNS edge admission
 * (a decision), it does not compute heavy graph data.
 */
class CodeGraphInferredGuard
{
    public const SCHEMA = 'atlas.code_graph.inferred_guard.v1';

    /** The two recognised edge grades. Anything not INFERRED is treated as EXTRACTED. */
    public const GRADE_EXTRACTED = 'extracted';

    public const GRADE_INFERRED = 'inferred';

    /**
     * Apply the anti-over-claim policy to a set of edges.
     *
     * @param  array<int,mixed>  $edges  edges to filter. Each edge SHOULD be an
     *   array carrying its grade in `edge_type` or `confidence`
     *   ('extracted'|'inferred', case-insensitive) and optionally a numeric `score`
     *   in [0,1]. Non-array entries are ignored. An edge with no recognised grade
     *   is treated as EXTRACTED (trusted) — the guard never invents a low grade.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `min_score` (float): drop INFERRED edges scoring below this (default from
     *     config 'atlas.code_graph.min_inferred_score', else 0.2).
     *   - `max_ratio` (float): cap on inferred/total (default from config
     *     'atlas.code_graph.max_inferred_ratio', else 0.35).
     * @return array{
     *   kept: array<int,mixed>,
     *   dropped: array<int,mixed>,
     *   stats: array{
     *     total:int, extracted:int, inferred:int,
     *     kept_inferred:int, dropped_inferred:int,
     *     inferred_ratio:float, cap:float
     *   }
     * }
     *   `kept` preserves the ORIGINAL input order of all surviving edges (extracted
     *   and the inferred that survived). `dropped` lists every removed edge.
     *   `stats.total` is the kept total; `inferred_ratio` is kept_inferred/total
     *   (0.0 when nothing is kept) and is guaranteed ≤ `cap`.
     */
    public function apply(array $edges, array $opts = []): array
    {
        $minScore = $this->resolveMinScore($opts);
        $maxRatio = $this->resolveMaxRatio($opts);

        // Partition into extracted (always kept) and inferred candidates, carrying
        // each row's original input index so we can (a) restore input order at the
        // end and (b) tie-break the cap drop deterministically.
        $extracted = [];        // list<array{idx:int, edge:mixed}>
        $inferredCandidates = []; // list<array{idx:int, edge:mixed, score:float}>
        $dropped = [];          // list<array{idx:int, edge:mixed}> — removed, audited

        $index = 0;
        foreach ($edges as $edge) {
            $currentIndex = $index++;

            if (! is_array($edge)) {
                // Malformed entry: cannot be classified or scored. It is not an
                // extracted edge we can trust, so it is dropped (fail-safe: a guard
                // never passes through something it cannot reason about).
                $dropped[] = ['idx' => $currentIndex, 'edge' => $edge];

                continue;
            }

            if ($this->grade($edge) === self::GRADE_INFERRED) {
                $inferredCandidates[] = [
                    'idx' => $currentIndex,
                    'edge' => $edge,
                    'score' => $this->score($edge),
                ];

                continue;
            }

            // Extracted (or unlabelled → trusted as extracted): kept unconditionally.
            $extracted[] = ['idx' => $currentIndex, 'edge' => $edge];
        }

        // Step 2 — drop inferred below the minimum score.
        $survivors = []; // list<array{idx:int, edge:mixed, score:float}>
        foreach ($inferredCandidates as $candidate) {
            if ($candidate['score'] < $minScore) {
                $dropped[] = ['idx' => $candidate['idx'], 'edge' => $candidate['edge']];

                continue;
            }
            $survivors[] = $candidate;
        }

        // Step 3 — cap the inferred:total ratio. Extracted edges all survive, so the
        // denominator is (extracted + kept_inferred). Find the largest number of
        // inferred we may keep such that kept_inferred / (extracted + kept_inferred)
        // ≤ cap, then drop the weakest inferred beyond that count.
        $extractedCount = count($extracted);
        $allowedInferred = $this->maxInferredAllowed($extractedCount, count($survivors), $maxRatio);

        if (count($survivors) > $allowedInferred) {
            // Sort weakest-first for dropping: score ASC, then original index ASC.
            // The index makes the order total (no reliance on usort stability).
            usort($survivors, static function (array $a, array $b): int {
                return $a['score'] <=> $b['score']
                    ?: $a['idx'] <=> $b['idx'];
            });

            $overflow = array_slice($survivors, 0, count($survivors) - $allowedInferred);
            foreach ($overflow as $candidate) {
                $dropped[] = ['idx' => $candidate['idx'], 'edge' => $candidate['edge']];
            }
            $survivors = array_slice($survivors, count($survivors) - $allowedInferred);
        }

        $keptInferredCount = count($survivors);

        // Restore original input order across all survivors (extracted + inferred).
        $keptCombined = array_merge($extracted, $survivors);
        usort($keptCombined, static fn (array $a, array $b): int => $a['idx'] <=> $b['idx']);

        $kept = array_map(static fn (array $row): mixed => $row['edge'], $keptCombined);

        // `dropped` is ordered by original input index for a stable, auditable list.
        usort($dropped, static fn (array $a, array $b): int => $a['idx'] <=> $b['idx']);
        $droppedEdges = array_map(static fn (array $row): mixed => $row['edge'], $dropped);

        $total = $extractedCount + $keptInferredCount;
        $inferredRatio = $total > 0 ? $keptInferredCount / $total : 0.0;

        return [
            'kept' => array_values($kept),
            'dropped' => array_values($droppedEdges),
            'stats' => [
                'total' => $total,
                'extracted' => $extractedCount,
                'inferred' => count($inferredCandidates),
                'kept_inferred' => $keptInferredCount,
                'dropped_inferred' => count($inferredCandidates) - $keptInferredCount,
                'inferred_ratio' => $this->round($inferredRatio),
                'cap' => $this->round($maxRatio),
            ],
        ];
    }

    /**
     * Largest number of inferred edges that may be kept so that
     * inferred / (extracted + inferred) ≤ cap.
     *
     * Closed form: from k / (E + k) ≤ cap  ⇒  k ≤ E·cap / (1 − cap) when cap < 1.
     * We take floor and never exceed the number actually available. A cap of 0
     * forbids all inferred; the clamp in {@see resolveMaxRatio()} keeps cap < 1 so
     * the denominator is always positive.
     */
    private function maxInferredAllowed(int $extractedCount, int $availableInferred, float $cap): int
    {
        if ($availableInferred <= 0) {
            return 0;
        }
        if ($cap <= 0.0) {
            return 0;
        }

        $limit = (int) floor(($extractedCount * $cap) / (1.0 - $cap));

        if ($limit < 0) {
            $limit = 0;
        }

        return min($limit, $availableInferred);
    }

    /**
     * Classify an edge as inferred or extracted. Reads `edge_type` first, then
     * `confidence`. Any value that is not the literal 'inferred' (case-insensitive,
     * trimmed) leaves the edge as extracted — the trusted default.
     *
     * @param  array<string,mixed>  $edge
     */
    private function grade(array $edge): string
    {
        foreach (['edge_type', 'confidence'] as $field) {
            if (! array_key_exists($field, $edge)) {
                continue;
            }
            $value = $edge[$field];
            if (! is_string($value)) {
                continue;
            }
            $normalized = strtolower(trim($value));
            if ($normalized === self::GRADE_INFERRED) {
                return self::GRADE_INFERRED;
            }
            if ($normalized === self::GRADE_EXTRACTED) {
                return self::GRADE_EXTRACTED;
            }
            // Unrecognised string on this field → fall through to the next field,
            // and ultimately to the trusted (extracted) default.
        }

        return self::GRADE_EXTRACTED;
    }

    /**
     * The edge's score, clamped to [0,1]. A missing or non-numeric score becomes
     * 0.0 — the weakest value — so an unscored inferred edge is the first to be
     * capped and (when min_score > 0) is dropped by the floor. This is the
     * over-claim-safe direction: never let an unproven, unscored guess outrank a
     * scored one.
     *
     * @param  array<string,mixed>  $edge
     */
    private function score(array $edge): float
    {
        if (! array_key_exists('score', $edge)) {
            return 0.0;
        }

        $raw = $edge['score'];
        if (is_int($raw) || is_float($raw)) {
            $value = (float) $raw;
        } elseif (is_string($raw) && is_numeric(trim($raw))) {
            $value = (float) trim($raw);
        } else {
            return 0.0;
        }

        if (is_nan($value) || is_infinite($value)) {
            return 0.0;
        }

        return Clamp01::of($value);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveMinScore(array $opts): float
    {
        if (array_key_exists('min_score', $opts)) {
            $candidate = $this->numericOrNull($opts['min_score']);
            if ($candidate !== null) {
                return Clamp01::of($candidate);
            }
        }

        $configured = $this->numericOrNull(config('atlas.code_graph.min_inferred_score', 0.2));

        return Clamp01::of($configured ?? 0.2);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveMaxRatio(array $opts): float
    {
        if (array_key_exists('max_ratio', $opts)) {
            $candidate = $this->numericOrNull($opts['max_ratio']);
            if ($candidate !== null) {
                return $this->clampRatio($candidate);
            }
        }

        $configured = $this->numericOrNull(config('atlas.code_graph.max_inferred_ratio', 0.35));

        return $this->clampRatio($configured ?? 0.35);
    }

    private function numericOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $float = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
        } else {
            return null;
        }

        if (is_nan($float) || is_infinite($float)) {
            return null;
        }

        return $float;
    }

    /** Clamp to [0,1]. */

    /**
     * Clamp a ratio cap to [0, 1). It must stay strictly below 1 so the closed-form
     * denominator (1 − cap) is positive and a misconfigured "1.0" can never silently
     * disable the structural ceiling. The epsilon ceiling (~0.999999) is the most
     * permissive value that still keeps the guard mathematically well-defined.
     */
    private function clampRatio(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        $ceiling = 0.999999;
        if ($value > $ceiling) {
            return $ceiling;
        }

        return $value;
    }

    /** Round reported floats so stats are stable for assertions and audit. */
    private function round(float $value): float
    {
        return round($value, 6);
    }
}
