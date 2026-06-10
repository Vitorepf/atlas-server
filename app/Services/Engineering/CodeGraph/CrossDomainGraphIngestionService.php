<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * M-8 Cross-Domain Graph ingestion (AP-814) — assembles the real cross-domain
 * entity graph in the canonical node/edge shape the existing domain-agnostic
 * {@see CodeGraphAnalytics} / world-model traversal already consume.
 *
 * After the operator's "superset único" decision (AP-814 §8.1), domain identity is
 * reconciled through {@see CrossDomainTaxonomyMap}: nodes ARE the 21 canonical
 * domains, and BOTH edge sources resolve their ids through the map — which is what
 * finally lets the mesh's allowed-crossing edges merge with handoff edges (the id
 * mismatch that blocked this is gone).
 *
 * Two real edge sources:
 *   - `ai_domain_handoffs` → `hands_off_to` (a handoff that HAS happened; EXTRACTED).
 *   - the EXISTING {@see AtlasCrossDomainMeshService} topology → `allows_crossing`
 *     (a governed rule that a crossing IS permitted; EXTRACTED). Reused, not recreated.
 *
 * Pure read-model: only SELECTs + the mesh topology read, capped, FAIL-OPEN. It
 * decides nothing and crosses nothing to a provider — it assembles + privacy-tags.
 */
final class CrossDomainGraphIngestionService
{
    public const SCHEMA = 'atlas.code_graph.cross_domain.v1';

    public const NODE_DOMAIN = 'domain';

    public const EDGE_HANDS_OFF = 'hands_off_to';

    public const EDGE_ALLOWS_CROSSING = 'allows_crossing';

    public const DEFAULT_MAX_DOMAINS = 100;

    public const DEFAULT_MAX_EDGES = 5000;

    public function __construct(
        private readonly CrossDomainTaxonomyMap $taxonomy,
        private readonly ?AtlasCrossDomainMeshService $mesh = null,
    ) {}

