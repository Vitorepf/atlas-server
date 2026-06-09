<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · Q-3 — Regression detector for the code graph between two index runs.
 *
 * The code graph is rebuilt on every index pass. A rebuild can SHRINK it for the
 * wrong reasons — a parser regressed, a workspace was half-scanned, a glob excluded
 * a tree, an extractor crashed mid-run — and the result is a graph that silently got
 * WORSE while still looking "fresh". This detector diffs the previous snapshot
 * against the new one and flags those drops so the degradation is caught at the seam
 * instead of poisoning every downstream context query.
 *
 * What it computes:
 *   - added/removed nodes and edges, plus the net node/edge deltas,
 *   - the before/after node & edge sizes (so the report stands alone), and
 *   - `regressions`: human-readable lines, each describing a meaningful drop.
 *
 * A snapshot may arrive in either shape (both are supported per side, mixable):
 *   1. FULL   — ['nodes' => array, 'edges' => array]. Sizes are counted by DISTINCT
 *      identity (node by id/node_id; edge by from+to+type), so duplicate rows in a
 *      raw dump never inflate the count, and added/removed are TRUE set differences.
 *   2. COUNT  — ['node_count' => int, 'edge_count' => int]. Cheap to persist; carries
 *      no identities, so added/removed for that side fall back to the net delta
 *      (added = max(0, after − before), removed = max(0, before − after)). This is
 *      the honest direction: we never fabricate per-element churn we cannot prove.
 *   When BOTH sides are FULL, added/removed are exact set diffs (churn is visible even
 *   when the totals are unchanged — e.g. 10 nodes swapped for 10 others).
 *
 * Regression rules (all on the AFTER-vs-BEFORE direction; growth is never a
 * regression):
 *   - edge count fell by ≥ drop ratio → "edge count dropped 40% (1000 -> 600)";
 *   - node count fell by ≥ drop ratio → same, for nodes;
 *   - either count went to 0 while the other side had > 0 → "graph emptied ..." —
 *     reported as the most severe drop and NEVER duplicated by the ratio rule.
 * The drop ratio is config('atlas.code_graph.regression_drop_ratio', 0.25) (25%),
 * overridable per call via $opts['drop_ratio']; clamped to (0,1].
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider. Same input always
 *     yields byte-identical output. Identity sets are compared as plain associative
 *     maps so ordering of the input rows never changes any count.
 *   - Never throws on bad data. Non-array snapshots, missing keys, non-array
 *     node/edge collections, non-array rows, blank ids, NaN/INF counts — all degrade
 *     to safe zeros. A malformed side is treated as an empty graph (size 0), which is
 *     the over-claim-safe direction: a snapshot we cannot read counts as "nothing",
 *     so a real graph following a garbage one is correctly seen as growth (no false
 *     regression), and garbage following a real graph is correctly flagged as a drop.
 *
 * This is [php] by the runtime-language boundary: it GOVERNS a quality gate
 * (a decision — "did the graph regress?"), it does not compute heavy graph data.
 * Heavier statistical drift (distribution shifts, per-type regressions) is a [py]
 * follow-up.
 */
class CodeGraphRegressionDetector
{
    public const SCHEMA = 'atlas.code_graph.regression_detector.v1';

    /** Default fraction a count may fall before it is flagged a regression (25%). */
    private const DEFAULT_DROP_RATIO = 0.25;

