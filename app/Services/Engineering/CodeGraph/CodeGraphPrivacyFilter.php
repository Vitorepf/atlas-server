<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Sovereignty + tombstone redaction applied to a code-graph result before it
 * reaches any agent / provider (AP-811/812 M-4).
 *
 * This is the last-mile privacy gate: whatever the resolver/analytics produced,
 * NOTHING about a sensitive file (`.env`, secret/key material) or an explicitly
 * tombstoned node may be handed to a model. The filter removes those nodes,
 * then drops every edge that touches a removed node (so a sensitive node can
 * never be re-inferred from a dangling endpoint), and reports exactly what was
 * redacted so the caller can audit the cut.
 *
 * Pure transform: no DB, no IO, no provider. It only reshapes the array it is
 * given. The authoritative sensitive-path classification is shared with
 * {@see \App\Services\Engineering\CodeGraph\SemanticExtractionRequestBuilder}
 * (same default markers, same diff-secret / do-not-touch convention) so the
 * graph surface and the extraction surface redact identically — this does NOT
 * reinvent that policy, it applies the same one at the graph egress point.
 *
 * Determinism: input order of surviving nodes/edges is preserved; removed
 * collections are de-duplicated and stats are pure counts, so the same input
 * always yields byte-identical output.
 */
class CodeGraphPrivacyFilter
{
    public const SCHEMA = 'atlas.code_graph.privacy_filter.v1';

    /**
     * Case-insensitive substrings that classify a node path as sovereignty-
     * sensitive. Kept in lock-step with
     * {@see \App\Services\Engineering\CodeGraph\SemanticExtractionRequestBuilder::SENSITIVE_MARKERS}
     * — the task contract is ".env / secret / key" material; the broader list
     * (credential, private key, pem/p12/pfx/keystore) is the same do-not-ship
     * convention used across the engineering surfaces.
     *
     * @var array<int,string>
     */
    private const SENSITIVE_MARKERS = [
        '.env',
        'secret',
        'secrets',
        'credential',
        'private_key',
        'private-key',
        'id_rsa',
        '.pem',
        '.p12',
        '.pfx',
        '.key',
        '.keystore',
    ];

