<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · Q-1 — Self-audit of the code graph's basic structural health.
 *
 * A code graph is a read model an agent trusts to reason about a codebase. If the
 * graph is silently broken — nodes nobody points at, edges that reference symbols
 * that no longer exist — the agent reasons over a lie. This auditor is the cheap,
 * deterministic, PHP-side health check that surfaces those structural defects
 * BEFORE they reach a consumer. (Heavy graph statistics — community detection,
 * centrality, betweenness — are a Python follow-up by the runtime-language
 * boundary; this class deliberately stays to counting and set membership.)
 *
 * It answers, for a (nodes, edges) pair:
 *
 *   - node_count / edge_count   — the size of the graph as actually parsed.
 *   - orphan_nodes              — node ids with degree 0 (no edge touches them).
 *                                 An orphan is a recall hole: the node exists but
 *                                 the graph claims it relates to nothing.
 *   - dangling_edges            — edges whose `from`/`to` references a node id that
 *                                 is NOT in the node set. A dangling edge is an
 *                                 integrity defect: the graph claims a relationship
 *                                 to a symbol it does not know about.
 *   - coverage_ratio            — (nodes with degree ≥ 1) / node_count. The fraction
 *                                 of the graph that is actually connected; 1.0 means
 *                                 no orphans, 0.0 means an empty or fully isolated
 *                                 graph.
 *   - max_degree / avg_degree   — connectivity shape over the node set.
 *   - issues                    — human-readable summary lines for an operator/audit
 *                                 ("12 orphan nodes", "3 dangling edges"), empty when
 *                                 the graph is clean.
 *
 * Degree semantics: a node's degree is the number of edge ENDPOINTS that land on it
 * (an edge from A to A counts 2 toward A's degree — a self-loop genuinely touches
 * the node twice; it is still "connected", so it is never an orphan). An edge whose
 * one valid endpoint is a real node and whose other endpoint is missing still counts
 * degree for the real endpoint — that node IS genuinely referenced by an edge — while
 * the edge is additionally reported as dangling. We never inflate a node's degree for
 * an endpoint that is not in the set.
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider, no I/O. Same input
 *     always yields byte-identical output: `orphan_nodes` is returned in the node
 *     set's first-seen order, `dangling_edges` in the edges' input order, and every
 *     float is rounded to a fixed precision so assertions and audit diffs are stable.
 *   - Never throws on malformed/empty input. Non-array/garbage edges are skipped (and
 *     counted as malformed in `issues`); nodes with no resolvable id are skipped; a
 *     blank/duplicate node id is de-duplicated. An empty graph returns all-zero,
 *     coverage_ratio 0.0, empty arrays, no issues — never a divide-by-zero.
 *   - Config is read with an inline default literal (the audit precision) so it works
 *     without any config edit; `$opts` overrides per call.
 *
 * This is [php] by the runtime-language boundary: it GOVERNS graph admission by
 * reporting health (a decision-support read model). It does not compute heavy data.
 */
class CodeGraphHealthAuditor
{
    public const SCHEMA = 'atlas.code_graph.health_auditor.v1';

    /** Fixed rounding precision for reported floats — keeps output deterministic. */
    private const DEFAULT_PRECISION = 6;

