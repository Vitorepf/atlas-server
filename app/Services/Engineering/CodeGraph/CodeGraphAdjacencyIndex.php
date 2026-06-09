<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · D-1 — Fast in-memory adjacency index for code-graph traversal at scale.
 *
 * The code graph is delivered as a flat list of edge rows ({@see CodeGraphInferredGuard}
 * produces such a list). Walking it for every traversal step is O(E) per hop, which
 * collapses at scale. This index builds, ONCE, the two adjacency maps a traversal
 * actually needs:
 *
 *   - forward  (out-neighbors):  node → [nodes it points AT]      via {@see neighbors()}
 *   - reverse  (in-neighbors):   node → [nodes that point TO it]  via {@see incoming()}
 *
 * so each subsequent neighbor lookup is O(1) plus the size of the returned list. The
 * structure is read-only after construction — it is a derived read model over the edge
 * set, not an authoring source.
 *
 * Edge shape (tolerant): each edge SHOULD be an array carrying its endpoints in
 * `from_node_id`/`from` and `to_node_id`/`to`. The canonical `*_node_id` keys win when
 * both are present. Endpoint ids may be strings or scalars (int ids are stringified) and
 * are trimmed; an empty id after trimming is not a node.
 *
 * Determinism (house contract):
 *   - Every returned list — {@see neighbors()}, {@see incoming()}, {@see nodes()} — is
 *     de-duplicated and sorted with a stable natural+case comparison, so the same edge
 *     set always yields byte-identical output regardless of input order or PHP's
 *     internal hashing. Self-loops are preserved (a node IS its own neighbor when an
 *     edge points it at itself) — that is real graph structure, not noise.
 *
 * Fail-safety (house contract):
 *   - Never throws on malformed input. Non-array edges, edges missing a usable
 *     endpoint, and non-scalar/empty ids are skipped silently. An empty or fully
 *     malformed edge set yields an empty-but-valid index (all accessors return safe
 *     defaults: [] / false / 0).
 *
 * Bounding (house contract):
 *   - The number of DISTINCT nodes admitted is capped by
 *     config('atlas.code_graph.max_adjacency_nodes', 200000) — a memory ceiling so a
 *     pathological edge dump cannot exhaust the process. Once the cap is reached, edges
 *     introducing a NEW node are skipped, but edges BETWEEN already-admitted nodes are
 *     still indexed. Admission order follows the input edge order (then endpoint order
 *     within an edge: from before to) so the bounded subset is deterministic.
 *
 * This is [php] by the runtime-language boundary: it is an in-memory index/lookup
 * structure (orchestration), not heavy ML/graph-analytics computation.
 */
class CodeGraphAdjacencyIndex
{
    public const SCHEMA = 'atlas.code_graph.adjacency_index.v1';

    /**
     * The conservative default node ceiling, mirrored from
     * config('atlas.code_graph.max_adjacency_nodes'). Kept as an inline literal so the
     * index works without any config edit; a configured value still overrides it.
     */
    public const DEFAULT_MAX_NODES = 200000;

    /**
     * forward[node] = set of out-neighbor ids (value-as-key set for O(1) dedup).
     *
     * @var array<string,array<string,true>>
     */
    private array $forward = [];

    /**
     * reverse[node] = set of in-neighbor ids (value-as-key set for O(1) dedup).
     *
     * @var array<string,array<string,true>>
     */
    private array $reverse = [];

    /**
     * The set of every admitted node id (value-as-key set), so a node with only
     * inbound, only outbound, or even no surviving edges is still "known".
     *
     * @var array<string,true>
     */
    private array $nodeSet = [];

