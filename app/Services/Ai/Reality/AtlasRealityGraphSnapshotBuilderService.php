<?php

namespace App\Services\Ai\Reality;

/**
 * Atlas Cognition Operating System — AURG (Unified Reality Graph) Phase 1.
 *
 * Schemas canon:
 *   - atlas.aurg.reality_node.v1
 *   - atlas.aurg.reality_edge.v1
 *   - atlas.aurg.reality_snapshot.v1
 *
 * Doc canon: atlas-cognition-operating-system.md (AUCRI bloco #8).
 *
 * RESPONSABILIDADE:
 *  - Manter snapshot deterministico read-only de entidades canon da "realidade Atlas"
 *    (workspaces, projetos, missions, work_orders, obras, evidence_refs).
 *  - Servir traversal bounded para AGRN.
 *  - Cada node/edge tem source canonico + freshness + confidence + provider_safe flag.
 *  - Snapshot serializado e hashado deterministicamente (snapshot_hash sha256).
 *
 * Phase 1 (esta versao):
 *  - Builder in-memory para snapshot.
 *  - Aceita lista de nodes + edges, valida invariants, emite snapshot canon.
 *  - Sem persistencia DB (Phase 2 grava em atlas_aurg_snapshots).
 *  - Sem ingestao automatica de fontes ASRE (Phase 3).
 *
 * Phase 2 (fused store — F1 ENTREGUE):
 *  - Persistencia: atlas_aurg_nodes + atlas_aurg_edges (migration
 *    2026_06_09_120000_create_atlas_aurg_graph_tables) — atlas_aurg_snapshots
 *    continua pendente.
 *  - Ingestao federada das 5 fontes reais (memory/code/domains/evidence/strategic)
 *    via {@see AtlasRealityGraphIngestionService} (comando atlas:aurg:ingest).
 *  - Diff snapshots para detectar drift (pendente).
 *
 * Phase 3:
 *  - Hookup com AGRN global active.
 *  - Time-aware overlay (TEOS-I2 temporal_truth).
 */
class AtlasRealityGraphSnapshotBuilderService
{
    public const NODE_WORKSPACE = 'workspace';

    public const NODE_PROJECT = 'project';

    public const NODE_MISSION = 'mission';

    public const NODE_WORK_ORDER = 'work_order';

    public const NODE_OBRA = 'obra';

    public const NODE_EVIDENCE = 'evidence';

    public const NODE_DOC = 'doc';

    public const NODE_MEMORY_ENTRY = 'memory_entry';

    public const NODE_SOURCE_PACKET = 'source_packet';

    /**
     * Phase-2 fused-store ADDITIVE kinds (Salto 1 / F1). The Phase-2 ingestion
     * federates 5 read-models; three node families had no honest kind in the
     * original 9: code-intelligence modules (bounded projection — modules only,
     * never symbols), the 21 canonical cross-domain taxonomy domains, and ASRE
     * reality entities whose free-form entity_type matches no canon kind
     * (mapped to reality_entity with the original type preserved in meta).
     * Additive only — the original kinds and validation are unchanged.
     */
    public const NODE_MODULE = 'module';

    public const NODE_DOMAIN = 'domain';

    public const NODE_REALITY_ENTITY = 'reality_entity';

    public const ALLOWED_NODE_KINDS = [
        self::NODE_WORKSPACE,
        self::NODE_PROJECT,
        self::NODE_MISSION,
        self::NODE_WORK_ORDER,
        self::NODE_OBRA,
        self::NODE_EVIDENCE,
        self::NODE_DOC,
        self::NODE_MEMORY_ENTRY,
        self::NODE_SOURCE_PACKET,
        self::NODE_MODULE,
        self::NODE_DOMAIN,
        self::NODE_REALITY_ENTITY,
    ];

    public const EDGE_BELONGS_TO = 'belongs_to';

    public const EDGE_DEPENDS_ON = 'depends_on';