    /**
     * Audit a (nodes, edges) graph for basic structural health.
     *
     * @param  array<int,mixed>  $nodes  Node descriptors. Each MAY be a string (the
     *   node id itself) or an array carrying its id in `node_id` (preferred) or `id`.
     *   Entries with no resolvable, non-blank id are ignored. Duplicate ids collapse
     *   to one node (the graph cannot hold the same symbol twice).
     * @param  array<int,mixed>  $edges  Edge descriptors. Each SHOULD be an array with
     *   a source in `from_node_id` (preferred) or `from`, and a target in
     *   `to_node_id` (preferred) or `to`. Non-array entries, or arrays with neither a
     *   resolvable source nor target, are skipped as malformed.
     * @param  array<string,mixed>  $opts  Per-call overrides:
     *   - `precision` (int): decimal places for reported floats (default from config
     *     'atlas.code_graph.health_precision', else 6; clamped to [0,12]).
     * @return array{
     *   node_count:int,
     *   edge_count:int,
     *   orphan_nodes:array<int,string>,
     *   dangling_edges:array<int,mixed>,
     *   coverage_ratio:float,
     *   max_degree:int,
     *   avg_degree:float,
     *   issues:array<int,string>
     * }
     *   `node_count` is the de-duplicated node total; `edge_count` is the number of
     *   well-formed edges actually considered (malformed entries excluded). Every
     *   value is deterministic for a given input.
     */
    public function audit(array $nodes, array $edges, array $opts = []): array
    {
        $precision = $this->resolvePrecision($opts);

        // --- Build the node set (de-duplicated, first-seen order preserved). ---
        // `degree` maps node_id → endpoint count. Using the node id as the key gives
        // us O(1) membership for dangling detection and natural de-duplication.
        $degree = [];      // array<string,int> node_id → degree, insertion-ordered
        foreach ($nodes as $node) {
            $id = $this->nodeId($node);
            if ($id === null) {
                // No resolvable id → not a node we can audit. Skipped silently
                // (counted in issues below via the node_count gap is not surfaced as
                // an error because a caller may legitimately pass placeholder rows).
                continue;
            }
            // Insertion order is preserved on first sight; a repeat id is a no-op.
            if (! array_key_exists($id, $degree)) {
                $degree[$id] = 0;
            }
        }

        $nodeCount = count($degree);

        // --- Walk edges: accumulate degree for known endpoints, flag dangling. ---
        $edgeCount = 0;          // well-formed edges considered
        $malformedCount = 0;     // entries that were not usable edges at all
        $danglingEdges = [];     // list<mixed> — original edge value, input order

        foreach ($edges as $edge) {
            $endpoints = $this->edgeEndpoints($edge);
            if ($endpoints === null) {
                // Not an array, or no resolvable source AND no resolvable target:
                // nothing to audit. Fail-safe: skip and count, never throw.
                $malformedCount++;

                continue;
            }

            $edgeCount++;
            [$from, $to] = $endpoints;

            $isDangling = false;

            // A null endpoint means "this side was not provided" — that is a
            // half-specified edge, which is itself a dangling/integrity defect
            // (the graph asserts a relationship with a missing end). A provided
            // endpoint that is not a known node is the classic dangling case.
            foreach ([$from, $to] as $endpoint) {
                if ($endpoint === null) {
                    $isDangling = true;

                    continue;
                }
                if (array_key_exists($endpoint, $degree)) {
                    // Known node: this endpoint genuinely connects the node.
                    $degree[$endpoint]++;
                } else {
                    // Provided but unknown → references a symbol not in the set.
                    $isDangling = true;
                }
            }

            if ($isDangling) {
                $danglingEdges[] = $edge;
            }
        }

        // --- Derive connectivity metrics from the degree map. ---
        $orphanNodes = [];   // list<string> — degree-0 node ids, first-seen order
        $connected = 0;      // nodes with degree ≥ 1
        $maxDegree = 0;
        $degreeSum = 0;

        foreach ($degree as $id => $deg) {
            $degreeSum += $deg;
            if ($deg === 0) {
                $orphanNodes[] = $id;
            } else {
                $connected++;
                if ($deg > $maxDegree) {
                    $maxDegree = $deg;
                }
            }
        }

        $coverageRatio = $nodeCount > 0 ? $connected / $nodeCount : 0.0;
        $avgDegree = $nodeCount > 0 ? $degreeSum / $nodeCount : 0.0;

        // --- Human-readable issue summary (empty when the graph is clean). ---
        $issues = $this->buildIssues(
            $nodeCount,
            $edgeCount,
            count($orphanNodes),
            count($danglingEdges),
            $malformedCount,
        );

        return [
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'orphan_nodes' => array_values($orphanNodes),
            'dangling_edges' => array_values($danglingEdges),
            'coverage_ratio' => $this->round($coverageRatio, $precision),
            'max_degree' => $maxDegree,
            'avg_degree' => $this->round($avgDegree, $precision),
            'issues' => $issues,
        ];
    }

