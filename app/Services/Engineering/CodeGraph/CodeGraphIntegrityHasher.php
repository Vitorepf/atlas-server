<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

/**
 * AP-815 · G-8 — Verifiable, reproducible graph-snapshot integrity hash.
 *
 * Produces a deterministic content hash of a workspace's code graph so two
 * independent index runs of the SAME graph are byte-for-byte comparable and the
 * integrity of a persisted read-model is provable (tamper / drift detection).
 *
 * The hash is computed over a CANONICAL form of the graph:
 *
 *   1. Each node is reduced to its stable id (a node string, or an array's
 *      `node_id` / `id`); each edge to the triple (from, to, type) read from
 *      `from_node_id`|`from`, `to_node_id`|`to`, `edge_type`|`type`.
 *   2. Nodes are sorted by id; edges sorted by (from, to, type). Sorting makes
 *      the hash ORDER-INDEPENDENT: the same graph fed in any order hashes equal.
 *   3. A graph is a SET — identical nodes and identical edge-triples are
 *      de-duplicated, so a repeated row cannot perturb identity.
 *   4. The canonical structure is serialized with json_encode using sorted,
 *      explicit keys (no float/locale ambiguity), then sha256'd.
 *
 * Pure of DB, clock and randomness — given the same graph it ALWAYS returns the
 * same digest. This is [php] by the runtime-language boundary: it governs /
 * proves identity; it performs NO heavy data or ML work.
 *
 * Fail-safe: malformed, empty or non-list input never throws. Unusable nodes /
 * edges are skipped; an empty graph yields a STABLE, non-empty canonical-empty
 * digest so callers always receive a comparable hash.
 */
class CodeGraphIntegrityHasher
{
    /**
     * The hashing algorithm recorded in every snapshot. Pinned so a stored hash
     * can be re-verified with the exact same algorithm later.
     */
    public const ALGO = 'sha256';

    /**
     * Bumped only if the canonical form changes in a way that would alter the
     * digest for an unchanged graph. Consumers compare across the same version.
     */
    public const SCHEMA_VERSION = 'atlas.code_graph.integrity.v1';

    /**
     * Compute the order-independent sha256 content hash of a graph.
     *
     * @param  array<mixed>  $nodes  Node strings, or arrays carrying `node_id`|`id`.
     * @param  array<mixed>  $edges  Edge arrays carrying from/to/type keys.
     * @return string Lowercase 64-char hex sha256 digest. Stable for an empty graph.
     */
    public function hash(array $nodes, array $edges): string
    {
        return hash(self::ALGO, $this->canonicalForm($nodes, $edges));
    }

