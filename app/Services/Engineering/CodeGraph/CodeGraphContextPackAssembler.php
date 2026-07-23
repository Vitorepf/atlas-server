<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · E-3 — Minimal context-pack assembler under a token budget.
 *
 * The cross-project context engine ranks candidate code-graph nodes by relevance
 * (that ranking is a [py] block — heavy retrieval/scoring). THIS [php] block is the
 * frugal packer: given an ALREADY-ORDERED list of candidates (highest relevance
 * first) and a token budget, it decides the SMALLEST useful subset that fits, so the
 * agent's window carries the most-relevant context and nothing more.
 *
 * Packing policy:
 *
 *   - Walk the ranked list in order. Keep a running token total. Include a node while
 *     `running_total + node_tokens <= budget`.
 *   - DEFAULT (stop-and-exclude-rest): the first node that does not fit ends inclusion;
 *     every later node is excluded. This is stable and predictable — the included set
 *     is always a clean rank-ordered prefix, which is what a reader expects from a
 *     "top-k that fits" pack and never reorders relevance.
 *   - `$opts['fill_gaps'] === true` (gap-filling): after a node is skipped for being
 *     too big, keep scanning and admit any LATER node that still fits the remaining
 *     budget. This squeezes more signal into the window at the cost of the clean
 *     prefix property (a low-rank small node may appear while a higher-rank big one
 *     does not). Rank order is still preserved among the nodes that ARE included.
 *
 * `truncated` is true whenever anything was excluded (the pack is not the whole input).
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider. Same input always
 *     yields byte-identical output: a single in-order pass, no sorting, no tie-breaks.
 *   - Never throws on bad data. Non-array nodes are dropped to `excluded` (a packer
 *     never emits something it cannot account for). A missing / non-numeric / NaN /
 *     INF / negative token count is replaced by a positive default per-node cost
 *     (config 'atlas.code_graph.default_node_tokens', else 200) so an un-costed node
 *     is never treated as free — the budget-safe direction (never under-count and
 *     blow the window). Fractional costs are ceil-rounded up for the same reason.
 *   - A zero or negative budget admits nothing (every node is excluded, truncated is
 *     true when there was any input). The budget itself is clamped to >= 0 so a
 *     garbage negative budget can never produce a negative `estimated_tokens`.
 *   - Config is read with an inline default literal so it works without config edits;
 *     `$opts` overrides config per call.
 *
 * This is [php] by the runtime-language boundary: it GOVERNS what enters the window
 * (a budgeting decision), it does not compute the heavy relevance ranking.
 */
class CodeGraphContextPackAssembler
{
    public const SCHEMA = 'atlas.code_graph.context_pack_assembler.v1';

    /**
     * The absolute floor for a per-node token cost when none is usable. Kept >= 1 so a
     * misconfigured 0/negative default can never make nodes "free" and over-pack the
     * window.
     */
    private const MIN_NODE_TOKENS = 1;