    /**
     * @param  iterable<mixed>  $edges  edge rows (see class docblock for shape). Any
     *   non-array entry, or an array missing a usable from/to endpoint, is skipped.
     */
    public function __construct(iterable $edges = [])
    {
        $maxNodes = $this->resolveMaxNodes();

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = $this->endpoint($edge, ['from_node_id', 'from']);
            $to = $this->endpoint($edge, ['to_node_id', 'to']);

            // An edge needs BOTH endpoints to contribute adjacency.
            if ($from === null || $to === null) {
                continue;
            }

            // Admit endpoints under the node ceiling. `from` is offered before `to`
            // so admission is deterministic when the cap bites mid-edge.
            $fromKnown = $this->admit($from, $maxNodes);
            $toKnown = $this->admit($to, $maxNodes);

            // Only record the edge once BOTH endpoints are admitted nodes. (If the
            // cap blocked one endpoint, a half-edge would point at a non-node, so we
            // skip it — keeping the maps internally consistent with nodeSet.)
            if (! $fromKnown || ! $toKnown) {
                continue;
            }

            $this->forward[$from][$to] = true;
            $this->reverse[$to][$from] = true;
        }
    }

    /**
     * Convenience factory mirroring the house `fromEdges` style.
     *
     * @param  iterable<mixed>  $edges
     */
    public static function fromEdges(iterable $edges): self
    {
        return new self($edges);
    }

    /**
     * Forward out-neighbors of a node: the nodes it points AT.
     *
     * @return array<int,string> de-duplicated, deterministically sorted. Empty for an
     *   unknown node or a node with no outbound edges (never null, never throws).
     */
    public function neighbors(string $nodeId): array
    {
        $key = $this->normalizeId($nodeId);
        if ($key === null) {
            return [];
        }

        return $this->sortedKeys($this->forward[$key] ?? []);
    }

    /**
     * Reverse in-neighbors of a node: the nodes that point TO it.
     *
     * @return array<int,string> de-duplicated, deterministically sorted. Empty for an
     *   unknown node or a node with no inbound edges (never null, never throws).
     */
    public function incoming(string $nodeId): array
    {
        $key = $this->normalizeId($nodeId);
        if ($key === null) {
            return [];
        }

        return $this->sortedKeys($this->reverse[$key] ?? []);
    }

    /**
     * Whether the node was admitted to the index (has at least one surviving edge in
     * either direction). A normalized (trimmed) match is used, so a caller's untrimmed
     * id still resolves.
     */
    public function has(string $nodeId): bool
    {
        $key = $this->normalizeId($nodeId);

        return $key !== null && isset($this->nodeSet[$key]);
    }

    /**
     * Total degree of a node = out-degree + in-degree, counting DISTINCT neighbors per
     * direction (the maps are deduped). A self-loop therefore contributes 1 to the out
     * count and 1 to the in count (total 2), which is the standard directed-graph
     * convention. Unknown node → 0.
     */
    public function degree(string $nodeId): int
    {
        $key = $this->normalizeId($nodeId);
        if ($key === null) {
            return 0;
        }

        $out = isset($this->forward[$key]) ? count($this->forward[$key]) : 0;
        $in = isset($this->reverse[$key]) ? count($this->reverse[$key]) : 0;

        return $out + $in;
    }

    /**
     * Every node admitted to the index.
     *
     * @return array<int,string> de-duplicated, deterministically sorted.
     */
    public function nodes(): array
    {
        return $this->sortedKeys($this->nodeSet);
    }

    /**
     * The number of distinct nodes in the index.
     */
    public function count(): int
    {
        return count($this->nodeSet);
    }

    /**
     * Admit a node id under the ceiling. Returns true if the node is now (or was
     * already) known; false only when it is NEW and the ceiling is full.
     */
    private function admit(string $id, int $maxNodes): bool
    {
        if (isset($this->nodeSet[$id])) {
            return true;
        }

        if (count($this->nodeSet) >= $maxNodes) {
            return false;
        }

        $this->nodeSet[$id] = true;

        return true;
    }

    /**
     * Pull and normalize an endpoint id from the first matching key. The canonical
     * `*_node_id` key is listed first so it wins over the short alias.
     *
     * @param  array<array-key,mixed>  $edge
     * @param  array<int,string>  $keys
     */
    private function endpoint(array $edge, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $edge)) {
                continue;
            }
            $normalized = $this->normalizeId($edge[$key]);
            if ($normalized !== null) {
                return $normalized;
            }
            // Key present but value unusable (null/empty/non-scalar) → try the alias
            // before giving up, so a blank `from_node_id` can fall back to `from`.
        }

        return null;
    }

    /**
     * Coerce an arbitrary value to a usable node id: scalars are stringified and
     * trimmed; bools and non-scalars are rejected; an empty result is null. Centralizes
     * the id contract so build-time and query-time normalization can never diverge.
     */
    private function normalizeId(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_int($value) || is_float($value)) {
            // Reject non-finite floats outright; a NaN/INF id is meaningless.
            if (is_float($value) && (is_nan($value) || is_infinite($value))) {
                return null;
            }
            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        }

        // Bools, null, arrays, objects, resources are not node ids.
        return null;
    }

    /**
     * Deterministically order a value-as-key set: keys back to values, then a stable
     * natural+case-insensitive sort with a strict tie-break on the raw string so the
     * order is total (and so PHP array keys, which silently cast numeric-strings to
     * ints, are compared as the strings they logically are).
     *
     * @param  array<array-key,true>  $set
     * @return array<int,string>
     */
    private function sortedKeys(array $set): array
    {
        if ($set === []) {
            return [];
        }

        // array keys may be int (PHP casts numeric string keys) → restore to string.
        $keys = array_map(static fn ($k): string => (string) $k, array_keys($set));

        usort($keys, static function (string $a, string $b): int {
            return strnatcasecmp($a, $b) ?: strcmp($a, $b);
        });

        return $keys;
    }

    /**
     * The node ceiling from config, clamped to a sane positive integer. A missing,
     * non-numeric, or non-positive value falls back to {@see DEFAULT_MAX_NODES} so the
     * index can never be configured into accepting zero nodes or an absurd negative.
     */
    private function resolveMaxNodes(): int
    {
        $configured = config('atlas.code_graph.max_adjacency_nodes', self::DEFAULT_MAX_NODES);

        if (is_int($configured)) {
            $value = $configured;
        } elseif (is_float($configured) && ! is_nan($configured) && ! is_infinite($configured)) {
            $value = (int) $configured;
        } elseif (is_string($configured) && is_numeric(trim($configured))) {
            $value = (int) trim($configured);
        } else {
            $value = self::DEFAULT_MAX_NODES;
        }

        return $value > 0 ? $value : self::DEFAULT_MAX_NODES;
    }
}
