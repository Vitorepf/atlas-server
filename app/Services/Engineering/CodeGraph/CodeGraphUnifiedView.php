<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Unifies the static code graph with the Atlas Universal Reality Graph (AURG) into
 * one queryable edge set (AP-812 M-9 — the marquee leap).
 *
 * Two graphs describe the same system from different angles:
 *  - the *code* graph ({@see CodeGraphEdgeResolver}): file X imports / references /
 *    tests module Y — static structural truth about the source;
 *  - the *reality* graph (AURG): the same {from_node_id, to_node_id, edge_type}
 *    shape carrying docs / memory / evidence / missions relations — what governs,
 *    proves and decides things in Atlas.
 *
 * Each on its own answers half a question. Stitched together they answer the one no
 * external code-intelligence tool can form, because no external tool *has* the
 * reality side: "from a code change, through reality, to the decisions and evidence
 * that govern it." That traversal — code edge -> the node it touches -> the AURG
 * doc/decision/evidence edges on that node — is exactly what this view makes
 * possible by putting both layers in a single, uniformly-tagged edge list.
 *
 * The transform itself is deliberately small and total: tag every edge with
 * `metadata.layer` ('code' | 'reality') so a consumer can tell which graph a hop
 * came from, and overlay `metadata.runtime_proven` (true when the edge's
 * `source_ref` is in the proven set) so runtime-truth rides on top of *both*
 * layers, not just the code one. The proven-matching semantics are identical to
 * {@see CodeGraphRuntimeEvidenceOverlay} (exact match, or the file part before a
 * trailing `:line`, or a keyed-set entry) so "proven" means the same thing
 * everywhere in the code-graph stack.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime. It is a read-model
 * that FEEDS traversal/query; it decides nothing about providers, models, domains
 * or policy. Storage and runtime wiring (behind config `atlas.code_graph.real_edges`,
 * default OFF) live in adapters, never here.
 *
 * @phpstan-type GraphEdge array{from_node_id?:string, to_node_id?:string, edge_type?:string, confidence?:string, confidence_score?:float|int, metadata?:array<string,mixed>}
 */
class CodeGraphUnifiedView
{
    public const SCHEMA = 'atlas.code_graph.unified_view.v1';

    public const LAYER_CODE = 'code';
    public const LAYER_REALITY = 'reality';

    /**
     * Merge the code graph and the AURG reality graph into one edge set, tagging the
     * originating layer on each edge and overlaying runtime-proven from the proven set.
     *
     * Edges are emitted code-layer first, then reality-layer, each block kept in a
     * deterministic order keyed by (from, to, edge_type) so the same inputs always
     * produce byte-identical output regardless of incoming order. No deduplication
     * across layers: a code `depends_on` and a reality `depends_on` between the same
     * two nodes are distinct facts (structure vs governance) and both are preserved,
     * distinguished by `metadata.layer`.
     *
     * @param  array<int,array<string,mixed>>  $codeEdges  resolved code-graph edges
     *   ({@see CodeGraphEdgeResolver}); each `{from_node_id, to_node_id, edge_type, ...}`.
     * @param  array<int,array<string,mixed>>  $aurgEdges  AURG reality edges — same
     *   `{from_node_id, to_node_id, edge_type}` shape, carrying docs/memory/evidence/mission relations.
     * @param  iterable<int|string,mixed>  $provenSourceRefs  paths/symbols (`path` or
     *   `path:line`) actually exercised at runtime. Accepts a list (`['a.php', 'b.php:12']`)
     *   or a set keyed by ref (`['a.php' => true]`); both normalise to a lookup set.
     * @return array{schema_version:string, edges:array<int,array<string,mixed>>, stats:array{code:int, reality:int, proven:int, total:int}}
     */
    public function unify(array $codeEdges, array $aurgEdges, iterable $provenSourceRefs = []): array
    {
        $provenSet = $this->normalizeProvenSet($provenSourceRefs);

        $codeLayer = $this->tagLayer($codeEdges, self::LAYER_CODE, $provenSet);
        $realityLayer = $this->tagLayer($aurgEdges, self::LAYER_REALITY, $provenSet);

        $edges = array_merge($codeLayer['edges'], $realityLayer['edges']);

        return [
            'schema_version' => self::SCHEMA,
            'edges' => $edges,
            'stats' => [
                'code' => count($codeLayer['edges']),
                'reality' => count($realityLayer['edges']),
                'proven' => $codeLayer['proven'] + $realityLayer['proven'],
                'total' => count($edges),
            ],
        ];
    }

    /**
     * Tag a single graph's edges with their layer and the runtime-proven overlay,
     * then sort deterministically by (from, to, edge_type).
     *
     * @param  array<int,array<string,mixed>>  $edges
     * @param  array<string,true>  $provenSet
     * @return array{edges:array<int,array<string,mixed>>, proven:int}
     */
    private function tagLayer(array $edges, string $layer, array $provenSet): array
    {
        $tagged = [];
        $proven = 0;

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $metadata = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
            $sourceRef = $this->stringOrNull($metadata['source_ref'] ?? null);
            $isProven = $sourceRef !== null && $this->sourceRefIsProven($sourceRef, $provenSet);

            $metadata['layer'] = $layer;
            $metadata['runtime_proven'] = $isProven;
            $metadata['unified_view'] = self::SCHEMA;

            if ($isProven) {
                $proven++;
            }

            $edge['metadata'] = $metadata;
            $tagged[] = $edge;
        }

        usort(
            $tagged,
            static fn (array $a, array $b): int => [
                self::nodeKey($a['from_node_id'] ?? null),
                self::nodeKey($a['to_node_id'] ?? null),
                self::nodeKey($a['edge_type'] ?? null),
            ] <=> [
                self::nodeKey($b['from_node_id'] ?? null),
                self::nodeKey($b['to_node_id'] ?? null),
                self::nodeKey($b['edge_type'] ?? null),
            ],
        );

        return ['edges' => $tagged, 'proven' => $proven];
    }

    /**
     * A source_ref counts as proven when it is in the set directly, or when its file
     * part (before a trailing `:line`) is — runtime evidence is frequently
     * file-grained while a resolved edge pins an exact `path:line`. A `:` inside a
     * symbol/FQCN is never treated as a line separator. Identical semantics to
     * {@see CodeGraphRuntimeEvidenceOverlay::sourceRefIsProven()}.
     *
     * @param  array<string,true>  $provenSet
     */
    private function sourceRefIsProven(string $sourceRef, array $provenSet): bool
    {
        if (isset($provenSet[$sourceRef])) {
            return true;
        }

        $colon = strrpos($sourceRef, ':');
        if ($colon !== false) {
            $file = substr($sourceRef, 0, $colon);
            $line = substr($sourceRef, $colon + 1);
            if ($file !== '' && ctype_digit($line) && isset($provenSet[$file])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<int|string,mixed>  $provenSourceRefs
     * @return array<string,true>
     */
    private function normalizeProvenSet(iterable $provenSourceRefs): array
    {
        $set = [];
        foreach ($provenSourceRefs as $key => $value) {
            // Set form: keyed by ref with a truthy value (['a.php' => true]).
            if (is_string($key)) {
                $ref = $this->stringOrNull($key);
                if ($ref !== null && $value) {
                    $set[$ref] = true;
                }

                continue;
            }
            // List form: the value is the ref (['a.php', 'b.php:12']).
            $ref = $this->stringOrNull($value);
            if ($ref !== null) {
                $set[$ref] = true;
            }
        }

        return $set;
    }

    private static function nodeKey(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
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
