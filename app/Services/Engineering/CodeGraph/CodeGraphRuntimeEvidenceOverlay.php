<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Overlays runtime-execution evidence onto resolved code-graph edges (AP-812 M-2).
 *
 * Resolved edges ({@see CodeGraphEdgeResolver}) prove a *static* relation: file X
 * imports / references / tests module Y. This overlay answers a question a purely
 * static graph (and graphify, which has no runtime) cannot: was that edge actually
 * exercised by code that *runs*? For each edge whose `metadata.source_ref`
 * (a `path` or `path:line`, exactly as the resolver emits) is present in the
 * proven set, we stamp `metadata.runtime_proven = true` and bump `metadata.weight`;
 * everything else is explicitly marked `runtime_proven = false` with the base
 * weight. This is the "edge proved by what actually runs" signal — the runtime-truth
 * layer over static truth.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime — deliberately so it
 * stays trivially testable and side-effect free. It does NOT call
 * {@see \App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService}. The
 * gated wiring (behind config `atlas.code_graph.real_edges`, default OFF) is what
 * builds the proven set: it reads the usage-intelligence reachability/usage-map
 * evidence (entrypoints, tests, references — paths/symbols actually exercised at
 * runtime) and passes that path/symbol set in here as `$provenSourceRefs`. This
 * service decides nothing about providers/models/domains/policy; it only enriches
 * a read-model.
 *
 * @phpstan-type OverlayEdge array{from_node_id?:string, to_node_id?:string, edge_type?:string, confidence_score?:float|int, metadata?:array<string,mixed>}
 */
class CodeGraphRuntimeEvidenceOverlay
{
    public const SCHEMA = 'atlas.code_graph.runtime_evidence_overlay.v1';

    /**
     * Multiplicative bump applied to the base weight when an edge is proven by
     * runtime. Kept >1 so a runtime-proven edge always outranks an identical
     * static-only edge, without inventing confidence the resolver never assigned.
     */
    private const PROVEN_WEIGHT_MULTIPLIER = 1.5;

    /**
     * Base weight for an edge that carries no prior weight. Seeded from
     * `confidence_score` when present (so a stronger static edge starts higher),
     * otherwise this neutral default.
     */
    private const DEFAULT_BASE_WEIGHT = 1.0;

    /**
     * Overlay runtime evidence onto resolved edges.
     *
     * @param  array<int,array<string,mixed>>  $edges  resolved edges from {@see CodeGraphEdgeResolver}
     * @param  iterable<int|string,mixed>  $provenSourceRefs  paths/symbols (`path` or `path:line`)
     *   actually exercised at runtime. Accepts a list (`['a.php', 'b.php:12']`) or a
     *   set keyed by ref (`['a.php' => true]`); both are normalised to a lookup set.
     * @return array{schema_version:string, edges:array<int,array<string,mixed>>, stats:array{proven:int, total:int}}
     */
    public function overlay(array $edges, iterable $provenSourceRefs): array
    {
        $provenSet = $this->normalizeProvenSet($provenSourceRefs);

        $result = [];
        $proven = 0;

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $metadata = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
            $sourceRef = $this->stringOrNull($metadata['source_ref'] ?? null);

            $isProven = $sourceRef !== null && $this->sourceRefIsProven($sourceRef, $provenSet);
            $baseWeight = $this->baseWeight($metadata, $edge);

            $metadata['runtime_proven'] = $isProven;
            $metadata['weight'] = $isProven
                ? $this->round($baseWeight * self::PROVEN_WEIGHT_MULTIPLIER)
                : $this->round($baseWeight);
            $metadata['runtime_overlay'] = self::SCHEMA;

            if ($isProven) {
                $proven++;
            }

            $edge['metadata'] = $metadata;
            $result[] = $edge;
        }

        return [
            'schema_version' => self::SCHEMA,
            'edges' => $result,
            'stats' => [
                'proven' => $proven,
                'total' => count($result),
            ],
        ];
    }

    /**
     * A source_ref counts as proven when it is in the set directly, or when its
     * file part (before `:line`) is — runtime evidence is frequently file-grained
     * while a resolved edge pins an exact `path:line`. Symbol-keyed proven entries
     * match verbatim.
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
            // Only strip a trailing :line (digits), never a `:` inside a symbol/FQCN.
            if ($file !== '' && ctype_digit($line) && isset($provenSet[$file])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $edge
     */
    private function baseWeight(array $metadata, array $edge): float
    {
        // Respect an existing weight if a prior overlay/stage already set one.
        $existing = $this->floatOrNull($metadata['weight'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        // Otherwise seed from the resolver's confidence_score so stronger static
        // edges start heavier; fall back to a neutral base.
        $confidence = $this->floatOrNull($edge['confidence_score'] ?? ($metadata['confidence_score'] ?? null));
        if ($confidence !== null && $confidence > 0) {
            return $confidence;
        }

        return self::DEFAULT_BASE_WEIGHT;
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

    private function round(float $value): float
    {
        return round($value, 4);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }
}