    /**
     * Filter a graph result, removing sensitive + tombstoned nodes and any edge
     * touching a removed node.
     *
     * @param  array<string,mixed>  $graphResult  a code-graph result: at least
     *   {nodes:array<int,array<string,mixed>>, edges:array<int,array<string,mixed>>}.
     *   A node is identified by its `node_id` (string) and may carry a `path`.
     *   An edge connects `from_node_id` -> `to_node_id`. Any other top-level keys
     *   on the result (schema_version, stats, ...) are preserved untouched.
     * @param  array<int,mixed>  $sensitivePaths  caller-supplied exact paths to treat
     *   as sensitive in addition to the marker-matched ones (e.g. operator-pinned
     *   secret files). Matched case-insensitively against a node's `path` AND its
     *   `node_id` (node ids are frequently path-derived).
     * @param  array<int,mixed>  $tombstonedNodeIds  node ids the operator has
     *   tombstoned (deleted-from-truth); they are removed regardless of path.
     * @return array{
     *   nodes:array<int,array<string,mixed>>,
     *   edges:array<int,array<string,mixed>>,
     *   redaction:array{removed_nodes:int, removed_edges:int}
     * }
     *   the input result with sensitive/tombstoned nodes and their incident edges
     *   removed; `schema_version` and any other passthrough keys are retained, and
     *   a `privacy` audit block is added alongside the `redaction` summary.
     */
    public function filter(array $graphResult, array $sensitivePaths = [], array $tombstonedNodeIds = []): array
    {
        $nodes = $this->rows($graphResult['nodes'] ?? null);
        $edges = $this->rows($graphResult['edges'] ?? null);

        $extraPaths = $this->normalizedSet($sensitivePaths);
        $tombstoned = $this->normalizedSet($tombstonedNodeIds);

        /** @var array<string,bool> $removedIds set of node ids that were removed */
        $removedIds = [];
        $removedSensitivePaths = [];
        $removedTombstoned = [];

        $keptNodes = [];
        foreach ($nodes as $node) {
            $nodeId = $this->stringOrNull($node['node_id'] ?? null);
            $path = $this->stringOrNull($node['path'] ?? null);

            $isTombstoned = $this->isTombstoned($nodeId, $tombstoned);
            $isSensitive = $this->isSensitive($path, $nodeId, $extraPaths);

            if (! $isTombstoned && ! $isSensitive) {
                $keptNodes[] = $node;

                continue;
            }

            // Remember the removed id so edges touching it can be dropped. A node
            // with no id can still be removed from the node list, but it cannot
            // poison an edge (edges reference ids), so id-tracking is sufficient.
            if ($nodeId !== null) {
                $removedIds[$this->key($nodeId)] = true;
            }
            if ($isTombstoned && $nodeId !== null) {
                $removedTombstoned[$this->key($nodeId)] = $nodeId;
            }
            if ($isSensitive) {
                $marker = $path ?? $nodeId;
                if ($marker !== null) {
                    $removedSensitivePaths[$this->key($marker)] = $marker;
                }
            }
        }

        $keptEdges = [];
        $removedEdgeCount = 0;
        foreach ($edges as $edge) {
            $from = $this->stringOrNull($edge['from_node_id'] ?? null);
            $to = $this->stringOrNull($edge['to_node_id'] ?? null);

            if ($this->touchesRemoved($from, $removedIds) || $this->touchesRemoved($to, $removedIds)) {
                $removedEdgeCount++;

                continue;
            }

            $keptEdges[] = $edge;
        }

        $removedNodeCount = count($nodes) - count($keptNodes);

        $result = $graphResult;
        $result['nodes'] = array_values($keptNodes);
        $result['edges'] = array_values($keptEdges);
        $result['redaction'] = [
            'removed_nodes' => $removedNodeCount,
            'removed_edges' => $removedEdgeCount,
        ];
        $result['privacy'] = [
            'filter' => self::SCHEMA,
            // Always true: the egress pass always ran. Asserts policy enforcement,
            // not that something matched (mirrors SemanticExtractionRequestBuilder).
            'sensitive_filtered' => true,
            'removed_sensitive_paths' => array_values($removedSensitivePaths),
            'removed_tombstoned_node_ids' => array_values($removedTombstoned),
            'removed_node_count' => $removedNodeCount,
            'removed_edge_count' => $removedEdgeCount,
        ];

        return $result;
    }

    /**
     * @param  array<string,bool>  $removedIds
     */
    private function touchesRemoved(?string $endpoint, array $removedIds): bool
    {
        return $endpoint !== null && isset($removedIds[$this->key($endpoint)]);
    }

    /**
     * @param  array<string,true>  $tombstoned
     */
    private function isTombstoned(?string $nodeId, array $tombstoned): bool
    {
        return $nodeId !== null && isset($tombstoned[$this->key($nodeId)]);
    }

    /**
     * Sensitive when either the path or the (often path-derived) node id matches
     * a default marker or a caller-supplied exact sensitive path.
     *
     * @param  array<string,true>  $extraPaths
     */
    private function isSensitive(?string $path, ?string $nodeId, array $extraPaths): bool
    {
        foreach ([$path, $nodeId] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $haystack = strtolower($candidate);
            if (isset($extraPaths[$haystack])) {
                return true;
            }
            foreach (self::SENSITIVE_MARKERS as $marker) {
                if (str_contains($haystack, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<string,true>
     */
    private function normalizedSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            $string = $this->stringOrNull($value);
            if ($string !== null) {
                $set[$this->key($string)] = true;
            }
        }

        return $set;
    }

    /**
     * @param  mixed  $rows
     * @return array<int,array<string,mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
    }

    private function key(string $value): string
    {
        return strtolower(trim($value));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
