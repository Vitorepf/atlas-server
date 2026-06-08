<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * M-8 Cross-Domain Graph entity ingestion (AP-814, Fase-3) — reads the REAL
 * per-domain rows from the common domain-scoped runtime tables and emits them as
 * entity nodes + intra-domain `belongs_to` edges in the SAME canonical node/edge
 * shape {@see CrossDomainGraphIngestionService} already produces.
 *
 * Fase-2 gave the graph ONE node per domain (the 21 canonical domains) plus the
 * cross-domain handoff/allowed edges between them. This service adds the missing
 * entity-level detail: each runtime record / evidence pack / claim becomes its own
 * node hanging off its domain node, so the cross-domain graph stops being a 21-node
 * skeleton and carries the actual work that happened inside each domain.
 *
 * Domain identity is reconciled through {@see CrossDomainTaxonomyMap} exactly like
 * the sibling service: every `domain_id` (mesh OR registry alias) resolves to ONE
 * canonical id; rows whose domain is unknown are skipped (never invent a domain),
 * and privacy_class is taken from the taxonomy's ARPTL-conservative sensitivity.
 *
 * Pure read-model: only SELECTs, capped at $maxEntities, FAIL-OPEN (a missing
 * table/driver yields an empty contribution, never a fatal), deterministic order,
 * deduped by node_id. It decides nothing and crosses nothing to a provider — it
 * assembles + privacy-tags.
 */
final class CrossDomainEntityIngestionService
{
    public const SCHEMA = 'atlas.code_graph.cross_domain_entities.v1';

    public const EDGE_BELONGS_TO = 'belongs_to';

    public const DEFAULT_MAX_ENTITIES = 5000;

    public const TYPE_RUNTIME_RECORD = 'runtime_record';

    public const TYPE_EVIDENCE = 'evidence';

    public const TYPE_CLAIM = 'claim';

    /**
     * Each source: [table, entity_type, id_column, domain_column]. The tables are the
     * common domain-scoped runtime tables; each is Schema::hasTable-guarded so any
     * absent table simply contributes nothing.
     *
     * @var list<array{table:string, type:string, id:string, domain:string}>
     */
    private const SOURCES = [
        ['table' => 'ai_domain_runtime_records', 'type' => self::TYPE_RUNTIME_RECORD, 'id' => 'id', 'domain' => 'domain_id'],
        ['table' => 'ai_evidence_packs', 'type' => self::TYPE_EVIDENCE, 'id' => 'id', 'domain' => 'domain_id'],
        ['table' => 'ai_claims', 'type' => self::TYPE_CLAIM, 'id' => 'id', 'domain' => 'domain_id'],
    ];

    public function __construct(
        private readonly CrossDomainTaxonomyMap $taxonomy,
    ) {}

    /**
     * @return array{schema_version:string, nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>, stats:array{entity_count:int, by_type:array<string,int>}}
     */
    public function gather(int $maxEntities = self::DEFAULT_MAX_ENTITIES): array
    {
        $cap = max(0, $maxEntities);

        /** @var array<string, array<string,mixed>> $nodesById dedup by node_id */
        $nodesById = [];
        /** @var array<string, array<string,mixed>> $edgesByKey */
        $edgesByKey = [];
        $byType = [];

        foreach (self::SOURCES as $source) {
            if (count($nodesById) >= $cap) {
                break;
            }
            $remaining = $cap - count($nodesById);
            foreach ($this->rows($source['table'], $source['id'], $source['domain'], $remaining) as $row) {
                if (count($nodesById) >= $cap) {
                    break;
                }
                $rawDomain = (string) ($row->__domain ?? '');
                $canonical = $this->taxonomy->canonical($rawDomain);
                if ($canonical === null) {
                    continue; // unknown domain → skip, never invent a domain
                }
                $rowId = (string) ($row->__id ?? '');
                if ($rowId === '') {
                    continue;
                }

                $type = $source['type'];
                $nodeId = 'domain:'.$canonical.':'.$type.':'.$rowId;
                if (isset($nodesById[$nodeId])) {
                    continue; // dedup by node_id
                }

                $privacyClass = $this->taxonomy->isSensitive($canonical) ? 'sensitive' : 'normal';
                $nodesById[$nodeId] = [
                    'node_id' => $nodeId,
                    'node_type' => $type,
                    'label' => $type.':'.$rowId,
                    'metadata' => [
                        'domain' => $canonical,
                        'privacy_class' => $privacyClass,
                        'entity_type' => $type,
                        'entity_id' => $rowId,
                        'source_table' => $source['table'],
                    ],
                ];
                $byType[$type] = ($byType[$type] ?? 0) + 1;

                $edgeKey = $nodeId.'|domain:'.$canonical.'|'.self::EDGE_BELONGS_TO;
                if (! isset($edgesByKey[$edgeKey])) {
                    $edgesByKey[$edgeKey] = [
                        'from_node_id' => $nodeId,
                        'to_node_id' => 'domain:'.$canonical,
                        'edge_type' => self::EDGE_BELONGS_TO,
                        'confidence' => 'EXTRACTED',
                        'confidence_score' => 1.0,
                        'metadata' => [
                            'cross_domain' => false, // entity belongs to its OWN domain (intra-domain)
                            'occurrences' => 1,
                            'entity_type' => $type,
                        ],
                    ];
                }
            }
        }

        $nodes = $this->sortByNodeId(array_values($nodesById));
        $edges = $this->sortEdges(array_values($edgesByKey));
        ksort($byType);

        return [
            'schema_version' => self::SCHEMA,
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => [
                'entity_count' => count($nodes),
                'by_type' => $byType,
            ],
        ];
    }

    /**
     * Read up to $limit rows from $table, aliasing the id + domain columns to stable
     * names so the gather loop is column-name agnostic. FAIL-OPEN on every failure.
     *
     * @return iterable<int, object>
     */
    private function rows(string $table, string $idColumn, string $domainColumn, int $limit): iterable
    {
        if ($limit <= 0 || ! $this->tableExists($table)) {
            return [];
        }
        try {
            return DB::table($table)
                ->select([$idColumn.' as __id', $domainColumn.' as __domain'])
                ->orderBy($idColumn)
                ->limit($limit)
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     * @return list<array<string,mixed>>
     */
    private function sortByNodeId(array $nodes): array
    {
        usort(
            $nodes,
            static fn (array $a, array $b): int => ((string) $a['node_id']) <=> ((string) $b['node_id']),
        );

        return $nodes;
    }

    /**
     * @param  list<array<string,mixed>>  $edges
     * @return list<array<string,mixed>>
     */
    private function sortEdges(array $edges): array
    {
        usort(
            $edges,
            static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']]
                <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']],
        );

        return $edges;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
