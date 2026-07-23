<?php

namespace App\Services\Engineering\CodeGraph;


/**
 * Cross-domain adapter (AP-812 M-8): proves the code-graph machinery is
 * domain-agnostic by mapping ANY domain's relations into the same canonical
 * edge shape that {@see CodeGraphEdgeResolver} emits and that
 * {@see CodeGraphAnalytics} (god-nodes / blast-radius) consumes.
 *
 * The graph operations don't know or care that a node is a code module — they
 * only see opaque {from_node_id, to_node_id, edge_type} tuples. So a Finance
 * relation ("AAPL hedges SPY"), a marketing funnel, or a supply-chain link maps
 * onto the identical primitive and the EXISTING analytics run unchanged. This is
 * a read-model/transform that FEEDS the graph; it decides nothing.
 *
 * Pure transform: no DB, no IO, no provider, no python runtime. Node ids are
 * namespaced by domain ("<domain>:<id>") so heterogeneous domains can never
 * collide if their edges share one graph. Edges are confidence-graded INFERRED
 * (a domain relation is asserted, not extracted from source like a real import).
 *
 * @phpstan-type DomainRelation array{from:string, to:string, type?:string}
 * @phpstan-type CanonicalEdge array{from_node_id:string, to_node_id:string, edge_type:string, confidence:string, confidence_score:float, metadata:array<string,mixed>}
 */
class DomainGraphAdapter
{
    public const SCHEMA = 'atlas.code_graph.domain_adapter.v1';

    /**
     * Matches the confidence vocabulary of {@see CodeGraphEdgeResolver}. Domain
     * relations are INFERRED: asserted by the domain, not extracted from source.
     */
    public const CONFIDENCE_INFERRED = 'INFERRED';

    private const SCORE_INFERRED = 0.75;

    /** Fallback edge type when a relation omits one. */
    private const EDGE_RELATES_TO = 'relates_to';

    /**
     * Map a domain's relations into canonical code-graph edges.
     *
     * Each relation {from,to,type?} becomes one edge with node ids namespaced as
     * "<domain>:<from>" / "<domain>:<to>". Relations missing from/to, or pointing
     * a node at itself, are dropped (never invented). Identical (from|to|type)
     * edges are deduped with an occurrence count, mirroring the resolver. Output
     * is deterministically sorted by [from, to, type] so callers and analytics
     * see a stable graph.
     *
     * @param  array<int,array<string,mixed>>  $relations  domain relations: each = {from, to, type?}
     * @return array<int,array<string,mixed>> canonical edges, exactly the shape
     *   {@see CodeGraphAnalytics::godNodes()} / {@see CodeGraphAnalytics::blastRadius()} consume.
     */
    public function toEdges(string $domainId, array $relations): array
    {
        $domain = $this->normalizeDomain($domainId);

        /** @var array<string,array<string,mixed>> $edges keyed by from|to|type */
        $edges = [];

        foreach ($relations as $relation) {
            if (! is_array($relation)) {
                continue;
            }

            $from = $this->stringOrNull($relation['from'] ?? null);
            $to = $this->stringOrNull($relation['to'] ?? null);
            if ($from === null || $to === null) {
                continue;
            }

            $edgeType = $this->edgeType($relation['type'] ?? null);

            $fromNode = $domain.':'.$from;
            $toNode = $domain.':'.$to;
            if ($fromNode === $toNode) {
                // Self-loop carries no dependency signal; skip (matches resolver).
                continue;
            }

            $key = $fromNode.'|'.$toNode.'|'.$edgeType;
            if (isset($edges[$key])) {
                $edges[$key]['metadata']['occurrences']++;

                continue;
            }

            $edges[$key] = [
                'from_node_id' => $fromNode,
                'to_node_id' => $toNode,
                'edge_type' => $edgeType,
                'confidence' => self::CONFIDENCE_INFERRED,
                'confidence_score' => self::SCORE_INFERRED,
                'metadata' => [
                    'adapter' => self::SCHEMA,
                    'domain' => $domain,
                    'relation' => $edgeType,
                    'confidence' => self::CONFIDENCE_INFERRED,
                    'confidence_score' => self::SCORE_INFERRED,
                    'inferred' => true,
                    'occurrences' => 1,
                ],
            ];
        }

        $edgeList = array_values($edges);
        usort(
            $edgeList,
            static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']]
                <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']],
        );

        return $edgeList;
    }

    private function normalizeDomain(string $domainId): string
    {
        $trimmed = trim($domainId);

        return $trimmed === '' ? 'domain' : $trimmed;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function edgeType(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : self::EDGE_RELATES_TO;
    }
}
