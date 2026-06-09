<?php

namespace App\Services\Ai\AutonomousEngineering\WorldModel;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AutonomousEngineering\AutonomousEngineeringHash;
use App\Services\Ai\RuntimeBoundary\GraphRankRuntimeClient;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ranks {@see AiCodebaseWorldModelNode} entries by combining textual match
 * with graph evidence (edge types, capability/risk overlap, file/flow
 * anchors). Output is deterministic JSON with reasons + relation paths so
 * any downstream consumer can audit *why* a node was ranked.
 *
 * Scope: read-only over the world model tables. Does NOT mutate state and
 * is safe to call from retrieval, planner or tests.
 *
 * RUNTIME LANGUAGE BOUNDARY: the numeric graph math (node centrality,
 * edge-weight propagation, incoming/outgoing scoring, the DESC-score /
 * ASC-node_id ranking order) is NOT hand-rolled here — per the
 * runtime_language_boundary canon it is computed by the real Python
 * networkx+numpy runtime (runtimes/python/graph_rank) via the signed
 * {@see GraphRankRuntimeClient} boundary. This class keeps the orchestration:
 * Eloquent IO / candidate windowing, hashing, the reason-string + relation-path
 * label composition, source collapsing and the deterministic payload envelope.
 * There is NO PHP scoring fallback — if the runtime is absent the boundary
 * throws honestly.
 */
class WorldModelGraphRanker
{
    public const SCHEMA = 'atlas.ai.codebase_world_model.ranking.v1';

    private const MAX_FULL_SCAN_NODES = 1200;

    private const MAX_FULL_SCAN_EDGES = 5000;

    private GraphRankRuntimeClient $graphRank;

    public function __construct(?GraphRankRuntimeClient $graphRank = null)
    {
        // Resolve from the container by default so `new WorldModelGraphRanker`
        // (used at a couple of call sites) keeps working without explicit wiring.
        $this->graphRank = $graphRank ?? app(GraphRankRuntimeClient::class);
    }

    /**
     * Run the ranking. If no world_model_id is supplied the most-recent
     * built world model is chosen.
     *
     * @return array<string,mixed>
     */
    public function rank(WorldModelRankingQuery $query): array
    {
        if (! $this->tablesReady()) {
            return $this->emptyResult($query, reason: 'world_model_tables_missing');
        }

        $model = $this->resolveModel($query);
        if ($model === null) {
            return $this->emptyResult($query, reason: 'world_model_not_found');
        }

        $totalNodeCount = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->count();
        $totalEdgeCount = AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->count();

        /** @var Collection<int,AiCodebaseWorldModelNode> $nodes */
        $nodes = $this->candidateNodes($model, $query, $totalNodeCount);

        /** @var Collection<int,AiCodebaseWorldModelEdge> $edges */
        $edges = $this->candidateEdges($model, $nodes->pluck('node_id')->map(static fn (mixed $nodeId): string => (string) $nodeId)->all(), $totalEdgeCount);
        $nodes = $this->withEdgeNeighborNodes($model, $nodes, $edges, $totalNodeCount);
        $edges = $this->candidateEdges($model, $nodes->pluck('node_id')->map(static fn (mixed $nodeId): string => (string) $nodeId)->all(), $totalEdgeCount);

        $nodeByNodeId = $nodes->keyBy('node_id');

        // Hand the (normalised) graph + query anchors to the real Python
        // networkx+numpy ranking engine. The boundary returns per-node scores +
        // structured boost decisions in final ranked order; the math lives there.
        $ranking = $this->graphRank->rank(
            $this->nodePayloads($nodes),
            $this->edgePayloads($edges),
            $this->queryPayload($query),
        );

        $scored = $this->composeScoredNodes(
            $ranking['scored'] ?? [],
            $nodeByNodeId,
        );

        $top = array_slice($scored, 0, $query->maxResults);
        $graphTop = $ranking['graph_top_node'] ?? ($top[0]['node_id'] ?? null);
        $textOnlyTop = $ranking['text_only_top_node'] ?? null;

        $rankedSources = $this->collapseToSources($top);

        $payload = [
            'schema_version' => self::SCHEMA,
            'world_model_id' => $model->model_id,
            'graph_version' => $model->model_hash,
            'graph_hash' => $this->graphHash($model, $nodes, $edges),
            'node_count' => $totalNodeCount,
            'edge_count' => $totalEdgeCount,
            'considered_node_count' => $nodes->count(),
            'considered_edge_count' => $edges->count(),
            'graph_hash_scope' => $totalNodeCount <= self::MAX_FULL_SCAN_NODES && $totalEdgeCount <= self::MAX_FULL_SCAN_EDGES
                ? 'full_world_model'
                : 'bounded_candidate_window',
            'query' => $query->toArray(),
            'query_signature' => $query->signature(),
            'ranked_nodes' => $top,
            'ranked_sources' => $rankedSources,
            'metrics' => [
                'text_only_top_node' => $textOnlyTop,
                'graph_top_node' => $graphTop,
                'graph_changed_top' => $textOnlyTop !== null && $graphTop !== null && $textOnlyTop !== $graphTop,
                'considered_nodes' => $nodes->count(),
                'considered_edges' => $edges->count(),
                'total_nodes' => $totalNodeCount,
                'total_edges' => $totalEdgeCount,
                'edge_types_used' => $this->edgeTypesUsed($top),
                'reasons_used' => $this->reasonsUsed($top),
            ],
            'generated_at' => now()->toJSON(),
        ];

        $payload['result_hash'] = AutonomousEngineeringHash::make([
            'schema' => self::SCHEMA,
            'graph_version' => $payload['graph_version'],
            'graph_hash' => $payload['graph_hash'],
            'query_signature' => $payload['query_signature'],
            'ranked_nodes' => array_map(
                static fn (array $node): array => [
                    'node_id' => $node['node_id'],
                    'score' => $node['score'],
                ],
                $top,
            ),
        ]);

        return $payload;
    }