    /**
     * Diff two graph snapshots and flag regressions.
     *
     * @param  array<string,mixed>  $before  the previous snapshot (FULL or COUNT shape).
     * @param  array<string,mixed>  $after  the new snapshot (FULL or COUNT shape).
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `drop_ratio` (float, (0,1]): fraction a count may fall before it is a
     *     regression (default config 'atlas.code_graph.regression_drop_ratio', else 0.25).
     * @return array{
     *   added_nodes:int, removed_nodes:int,
     *   added_edges:int, removed_edges:int,
     *   node_delta:int, edge_delta:int,
     *   before:array{nodes:int, edges:int},
     *   after:array{nodes:int, edges:int},
     *   regressions:array<int,string>
     * }
     *   `node_delta`/`edge_delta` are signed (after − before; negative = shrank).
     *   `regressions` is empty when nothing meaningfully dropped; its lines are
     *   ordered nodes-then-edges and are stable for assertion/audit.
     */
    public function diff(array $before, array $after, array $opts = []): array
    {
        $dropRatio = $this->resolveDropRatio($opts);

        $beforeNodeIds = $this->nodeIdentities($before);
        $afterNodeIds = $this->nodeIdentities($after);
        $beforeEdgeIds = $this->edgeIdentities($before);
        $afterEdgeIds = $this->edgeIdentities($after);

        // Sizes: prefer the distinct-identity count when a side is FULL; otherwise
        // fall back to the declared count-only integer.
        $beforeNodes = $this->sizeOf($before, $beforeNodeIds, 'node_count');
        $afterNodes = $this->sizeOf($after, $afterNodeIds, 'node_count');
        $beforeEdges = $this->sizeOf($before, $beforeEdgeIds, 'edge_count');
        $afterEdges = $this->sizeOf($after, $afterEdgeIds, 'edge_count');

        // added/removed: exact set diff when BOTH sides carry identities; otherwise
        // derive from the net delta (we cannot prove per-element churn without ids).
        [$addedNodes, $removedNodes] = $this->churn(
            $beforeNodeIds,
            $afterNodeIds,
            $beforeNodes,
            $afterNodes,
        );
        [$addedEdges, $removedEdges] = $this->churn(
            $beforeEdgeIds,
            $afterEdgeIds,
            $beforeEdges,
            $afterEdges,
        );

        $regressions = [];
        $this->flag($regressions, 'node', $beforeNodes, $afterNodes, $dropRatio);
        $this->flag($regressions, 'edge', $beforeEdges, $afterEdges, $dropRatio);

        return [
            'added_nodes' => $addedNodes,
            'removed_nodes' => $removedNodes,
            'added_edges' => $addedEdges,
            'removed_edges' => $removedEdges,
            'node_delta' => $afterNodes - $beforeNodes,
            'edge_delta' => $afterEdges - $beforeEdges,
            'before' => ['nodes' => $beforeNodes, 'edges' => $beforeEdges],
            'after' => ['nodes' => $afterNodes, 'edges' => $afterEdges],
            'regressions' => $regressions,
        ];
    }

    /**
     * Build the set of DISTINCT node identities for a snapshot, or null when the
     * snapshot is COUNT-only (no `nodes` array to read identities from).
     *
     * Identity is the node's id/node_id (string-cast, trimmed). Rows with no usable
     * id are kept as positional, distinct entries (so an un-id'd dump still counts)
     * under a reserved synthetic key that can never collide with a real id.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,true>|null
     */
    private function nodeIdentities(array $snapshot): ?array
    {
        if (! array_key_exists('nodes', $snapshot) || ! is_array($snapshot['nodes'])) {
            return null;
        }

        $ids = [];
        $position = 0;
        foreach ($snapshot['nodes'] as $node) {
            $key = $this->nodeKey($node);
            if ($key === null) {
                // No resolvable id: count it positionally so it is distinct and never
                // collides with a keyed node or another un-id'd row.
                $key = "\0node#".$position;
            }
            $ids[$key] = true;
            $position++;
        }

        return $ids;
    }

    /**
     * Build the set of DISTINCT edge identities for a snapshot, or null when the
     * snapshot is COUNT-only (no `edges` array).
     *
     * Identity is (from, to, type). Edges with no resolvable from/to are kept as
     * positional distinct entries so an un-keyable dump still contributes to the size.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,true>|null
     */
    private function edgeIdentities(array $snapshot): ?array
    {
        if (! array_key_exists('edges', $snapshot) || ! is_array($snapshot['edges'])) {
            return null;
        }

        $ids = [];
        $position = 0;
        foreach ($snapshot['edges'] as $edge) {
            $key = $this->edgeKey($edge);
            if ($key === null) {
                $key = "\0edge#".$position;
            }
            $ids[$key] = true;
            $position++;
        }

        return $ids;
    }

    /**
     * Resolve a node's identity key from id/node_id. Returns null when no usable id
     * is present (the caller substitutes a positional key).
     */
    private function nodeKey(mixed $node): ?string
    {
        if (is_string($node) || is_int($node) || is_float($node)) {
            $scalar = $this->stringify($node);

            return $scalar !== '' ? $scalar : null;
        }

        if (! is_array($node)) {
            return null;
        }

        foreach (['id', 'node_id'] as $field) {
            if (! array_key_exists($field, $node)) {
                continue;
            }
            $value = $this->stringify($node[$field]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve an edge's identity key from (from, to, type). Accepts the long
     * `from_node_id`/`to_node_id` and short `from`/`to` key families; `type` falls
     * back to `edge_type`. Returns null when neither endpoint is resolvable.
     */
    private function edgeKey(mixed $edge): ?string
    {
        if (! is_array($edge)) {
            return null;
        }

        $from = $this->firstNonEmpty($edge, ['from_node_id', 'from', 'source', 'src']);
        $to = $this->firstNonEmpty($edge, ['to_node_id', 'to', 'target', 'dst']);

        if ($from === null && $to === null) {
            return null;
        }

        $type = $this->firstNonEmpty($edge, ['type', 'edge_type', 'relation', 'rel']) ?? '';

        // Null-byte separators cannot appear in a stringified scalar, so the tuple
        // is unambiguous (no "a|b" vs "a" + "|b" collision).
        return ($from ?? '')."\0".($to ?? '')."\0".$type;
    }

    /**
     * First key in $fields whose value stringifies non-empty, else null.
     *
     * @param  array<string,mixed>  $row
     * @param  array<int,string>  $fields
     */
    private function firstNonEmpty(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = $this->stringify($row[$field]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Cast a scalar identity component to a trimmed string; non-scalars → ''. */
    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return '';
            }

            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    /**
     * The size of one side: the distinct-identity count when identities are present
     * (FULL shape), otherwise the declared COUNT-only integer, otherwise 0.
     *
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,true>|null  $identities
     */
    private function sizeOf(array $snapshot, ?array $identities, string $countKey): int
    {
        if ($identities !== null) {
            return count($identities);
        }

        if (array_key_exists($countKey, $snapshot)) {
            return $this->intCount($snapshot[$countKey]);
        }

        return 0;
    }

    /**
     * Coerce a declared count to a non-negative int. Floats are floored; NaN/INF,
     * negatives, and non-numerics become 0 (a count cannot be negative or unreadable).
     */
    private function intCount(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value) || $value <= 0.0) {
                return 0;
            }

            return (int) floor($value);
        }
        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float) || $float <= 0.0) {
                return 0;
            }