    /**
     * Assemble the minimal context pack that fits the token budget.
     *
     * @param  array<int,mixed>  $rankedNodes  ranked candidates, HIGHEST relevance
     *   first. Each node SHOULD be an array carrying a numeric `tokens` cost (and an
     *   `id`, though no field is required). Non-array entries are dropped to
     *   `excluded`. A missing/garbage `tokens` is replaced by the default per-node
     *   cost. The list is consumed in the given order; it is never re-sorted.
     * @param  int  $tokenBudget  total token budget for the pack. Values <= 0 admit
     *   nothing. Clamped to >= 0 internally for the reported `budget`.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `fill_gaps` (bool): when true, keep scanning past a node that doesn't fit
     *     and admit later nodes that still fit (default false — stop-and-exclude-rest).
     *   - `default_node_tokens` (int|float): per-node cost used when a node's own
     *     `tokens` is missing/garbage (default from config
     *     'atlas.code_graph.default_node_tokens', else 200; floored to >= 1).
     * @return array{
     *   included: array<int,mixed>,
     *   excluded: array<int,mixed>,
     *   estimated_tokens: int,
     *   budget: int,
     *   truncated: bool,
     *   count: int
     * }
     *   `included` is the chosen subset in ranked order; `excluded` lists every node
     *   left out (non-array entries included), in input order. `estimated_tokens` is
     *   the summed cost of the included nodes (always <= `budget`). `count` is
     *   count(included). `truncated` is true iff anything was excluded.
     */
    public function assemble(array $rankedNodes, int $tokenBudget, array $opts = []): array
    {
        $budget = $tokenBudget > 0 ? $tokenBudget : 0;
        $defaultNodeTokens = $this->resolveDefaultNodeTokens($opts);
        $fillGaps = $this->resolveFillGaps($opts);

        $included = [];
        $excluded = [];
        $runningTotal = 0;
        // Once we hit a node that does not fit, in DEFAULT mode every remaining node is
        // excluded without a fit test. In fill_gaps mode we keep testing later nodes.
        $stopped = false;

        foreach ($rankedNodes as $node) {
            if ($stopped) {
                $excluded[] = $node;

                continue;
            }

            if (! is_array($node)) {
                // Unaccountable entry: cannot be costed, so it is excluded rather than
                // silently passed through (fail-safe — a packer accounts for every
                // token it admits).
                $excluded[] = $node;

                continue;
            }

            $cost = $this->nodeTokens($node, $defaultNodeTokens);

            if ($runningTotal + $cost <= $budget) {
                $included[] = $node;
                $runningTotal += $cost;

                continue;
            }

            // Node does not fit.
            $excluded[] = $node;

            if (! $fillGaps) {
                // Stop-and-exclude-rest: the included set stays a clean ranked prefix.
                $stopped = true;
            }
            // fill_gaps: fall through and keep scanning for a smaller later node.
        }

        return [
            'included' => array_values($included),
            'excluded' => array_values($excluded),
            'estimated_tokens' => $runningTotal,
            'budget' => $budget,
            'truncated' => count($excluded) > 0,
            'count' => count($included),
        ];
    }

    /**
     * A node's token cost: its own `tokens` when that is a positive finite number,
     * otherwise the default per-node cost. Fractional costs round UP (ceil) so a node
     * is never under-counted against the budget.
     *
     * @param  array<string,mixed>  $node
     */
    private function nodeTokens(array $node, int $default): int
    {
        if (! array_key_exists('tokens', $node)) {
            return $default;
        }

        $raw = $node['tokens'];
        if (is_int($raw) || is_float($raw)) {
            $value = (float) $raw;
        } elseif (is_string($raw) && is_numeric(trim($raw))) {
            $value = (float) trim($raw);
        } else {
            return $default;
        }

        if (is_nan($value) || is_infinite($value) || $value <= 0.0) {
            // Zero/negative/non-finite cost is meaningless for budgeting; fall back to
            // the default so the node is never treated as free.
            return $default;
        }

        $ceiled = (int) ceil($value);

        return $ceiled >= self::MIN_NODE_TOKENS ? $ceiled : self::MIN_NODE_TOKENS;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveDefaultNodeTokens(array $opts): int
    {
        if (array_key_exists('default_node_tokens', $opts)) {
            $candidate = $this->positiveIntOrNull($opts['default_node_tokens']);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        $configured = $this->positiveIntOrNull(config('atlas.code_graph.default_node_tokens', 200));

        return $configured ?? 200;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveFillGaps(array $opts): bool
    {
        // Strict identity check (=== true) per the contract: only the literal boolean
        // true enables gap-filling. Any other value (1, 'true', null, …) leaves the
        // safe default stop-and-exclude-rest behaviour in place.
        return array_key_exists('fill_gaps', $opts) && $opts['fill_gaps'] === true;
    }

    /**
     * Coerce a value to a positive int (>= MIN_NODE_TOKENS), or null when it is not a
     * usable positive finite number. Used for the default per-node cost so a
     * misconfigured 0/negative/garbage value falls back to the inline literal.
     */
    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $float = (float) $value;
        } elseif (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
        } else {
            return null;
        }

        if (is_nan($float) || is_infinite($float) || $float < 1.0) {
            return null;
        }

        return (int) floor($float);
    }
}