    public const EDGE_GENERATED = 'generated';

    public const EDGE_REFERENCES = 'references';

    public const EDGE_SUPERSEDES = 'supersedes';

    public const EDGE_PROVES = 'proves';

    public const ALLOWED_EDGE_KINDS = [
        self::EDGE_BELONGS_TO,
        self::EDGE_DEPENDS_ON,
        self::EDGE_GENERATED,
        self::EDGE_REFERENCES,
        self::EDGE_SUPERSEDES,
        self::EDGE_PROVES,
    ];

    /**
     * Constroi snapshot canonico read-only.
     *
     * @param  array<int,array<string,mixed>>  $nodes  Cada node: id, kind, label, source, observed_at, confidence, provider_safe (bool).
     * @param  array<int,array<string,mixed>>  $edges  Cada edge: from_id, to_id, kind, weight, source, observed_at.
     * @return array{
     *   schema_version:string,
     *   snapshot_id:string,
     *   snapshot_hash:string,
     *   nodes:array<int,array<string,mixed>>,
     *   edges:array<int,array<string,mixed>>,
     *   stats:array{node_count:int,edge_count:int,kinds:array<string,int>,edge_kinds:array<string,int>,provider_safe_nodes:int,blocked_nodes:int},
     *   created_at:string
     * }
     */
    public function buildSnapshot(array $nodes, array $edges, ?string $snapshotId = null): array
    {
        $cleanedNodes = $this->cleanNodes($nodes);
        $nodeIds = array_column($cleanedNodes, 'id');
        $cleanedEdges = $this->cleanEdges($edges, $nodeIds);

        // Sort canonical para deterministic hash.
        usort($cleanedNodes, fn ($a, $b) => strcmp($a['id'], $b['id']));
        usort($cleanedEdges, function ($a, $b): int {
            $c = strcmp($a['from_id'], $b['from_id']);
            if ($c !== 0) {
                return $c;
            }

            return strcmp($a['to_id'], $b['to_id']);
        });

        $snapshotId = $snapshotId ?: 'aurg_'.substr(hash('sha256', json_encode(['nodes' => $cleanedNodes, 'edges' => $cleanedEdges], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''), 0, 24);

        $snapshotHash = hash('sha256', json_encode([
            'schema_version' => 'atlas.aurg.reality_snapshot.v1',
            'nodes' => $cleanedNodes,
            'edges' => $cleanedEdges,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $kinds = [];
        $edgeKinds = [];
        $providerSafeNodes = 0;
        $blockedNodes = 0;

        foreach ($cleanedNodes as $n) {
            $kinds[$n['kind']] = ($kinds[$n['kind']] ?? 0) + 1;
            if ($n['provider_safe']) {
                $providerSafeNodes++;
            } else {
                $blockedNodes++;
            }
        }
        foreach ($cleanedEdges as $e) {
            $edgeKinds[$e['kind']] = ($edgeKinds[$e['kind']] ?? 0) + 1;
        }

        return [
            'schema_version' => 'atlas.aurg.reality_snapshot.v1',
            'snapshot_id' => $snapshotId,
            'snapshot_hash' => $snapshotHash,
            'nodes' => $cleanedNodes,
            'edges' => $cleanedEdges,
            'stats' => [
                'node_count' => count($cleanedNodes),
                'edge_count' => count($cleanedEdges),
                'kinds' => $kinds,
                'edge_kinds' => $edgeKinds,
                'provider_safe_nodes' => $providerSafeNodes,
                'blocked_nodes' => $blockedNodes,
            ],
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Traverse bounded a partir de um node, retornando vizinhos por edge kind.
     *
     * @return array{schema_version:string,seed_id:string,depth:int,visited:array<int,string>,edges_taken:array<int,array<string,mixed>>,truncated:bool}
     */
    public function traverse(array $snapshot, string $seedId, int $maxDepth = 2, int $maxNodes = 50): array
    {
        $nodes = $snapshot['nodes'] ?? [];
        $edges = $snapshot['edges'] ?? [];

        $byFrom = [];
        foreach ($edges as $e) {
            $byFrom[$e['from_id']][] = $e;
        }

        $visited = [];
        $edgesTaken = [];
        $queue = [[$seedId, 0]];
        $truncated = false;

        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            if (in_array($current, $visited, true)) {
                continue;
            }
            $visited[] = $current;
            if (count($visited) >= $maxNodes) {
                $truncated = true;
                break;
            }
            if ($depth >= $maxDepth) {
                continue;
            }
            foreach ($byFrom[$current] ?? [] as $edge) {
                $edgesTaken[] = $edge;
                $queue[] = [$edge['to_id'], $depth + 1];
            }
        }

        return [
            'schema_version' => 'atlas.aurg.reality_snapshot.v1#traversal',
            'seed_id' => $seedId,
            'depth' => $maxDepth,
            'visited' => $visited,
            'edges_taken' => $edgesTaken,
            'truncated' => $truncated,
        ];
    }

    public static function isValidNodeKind(string $kind): bool
    {
        return in_array($kind, self::ALLOWED_NODE_KINDS, true);
    }

    public static function isValidEdgeKind(string $kind): bool
    {
        return in_array($kind, self::ALLOWED_EDGE_KINDS, true);
    }

    /**
     * @param  array<int,array<string,mixed>>  $nodes
     * @return array<int,array<string,mixed>>
     */
    private function cleanNodes(array $nodes): array
    {
        $cleaned = [];
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $id = trim((string) ($n['id'] ?? ''));
            $kind = (string) ($n['kind'] ?? '');
            if ($id === '' || ! self::isValidNodeKind($kind)) {
                continue;
            }

            $cleaned[] = [
                'schema_version' => 'atlas.aurg.reality_node.v1',
                'id' => $id,
                'kind' => $kind,
                'label' => mb_substr((string) ($n['label'] ?? ''), 0, 200),
                'source' => (string) ($n['source'] ?? 'unknown'),
                'observed_at' => (string) ($n['observed_at'] ?? now()->toIso8601String()),
                'confidence' => $this->normalizeConfidence($n['confidence'] ?? 1.0),
                'provider_safe' => (bool) ($n['provider_safe'] ?? true),
            ];
        }

        return $cleaned;
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     * @param  array<int,string>  $validNodeIds
     * @return array<int,array<string,mixed>>
     */
    private function cleanEdges(array $edges, array $validNodeIds): array
    {
        $validSet = array_flip($validNodeIds);
        $cleaned = [];

        foreach ($edges as $e) {
            if (! is_array($e)) {
                continue;
            }
            $from = trim((string) ($e['from_id'] ?? ''));
            $to = trim((string) ($e['to_id'] ?? ''));
            $kind = (string) ($e['kind'] ?? '');

            if ($from === '' || $to === '') {
                continue;
            }
            if (! self::isValidEdgeKind($kind)) {
                continue;
            }
            if (! isset($validSet[$from], $validSet[$to])) {
                continue;
            }
            if ($from === $to) {
                continue;
            }

            $cleaned[] = [
                'schema_version' => 'atlas.aurg.reality_edge.v1',
                'from_id' => $from,
                'to_id' => $to,
                'kind' => $kind,
                'weight' => $this->normalizeConfidence($e['weight'] ?? 1.0),
                'source' => (string) ($e['source'] ?? 'unknown'),
                'observed_at' => (string) ($e['observed_at'] ?? now()->toIso8601String()),
            ];
        }

        return $cleaned;
    }

    private function normalizeConfidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 1.0;
        }
        $f = (float) $value;
        if ($f < 0.0) {
            return 0.0;
        }
        if ($f > 1.0) {
            return 1.0;
        }

        return round($f, 3);
    }
}