    /**
     * Resolve the world model to rank over. Precedence (most → least specific):
     *
     *  1. explicit world_model_id              → that exact model (unchanged);
     *  2. workspace_id (W-3, additive)         → that workspace's SYMBOL-level
     *     world model via {@see CodeGraphWorkspaceModelResolver}, so a second
     *     workspace's graph no longer silently shadows atlas-server's;
     *  3. neither                              → most-recent built globally
     *     (the historical default — byte-identical to pre-W-3).
     */
    private function resolveModel(WorldModelRankingQuery $rankingQuery): ?AiCodebaseWorldModel
    {
        $worldModelId = $rankingQuery->worldModelId;
        if ($worldModelId !== null && $worldModelId !== '') {
            return AiCodebaseWorldModel::query()
                ->where('model_id', $worldModelId)
                ->first();
        }

        $workspaceId = $rankingQuery->workspaceId;
        if ($workspaceId !== null && trim($workspaceId) !== '') {
            return (new CodeGraphWorkspaceModelResolver)->symbolModel($workspaceId);
        }

        return AiCodebaseWorldModel::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int,AiCodebaseWorldModelNode>
     */
    private function candidateNodes(AiCodebaseWorldModel $model, WorldModelRankingQuery $query, int $totalNodeCount): Collection
    {
        if ($totalNodeCount <= self::MAX_FULL_SCAN_NODES) {
            return AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $model->id)
                ->orderBy('node_id')
                ->get();
        }

        $signals = array_values(array_unique(array_filter(array_merge(
            $query->textualSeeds,
            $query->targetFiles,
            $query->targetFlows,
            $query->targetCapabilities,
            $query->targetRisks,
        ))));

        if ($signals === []) {
            return AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $model->id)
                ->orderBy('node_id')
                ->limit(self::MAX_FULL_SCAN_NODES)
                ->get();
        }

        $nodes = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->where(function ($builder) use ($query, $signals): void {
                foreach ($query->targetFlows as $flow) {
                    $builder->orWhere('flow_id', $flow);
                }

                foreach ($signals as $signal) {
                    $like = '%'.$this->escapeLike($signal).'%';
                    $builder
                        ->orWhere('node_id', 'like', $like)
                        ->orWhere('path', 'like', $like)
                        ->orWhere('flow_id', 'like', $like);
                }
            })
            ->orderBy('node_id')
            ->limit(self::MAX_FULL_SCAN_NODES)
            ->get();

        if ($nodes->isNotEmpty()) {
            return $nodes;
        }