    /**
     * @return array{schema_version:string, nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>, stats:array<string,int>}
     */
    public function gather(int $maxDomains = self::DEFAULT_MAX_DOMAINS, int $maxEdges = self::DEFAULT_MAX_EDGES): array
    {
        $nodes = $this->domainNodes($maxDomains);
        $entity = $this->entityGraph();
        $nodes = array_merge($nodes, $entity['nodes']);
        $edges = $this->dedup(array_merge(
            $this->handoffEdges($maxEdges),
            $this->meshAllowedEdges(),
            $entity['edges'],
        ), $maxEdges);

        $byType = [];
        $crossDomain = 0;
        foreach ($edges as $edge) {
            $type = (string) ($edge['edge_type'] ?? '');
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            if (($edge['metadata']['cross_domain'] ?? false) === true) {
                $crossDomain++;
            }
        }
        $domainCount = 0;
        $entityCount = 0;
        $sensitive = 0;
        foreach ($nodes as $node) {
            if (($node['node_type'] ?? '') === self::NODE_DOMAIN) {
                $domainCount++;
                if (($node['metadata']['privacy_class'] ?? 'normal') !== 'normal') {
                    $sensitive++;
                }
            } else {
                $entityCount++;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => [
                'domain_count' => $domainCount,
                'entity_count' => $entityCount,
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'handoff_edge_count' => $byType[self::EDGE_HANDS_OFF] ?? 0,
                'allowed_edge_count' => $byType[self::EDGE_ALLOWS_CROSSING] ?? 0,
                'cross_domain_edge_count' => $crossDomain,
                'sensitive_domain_count' => $sensitive,
            ],
        ];
    }

    /**
     * Fase-3 entity merge — pulls per-domain real entities from the (separately built,
     * workflow-authored) CrossDomainEntityIngestionService. Wired defensively: inert
     * (empty) until that class exists AND config enables entities, and FAIL-OPEN, so
     * the domain-level graph is byte-identical until entities are genuinely available.
     *
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    private function entityGraph(): array
    {
        $empty = ['nodes' => [], 'edges' => []];
        try {
            $enabled = function_exists('config') ? (bool) config('atlas.cross_domain_graph.entities', true) : true;
            if (! $enabled) {
                return $empty;
            }
            $class = 'App\\Services\\Engineering\\CodeGraph\\CrossDomainEntityIngestionService';
            if (! class_exists($class) || ! function_exists('app')) {
                return $empty;
            }
            $max = (int) config('atlas.cross_domain_graph.max_entities', 5000);
            $graph = app($class)->gather($max);

            return [
                'nodes' => is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [],
                'edges' => is_array($graph['edges'] ?? null) ? $graph['edges'] : [],
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * Nodes ARE the canonical superset (the single source of truth). `ai_domain_profiles`
     * only enriches runtime status when present.
     *
     * @return list<array<string,mixed>>
     */
    private function domainNodes(int $max): array
    {
        $status = $this->profileStatuses();
        $nodes = [];
        foreach ($this->taxonomy->all() as $canonical => $meta) {
            if (count($nodes) >= $max) {
                break;
            }
            $nodes[] = [
                'node_id' => 'domain:'.$canonical,
                'node_type' => self::NODE_DOMAIN,
                'label' => $meta['label'],
                'metadata' => [
                    'domain' => $canonical,
                    'privacy_class' => $meta['sensitive'] ? 'sensitive' : 'normal',
                    'mesh_id' => $meta['mesh'],
                    'registry_id' => $meta['registry'],
                    'status' => $status[$canonical] ?? 'unknown',
                ],
            ];
        }

        return $nodes;
    }

    /**
     * @return array<string,string> canonical_id => status (from ai_domain_profiles, best-effort)
     */
    private function profileStatuses(): array
    {
        if (! $this->tableExists('ai_domain_profiles')) {
            return [];
        }
        try {
            $rows = DB::table('ai_domain_profiles')->select('id', 'status')->limit(200)->get();
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $canonical = $this->taxonomy->canonical((string) ($row->id ?? ''));
            if ($canonical !== null) {
                $out[$canonical] = (string) ($row->status ?? 'unknown');
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function handoffEdges(int $max): array
    {
        if ($max <= 0 || ! $this->tableExists('ai_domain_handoffs')) {
            return [];
        }
        try {
            $rows = DB::table('ai_domain_handoffs')
                ->select('source_domain_id', 'target_domain_id')
                ->limit($max)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $edges = [];
        foreach ($rows as $row) {
            $src = $this->taxonomy->canonical((string) ($row->source_domain_id ?? ''));
            $dst = $this->taxonomy->canonical((string) ($row->target_domain_id ?? ''));
            if ($src === null || $dst === null || $src === $dst) {
                continue;
            }
            $edges[] = $this->edge('domain:'.$src, 'domain:'.$dst, self::EDGE_HANDS_OFF, []);
        }

        return $edges;
    }

    /**
     * Real allowed-crossing edges from the EXISTING mesh topology, resolved through
     * the canonical map. Reuse, not recreate.
     *
     * @return list<array<string,mixed>>
     */
    private function meshAllowedEdges(): array
    {
        if ($this->mesh === null) {
            return [];
        }
        try {
            $topology = $this->mesh->topology();
        } catch (Throwable) {
            return [];
        }
        $allowed = is_array($topology['edges_allowed'] ?? null) ? $topology['edges_allowed'] : [];

        $edges = [];
        foreach ($allowed as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $from = $this->taxonomy->canonical((string) ($rule['from'] ?? ''));
            $to = $this->taxonomy->canonical((string) ($rule['to'] ?? ''));
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            $classes = is_array($rule['privacy_classes_allowed'] ?? null) ? array_values($rule['privacy_classes_allowed']) : [];
            $edges[] = $this->edge('domain:'.$from, 'domain:'.$to, self::EDGE_ALLOWS_CROSSING, [
                'privacy_classes_allowed' => $classes,
            ]);
        }

        return $edges;
    }

    /**
     * @param  array<string,mixed>  $extraMeta
     * @return array<string,mixed>
     */
    private function edge(string $from, string $to, string $type, array $extraMeta): array
    {
        return [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'confidence' => 'EXTRACTED',
            'confidence_score' => 1.0,
            'metadata' => array_merge(['cross_domain' => true, 'occurrences' => 1], $extraMeta),
        ];
    }

    /**
     * Dedup by from|to|type with occurrence count; deterministic order.
     *
     * @param  list<array<string,mixed>>  $edges
     * @return list<array<string,mixed>>
     */
    private function dedup(array $edges, int $max): array
    {
        $byKey = [];
        foreach ($edges as $edge) {
            $key = $edge['from_node_id'].'|'.$edge['to_node_id'].'|'.$edge['edge_type'];
            if (isset($byKey[$key])) {
                $byKey[$key]['metadata']['occurrences']++;

                continue;
            }
            $byKey[$key] = $edge;
        }

        $list = array_values($byKey);
        usort(
            $list,
            static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']]
                <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']],
        );

        return array_slice($list, 0, max(0, $max));
    }

    private function tableExists(string $table): bool
    {
        return DatabaseTableAvailability::has($table);
    }
}