    /**
     * Resolve a node descriptor to its id, or null if none is usable.
     *
     * A string node is its own id. An array node carries the id in `node_id`
     * (preferred) or `id`. The value is coerced to a trimmed string; blanks and
     * non-scalar ids are rejected (return null) so they cannot become a phantom
     * empty-string node.
     */
    private function nodeId(mixed $node): ?string
    {
        if (is_string($node)) {
            $trimmed = trim($node);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_array($node)) {
            foreach (['node_id', 'id'] as $key) {
                if (! array_key_exists($key, $node)) {
                    continue;
                }
                $id = $this->scalarId($node[$key]);
                if ($id !== null) {
                    return $id;
                }
            }

            return null;
        }

        // Bare scalar (int/float) used directly as an id — tolerate it.
        return $this->scalarId($node);
    }

    /**
     * Resolve an edge to its [from, to] endpoint ids.
     *
     * @return array{0:?string,1:?string}|null  null when the edge is not an array, or
     *   when NEITHER a source nor a target id can be resolved (a fully unusable edge).
     *   Either element may be null individually when only one side is provided.
     */
    private function edgeEndpoints(mixed $edge): ?array
    {
        if (! is_array($edge)) {
            return null;
        }

        $from = $this->endpointId($edge, ['from_node_id', 'from']);
        $to = $this->endpointId($edge, ['to_node_id', 'to']);

        if ($from === null && $to === null) {
            return null;
        }

        return [$from, $to];
    }

    /**
     * Read the first present, resolvable id from a list of candidate keys.
     *
     * @param  array<string,mixed>  $edge
     * @param  array<int,string>  $keys  candidate keys in priority order
     */
    private function endpointId(array $edge, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $edge)) {
                continue;
            }
            $id = $this->scalarId($edge[$key]);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Coerce a scalar id value to a non-blank trimmed string, or null.
     *
     * Strings are trimmed; ints/floats are stringified (an id may legitimately be
     * numeric). Floats use a canonical, locale-independent representation so the
     * same number always keys the same node. Bools, null, arrays and objects are
     * not valid ids → null.
     */
    private function scalarId(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // NaN/INF cannot be a stable id.
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            // Canonical: integral floats render without a trailing ".0" mismatch
            // against an int form of the same id; non-integral use a fixed
            // serialization independent of locale.
            if (floor($value) === $value && abs($value) < 1.0e15) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        return null;
    }

    /**
     * Compose the human-readable issue list. Order is fixed (malformed, dangling,
     * orphans) so the output is deterministic; lines pluralize correctly and the
     * list is empty for a clean graph.
     *
     * @return array<int,string>
     */
    private function buildIssues(
        int $nodeCount,
        int $edgeCount,
        int $orphanCount,
        int $danglingCount,
        int $malformedCount,
    ): array {
        $issues = [];

        if ($nodeCount === 0 && $edgeCount === 0 && $malformedCount === 0) {
            // A genuinely empty graph is not an "issue" to flag — it is just empty.
            return [];
        }

        if ($malformedCount > 0) {
            $issues[] = $malformedCount.' malformed '.$this->plural($malformedCount, 'edge').' skipped';
        }

        if ($danglingCount > 0) {
            $issues[] = $danglingCount.' dangling '.$this->plural($danglingCount, 'edge');
        }

        if ($orphanCount > 0) {
            $issues[] = $orphanCount.' orphan '.$this->plural($orphanCount, 'node');
        }

        return $issues;
    }

    /** Naive English pluralizer for the two nouns we emit ("node"/"edge"). */
    private function plural(int $count, string $noun): string
    {
        return $count === 1 ? $noun : $noun.'s';
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolvePrecision(array $opts): int
    {
        if (array_key_exists('precision', $opts)) {
            $candidate = $this->intOrNull($opts['precision']);
            if ($candidate !== null) {
                return $this->clampPrecision($candidate);
            }
        }

        $configured = $this->intOrNull(
            config('atlas.code_graph.health_precision', self::DEFAULT_PRECISION)
        );

        return $this->clampPrecision($configured ?? self::DEFAULT_PRECISION);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && ! is_nan($value) && ! is_infinite($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /** Keep precision in a sane, safe band for {@see round()}. */
    private function clampPrecision(int $value): int
    {
        if ($value < 0) {
            return 0;
        }
        if ($value > 12) {
            return 12;
        }

        return $value;
    }

    /** Round a reported float so stats are stable for assertions and audit diffs. */
    private function round(float $value, int $precision): float
    {
        if (is_nan($value) || is_infinite($value)) {
            return 0.0;
        }

        return round($value, $precision);
    }
}