        return AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->orderBy('node_id')
            ->limit(self::MAX_FULL_SCAN_NODES)
            ->get();
    }

    /**
     * @param  array<int,string>  $nodeIds
     * @return Collection<int,AiCodebaseWorldModelEdge>
     */
    private function candidateEdges(AiCodebaseWorldModel $model, array $nodeIds, int $totalEdgeCount): Collection
    {
        if ($totalEdgeCount <= self::MAX_FULL_SCAN_EDGES) {
            return AiCodebaseWorldModelEdge::query()
                ->where('world_model_id', $model->id)
                ->orderBy('from_node_id')
                ->orderBy('to_node_id')
                ->orderBy('edge_type')
                ->get();
        }

        $nodeIds = array_values(array_unique(array_filter($nodeIds)));
        if ($nodeIds === []) {
            return collect();
        }

        return AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where(function ($builder) use ($nodeIds): void {
                $builder
                    ->whereIn('from_node_id', $nodeIds)
                    ->orWhereIn('to_node_id', $nodeIds);
            })
            ->orderBy('from_node_id')
            ->orderBy('to_node_id')
            ->orderBy('edge_type')
            ->limit(self::MAX_FULL_SCAN_EDGES)
            ->get();
    }

    /**
     * @param  Collection<int,AiCodebaseWorldModelNode>  $nodes
     * @param  Collection<int,AiCodebaseWorldModelEdge>  $edges
     * @return Collection<int,AiCodebaseWorldModelNode>
     */
    private function withEdgeNeighborNodes(
        AiCodebaseWorldModel $model,
        Collection $nodes,
        Collection $edges,
        int $totalNodeCount,
    ): Collection {
        if ($totalNodeCount <= self::MAX_FULL_SCAN_NODES || $nodes->count() >= self::MAX_FULL_SCAN_NODES) {
            return $nodes;
        }

        $known = $nodes->pluck('node_id')
            ->map(static fn (mixed $nodeId): string => (string) $nodeId)
            ->all();
        $neighborIds = [];
        foreach ($edges as $edge) {
            $neighborIds[] = (string) $edge->from_node_id;
            $neighborIds[] = (string) $edge->to_node_id;
        }
        $neighborIds = array_values(array_diff(array_unique(array_filter($neighborIds)), $known));
        if ($neighborIds === []) {
            return $nodes;
        }

        $remaining = max(0, self::MAX_FULL_SCAN_NODES - $nodes->count());
        if ($remaining === 0) {
            return $nodes;
        }

        $neighbors = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->whereIn('node_id', array_slice($neighborIds, 0, $remaining))
            ->orderBy('node_id')
            ->get();

        return $nodes
            ->merge($neighbors)
            ->unique('node_id')
            ->sortBy('node_id')
            ->values();
    }

    /**
     * Normalise the Eloquent nodes into the boundary's plain-array shape, with
     * the same lower-casing the prior in-PHP scorer applied to anchors/haystacks
     * (so the Python text/anchor matching is byte-identical). Field shape mirrors
     * what scoring.py expects.
     *
     * @param  Collection<int,AiCodebaseWorldModelNode>  $nodes
     * @return list<array<string,mixed>>
     */
    private function nodePayloads(Collection $nodes): array
    {
        return $nodes
            ->map(static fn (AiCodebaseWorldModelNode $node): array => [
                'node_id' => (string) $node->node_id,
                'node_type' => (string) $node->node_type,
                'path' => $node->path !== null ? (string) $node->path : null,
                'flow_id' => $node->flow_id !== null ? (string) $node->flow_id : null,
                'capabilities' => array_values(array_map(
                    static fn ($value): string => (string) $value,
                    (array) ($node->capabilities ?? []),
                )),
                'risks' => array_values(array_map(
                    static fn ($value): string => (string) $value,
                    (array) ($node->risks ?? []),
                )),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AiCodebaseWorldModelEdge>  $edges
     * @return list<array<string,mixed>>
     */
    private function edgePayloads(Collection $edges): array
    {
        return $edges
            ->map(static fn (AiCodebaseWorldModelEdge $edge): array => [
                'from_node_id' => (string) $edge->from_node_id,
                'to_node_id' => (string) $edge->to_node_id,
                'edge_type' => (string) $edge->edge_type,
            ])
            ->values()
            ->all();
    }

    /**
     * The query anchors handed to the boundary. They are already lower-cased by
     * {@see WorldModelRankingQuery} (cleanList), and the risk posture is reduced
     * to the single boolean the scorer needs.
     *
     * @return array<string,mixed>
     */
    private function queryPayload(WorldModelRankingQuery $query): array
    {
        return [
            'textual_seeds' => array_values($query->textualSeeds),
            'target_files' => array_values($query->targetFiles),
            'target_flows' => array_values($query->targetFlows),
            'target_capabilities' => array_values($query->targetCapabilities),
            'target_risks' => array_values($query->targetRisks),
            'risk_elevated' => $query->isRiskElevated(),
            'boost_docs' => $query->boostDocs,
            'boost_tests' => $query->boostTests,
        ];
    }

    /**
     * Turn the boundary's per-node math + structured boost decisions into the
     * public ranked_nodes payload: passthrough node metadata (type/path/flow/
     * capabilities/risks) from the local Eloquent records, and compose the human
     * reason strings + relation_path from the decisions. The boundary already
     * returns them in final ranked order, so no re-sort here.
     *
     * @param  array<int,array<string,mixed>>  $scored
     * @param  Collection<string,AiCodebaseWorldModelNode>  $nodeByNodeId
     * @return array<int,array<string,mixed>>
     */
    private function composeScoredNodes(array $scored, Collection $nodeByNodeId): array
    {
        $out = [];
        foreach ($scored as $entry) {
            $nodeId = (string) ($entry['node_id'] ?? '');
            $node = $nodeByNodeId->get($nodeId);
            if (! $node instanceof AiCodebaseWorldModelNode) {
                continue;
            }

            $relationPath = $this->normaliseRelationPath((array) ($entry['relation_path'] ?? []));
            $reasons = $this->composeReasons(
                (array) ($entry['reasons'] ?? []),
                $node,
            );

            $out[] = [
                'node_id' => $nodeId,
                'node_type' => $node->node_type,
                'path' => $node->path,
                'flow_id' => $node->flow_id,
                'capabilities' => array_values((array) ($node->capabilities ?? [])),
                'risks' => array_values((array) ($node->risks ?? [])),
                'text_score' => $this->floatOf($entry['text_score'] ?? 0.0),
                'graph_score' => $this->floatOf($entry['graph_score'] ?? 0.0),
                'score' => $this->floatOf($entry['score'] ?? 0.0),
                'confidence' => $this->floatOf($entry['confidence'] ?? 0.0),
                'reasons' => $reasons,
                'relation_path' => $relationPath,
            ];
        }

        return $out;
    }

    /**
     * Compose the public reason strings. Plain reasons pass through unchanged;
     * the boundary marks edge-derived reasons as "__edge__:direction:edge_type:
     * node_type" so this PHP side owns the human label vocabulary (edgeReason),
     * keeping the label taxonomy in one language.
     *
     * @param  array<int,mixed>  $rawReasons
     * @return array<int,string>
     */
    private function composeReasons(array $rawReasons, AiCodebaseWorldModelNode $node): array
    {
        $reasons = [];
        foreach ($rawReasons as $raw) {
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            if (! str_starts_with($raw, '__edge__:')) {
                $reasons[] = $raw;

                continue;
            }
            // __edge__:<direction>:<edge_type>:<node_type>
            $parts = explode(':', $raw, 4);
            $direction = $parts[1] ?? '';
            $edgeType = $parts[2] ?? '';
            $reasons[] = $this->edgeReason($node, $edgeType, $direction === 'outgoing');
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<int,mixed>  $relationPath
     * @return array<int,array<string,string>>
     */
    private function normaliseRelationPath(array $relationPath): array
    {
        $out = [];
        foreach ($relationPath as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $out[] = [
                'from' => (string) ($edge['from'] ?? ''),
                'to' => (string) ($edge['to'] ?? ''),
                'edge_type' => (string) ($edge['edge_type'] ?? ''),
                'direction' => (string) ($edge['direction'] ?? ''),
            ];
        }

        return $out;
    }

    private function floatOf(mixed $value): float
    {
        return round((float) $value, 4);
    }

    /**
     * Human reason label for an edge-derived boost. Pure string vocabulary (no
     * math) — kept in PHP so the public reason taxonomy lives in the kernel.
     */
    private function edgeReason(
        AiCodebaseWorldModelNode $node,
        string $edgeType,
        bool $outgoing,
    ): string {
        $type = $edgeType;
        if ($outgoing && $type === 'tests' && $node->node_type === 'test') {
            return 'test_covers_seed';
        }
        if ($outgoing && in_array($type, ['documents', 'documented_by'], true) && $node->node_type === 'doc') {
            return 'governing_doc_for_seed';
        }
        if (in_array($type, ['depends_on', 'invokes'], true)) {
            return ($outgoing ? 'outgoing_' : 'incoming_').$type.'_seed';
        }
        if ($type === 'contains_symbol') {
            return 'contains_seed_symbol';
        }
        if ($type === 'defines') {
            return $outgoing ? 'defines_seed_symbol' : 'defined_by_seed';
        }

        return ($outgoing ? 'outgoing_' : 'incoming_').$type;
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<int,array<string,mixed>>
     */
    private function collapseToSources(array $ranked): array
    {
        $byPath = [];
        foreach ($ranked as $node) {
            $path = (string) ($node['path'] ?? '');
            if ($path === '') {
                continue;
            }
            if (! isset($byPath[$path])) {
                $byPath[$path] = [
                    'path' => $path,
                    'source_kind' => $this->sourceKind((string) $node['node_type']),
                    'top_score' => $node['score'],
                    'top_confidence' => $node['confidence'],
                    'node_count' => 1,
                    'node_ids' => [$node['node_id']],
                    'reasons' => $node['reasons'],
                ];

                continue;
            }
            $byPath[$path]['node_count']++;
            $byPath[$path]['node_ids'][] = $node['node_id'];
            $byPath[$path]['reasons'] = array_values(array_unique(array_merge(
                $byPath[$path]['reasons'],
                $node['reasons'],
            )));
            if ($node['score'] > $byPath[$path]['top_score']) {
                $byPath[$path]['top_score'] = $node['score'];
                $byPath[$path]['top_confidence'] = $node['confidence'];
            }
        }

        $sources = array_values($byPath);
        usort(
            $sources,
            static fn (array $a, array $b): int => $b['top_score'] <=> $a['top_score']
                ?: strcmp((string) $a['path'], (string) $b['path']),
        );

        return $sources;
    }

    private function sourceKind(string $nodeType): string
    {
        return match ($nodeType) {
            'doc' => 'canonical_docs',
            'test' => 'related_tests',
            'module', 'service', 'file' => 'code_symbols',
            'command' => 'commands',
            'migration' => 'migrations',
            default => 'world_model_node',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<int,string>
     */
    private function edgeTypesUsed(array $ranked): array
    {
        $types = [];
        foreach ($ranked as $node) {
            foreach ((array) ($node['relation_path'] ?? []) as $edge) {
                if (isset($edge['edge_type']) && is_string($edge['edge_type'])) {
                    $types[] = $edge['edge_type'];
                }
            }
        }
        $unique = array_values(array_unique($types));
        sort($unique);

        return $unique;
    }

    /**
     * @param  array<int,array<string,mixed>>  $ranked
     * @return array<int,string>
     */
    private function reasonsUsed(array $ranked): array
    {
        $reasons = [];
        foreach ($ranked as $node) {
            foreach ((array) ($node['reasons'] ?? []) as $reason) {
                if (is_string($reason)) {
                    $reasons[] = $reason;
                }
            }
        }
        $unique = array_values(array_unique($reasons));
        sort($unique);

        return $unique;
    }

    /**
     * @param  Collection<int,AiCodebaseWorldModelNode>  $nodes
     * @param  Collection<int,AiCodebaseWorldModelEdge>  $edges
     */
    private function graphHash(AiCodebaseWorldModel $model, Collection $nodes, Collection $edges): string
    {
        return AutonomousEngineeringHash::make([
            'model_hash' => $model->model_hash,
            'node_signatures' => $nodes
                ->map(static fn (AiCodebaseWorldModelNode $node): array => [
                    'node_id' => $node->node_id,
                    'node_type' => $node->node_type,
                    'path' => $node->path,
                    'flow_id' => $node->flow_id,
                ])
                ->sortBy('node_id')
                ->values()
                ->all(),
            'edge_signatures' => $edges
                ->map(static fn (AiCodebaseWorldModelEdge $edge): array => [
                    'from' => $edge->from_node_id,
                    'to' => $edge->to_node_id,
                    'type' => $edge->edge_type,
                ])
                ->sortBy(static fn (array $value): string => $value['from'].'|'.$value['to'].'|'.$value['type'])
                ->values()
                ->all(),
        ]);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\%_');
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(WorldModelRankingQuery $query, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'world_model_id' => null,
            'graph_version' => null,
            'graph_hash' => null,
            'node_count' => 0,
            'edge_count' => 0,
            'query' => $query->toArray(),
            'query_signature' => $query->signature(),
            'ranked_nodes' => [],
            'ranked_sources' => [],
            'metrics' => [
                'text_only_top_node' => null,
                'graph_top_node' => null,
                'graph_changed_top' => false,
                'considered_nodes' => 0,
                'edge_types_used' => [],
                'reasons_used' => [],
                'fallback_reason' => $reason,
            ],
            'result_hash' => AutonomousEngineeringHash::make([
                'schema' => self::SCHEMA,
                'reason' => $reason,
                'query_signature' => $query->signature(),
            ]),
            'generated_at' => now()->toJSON(),
        ];
    }

    private function tablesReady(): bool
    {
        try {
            return Schema::hasTable('ai_codebase_world_models')
                && Schema::hasTable('ai_codebase_world_model_nodes')
                && Schema::hasTable('ai_codebase_world_model_edges');
        } catch (Throwable) {
            return false;
        }
    }
}