    /**
     * Build a verifiable snapshot descriptor for a workspace's graph.
     *
     * @param  array<mixed>  $nodes
     * @param  array<mixed>  $edges
     * @return array{
     *     workspace_id: string,
     *     graph_hash: string,
     *     node_count: int,
     *     edge_count: int,
     *     algo: string,
     *     schema_version: string
     * }
     */
    public function snapshot(string $workspaceId, array $nodes, array $edges): array
    {
        $canonicalNodes = $this->canonicalNodes($nodes);
        $canonicalEdges = $this->canonicalEdges($edges);

        return [
            'workspace_id' => $this->normalizeWorkspaceId($workspaceId),
            // Hash the already-canonicalized collections so the counts below and
            // the digest describe exactly the same (deduped, sorted) graph.
            'graph_hash' => hash(self::ALGO, $this->encode([
                'nodes' => $canonicalNodes,
                'edges' => $canonicalEdges,
            ])),
            'node_count' => count($canonicalNodes),
            'edge_count' => count($canonicalEdges),
            'algo' => self::ALGO,
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * The exact JSON string the digest is taken over. Exposed for debugging /
     * proving WHY two graphs differ; not part of the stable public contract.
     *
     * @param  array<mixed>  $nodes
     * @param  array<mixed>  $edges
     */
    public function canonicalForm(array $nodes, array $edges): string
    {
        return $this->encode([
            'nodes' => $this->canonicalNodes($nodes),
            'edges' => $this->canonicalEdges($edges),
        ]);
    }

    /**
     * Reduce nodes to a sorted, de-duplicated list of stable ids.
     *
     * @param  array<mixed>  $nodes
     * @return list<string>
     */
    private function canonicalNodes(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $id = $this->nodeId($node);
            if ($id !== null) {
                // Key by id to de-duplicate; value preserved for the value list.
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * Reduce edges to a sorted, de-duplicated list of [from, to, type] triples.
     *
     * @param  array<mixed>  $edges
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function canonicalEdges(array $edges): array
    {
        $seen = [];
        foreach ($edges as $edge) {
            $triple = $this->edgeTriple($edge);
            if ($triple === null) {
                continue;
            }

            // A NUL-delimited key is a faithful, collision-free de-dup signature:
            // NUL cannot appear in a sane identifier, so distinct triples never
            // alias and identical triples always collapse to one.
            $key = $triple[0]."\0".$triple[1]."\0".$triple[2];
            $seen[$key] = $triple;
        }

        $triples = array_values($seen);

        // Total order over the triple so the list is canonical regardless of the
        // input order or the (unstable) order map keys were inserted.
        usort($triples, static function (array $a, array $b): int {
            return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
        });

        return $triples;
    }

    /**
     * Resolve a node's stable id from a string or an array shape. Returns null
     * when no usable id is present (the node is skipped, never fatal).
     *
     * @param  mixed  $node
     */
    private function nodeId($node): ?string
    {
        if (is_string($node)) {
            return $this->cleanId($node);
        }

        if ($node instanceof \Stringable) {
            return $this->cleanId((string) $node);
        }

        if (is_int($node) || is_float($node)) {
            return $this->cleanId($this->scalarToString($node));
        }

        if (is_array($node)) {
            // Precedence: explicit graph id key, then generic id key.
            foreach (['node_id', 'id'] as $key) {
                if (array_key_exists($key, $node)) {
                    $value = $this->scalarToString($node[$key]);
                    $clean = $this->cleanId($value);
                    if ($clean !== null) {
                        return $clean;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve an edge's (from, to, type) triple. Returns null when either
     * endpoint is missing (a danglingless edge is meaningless → skipped).
     *
     * @param  mixed  $edge
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function edgeTriple($edge): ?array
    {
        if (! is_array($edge)) {
            // An edge may also arrive as a positional [from, to, type?] tuple.
            return null;
        }

        $from = $this->firstKey($edge, ['from_node_id', 'from']);
        $to = $this->firstKey($edge, ['to_node_id', 'to']);

        // Positional fallback: [from, to, type] when no associative keys exist.
        if ($from === null && $to === null && array_is_list($edge)) {
            $from = isset($edge[0]) ? $this->cleanId($this->scalarToString($edge[0])) : null;
            $to = isset($edge[1]) ? $this->cleanId($this->scalarToString($edge[1])) : null;
        }

        if ($from === null || $to === null) {
            return null;
        }

        $type = $this->firstKey($edge, ['edge_type', 'type']);
        if ($type === null && array_is_list($edge) && isset($edge[2])) {
            $type = $this->cleanId($this->scalarToString($edge[2]));
        }
        // Type is optional; normalize a missing type to a stable sentinel so the
        // triple shape is uniform and two type-less edges hash identically.
        $type ??= '';

        return [$from, $to, $type];
    }

    /**
     * Return the first cleanable value among $keys, else null.
     *
     * @param  array<mixed>  $source
     * @param  list<string>  $keys
     */
    private function firstKey(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $clean = $this->cleanId($this->scalarToString($source[$key]));
                if ($clean !== null) {
                    return $clean;
                }
            }
        }

        return null;
    }

    /**
     * Coerce a scalar-ish value to a canonical string. Non-scalar (array/object
     * without Stringable) collapses to '' so it is treated as absent, not fatal.
     *
     * @param  mixed  $value
     */
    private function scalarToString($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Avoid locale- and precision-dependent float rendering: NaN/INF are
            // not valid ids; finite floats render with full precision, trimmed.
            if (! is_finite($value)) {
                return '';
            }

            $rendered = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

            return $rendered === '' || $rendered === '-' ? '0' : $rendered;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Trim and reject empty ids. A blank id carries no identity and would let
     * unrelated nodes/edges collide, so it is dropped (returns null).
     */
    private function cleanId(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalize the workspace id label for the snapshot. Fail-safe to a stable
     * placeholder so the snapshot shape is always well-formed.
     */
    private function normalizeWorkspaceId(string $workspaceId): string
    {
        $trimmed = trim($workspaceId);

        return $trimmed === '' ? 'unknown-workspace' : $trimmed;
    }

    /**
     * Deterministic JSON serialization of the canonical structure.
     *
     * JSON_UNESCAPED_SLASHES / UNICODE keep ids readable and stable; the array
     * is built with explicit, already-sorted keys so encoding is reproducible.
     * Guarded against the (here unreachable) encode failure so we never throw.
     *
     * @param  array<string,mixed>  $canonical
     */
    private function encode(array $canonical): string
    {
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        // json_encode can only return false on resources/NAN/INF, all already
        // filtered above. Fall back to a deterministic marker rather than throw.
        return $json !== false ? $json : 'atlas.code_graph.integrity.encode_error';
    }
}