            return (int) floor($float);
        }

        return 0;
    }

    /**
     * Compute [added, removed] for one collection.
     *
     * When BOTH sides carry identities, this is an exact set difference, so churn is
     * visible even when totals match (10 swapped for 10 → added 10, removed 10). When
     * either side is COUNT-only we cannot know per-element churn, so we report the net
     * change: a net growth as `added`, a net shrink as `removed` (never both).
     *
     * @param  array<string,true>|null  $beforeIds
     * @param  array<string,true>|null  $afterIds
     * @return array{0:int,1:int}
     */
    private function churn(?array $beforeIds, ?array $afterIds, int $beforeSize, int $afterSize): array
    {
        if ($beforeIds !== null && $afterIds !== null) {
            $added = count(array_diff_key($afterIds, $beforeIds));
            $removed = count(array_diff_key($beforeIds, $afterIds));

            return [$added, $removed];
        }

        $delta = $afterSize - $beforeSize;

        return [max(0, $delta), max(0, -$delta)];
    }

    /**
     * Append a regression line for one dimension ('node'|'edge') if the after-count
     * dropped meaningfully. Order of checks (most severe first, never duplicated):
     *   1. emptied — before > 0 and after == 0;
     *   2. ratio drop — after < before and the drop fraction ≥ $dropRatio.
     *
     * @param  array<int,string>  $regressions
     */
    private function flag(array &$regressions, string $dimension, int $before, int $after, float $dropRatio): void
    {
        // No prior graph (or it was already empty) → nothing could have regressed.
        if ($before <= 0) {
            return;
        }

        $plural = $dimension.'s';

        if ($after === 0) {
            $regressions[] = "graph emptied: {$plural} fell to 0 (was {$before})";

            return;
        }

        if ($after >= $before) {
            return; // unchanged or grew → not a regression.
        }

        $dropFraction = ($before - $after) / $before;
        if ($dropFraction < $dropRatio) {
            return; // a drop, but within the allowed tolerance.
        }

        $percent = $this->formatPercent($dropFraction);
        $regressions[] = "{$dimension} count dropped {$percent} ({$before} -> {$after})";
    }

    /**
     * Render a drop fraction as a human percent. Whole percentages render without a
     * decimal ("40%"); fractional ones keep one decimal ("33.3%") so the line is
     * informative without noise. Deterministic for a given fraction.
     */
    private function formatPercent(float $fraction): string
    {
        $percent = $fraction * 100.0;
        $rounded = round($percent, 1);

        if ($rounded === floor($rounded)) {
            return ((int) $rounded).'%';
        }

        return rtrim(rtrim(number_format($rounded, 1, '.', ''), '0'), '.').'%';
    }

    /**
     * Resolve the drop ratio from $opts then config, clamped to (0,1]. The lower
     * bound is a tiny epsilon (not 0) so a misconfigured 0 can never make the gate
     * fire on every infinitesimal drop; the upper bound 1.0 means "only a full
     * collapse counts". A non-numeric/NaN/INF value falls back to the default.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveDropRatio(array $opts): float
    {
        if (array_key_exists('drop_ratio', $opts)) {
            $candidate = $this->numericOrNull($opts['drop_ratio']);
            if ($candidate !== null) {
                return $this->clampRatio($candidate);
            }
        }

        $configured = $this->numericOrNull(
            config('atlas.code_graph.regression_drop_ratio', self::DEFAULT_DROP_RATIO),
        );

        return $this->clampRatio($configured ?? self::DEFAULT_DROP_RATIO);
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

    /** Clamp a drop ratio to (0,1]: at least a tiny epsilon, at most a full collapse. */
    private function clampRatio(float $value): float
    {
        $floor = 0.000001;
        if ($value < $floor) {
            return $floor;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
