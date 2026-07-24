<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use App\Services\Engineering\CodeGraph\CodeGraphAdjacencyIndex;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver;
use App\Support\AtlasSecurity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * GOD-DEBULK FASE C: code-graph traversal tool family extracted verbatim from
 * AtlasOpenBrainMcpService (AP-811 / AP-815). Read-only over the world-model
 * edge/node tables and the read-only WorldModelGraphRanker. No writes, no
 * provider. The façade delegates atlas_code_neighbors / atlas_code_path /
 * atlas_code_explain here; bodies are byte-identical to the pre-split service.
 */
class CodeGraphTools
{
    use OpenBrainMcpToolInput;

    // ---------------------------------------------------------------------
    // Code graph traversal tools (AP-811). Read-only over the world-model
    // edge/node tables and the read-only WorldModelGraphRanker. No writes,
    // no provider. Every graph-derived string is run through the same
    // provider-safe redaction the memory/code tools use before it leaves.
    // ---------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function codeNeighbors(array $arguments): array
    {
        $tool = 'atlas_code_neighbors';
        $model = $this->resolveGraphModel($arguments);
        if ($model === null) {
            return $this->noGraph($tool, $arguments);
        }

        $direction = strtolower($this->string($arguments['direction'] ?? null) ?? 'both');
        if (! in_array($direction, ['in', 'out', 'both'], true)) {
            $direction = 'both';
        }

        $start = $this->resolveStartNode($model, $arguments);
        if ($start === null) {
            return [
                'ok' => false,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'error' => 'node_not_found',
                'generated_at' => now()->toJSON(),
            ];
        }

        $maxNodes = $this->traversalMaxNodes();
        $limit = $this->positiveInt($arguments['limit'] ?? null);
        $limit = $limit === null ? $maxNodes : min($limit, $maxNodes);

        $edges = $this->edgesTouching($model, $start->node_id);
        // AP-815 B3: load only the neighbour nodes this call presents, not the whole graph.
        $neighborIds = [];
        foreach ($edges as $edge) {
            $neighborIds[] = $edge->from_node_id === $start->node_id ? $edge->to_node_id : $edge->from_node_id;
        }
        $nodeIndex = $this->nodeIndex($model, $neighborIds);

        $neighbors = [];
        foreach ($edges as $edge) {
            $isOut = $edge->from_node_id === $start->node_id;
            $isIn = $edge->to_node_id === $start->node_id;
            if ($direction === 'out' && ! $isOut) {
                continue;
            }
            if ($direction === 'in' && ! $isIn) {
                continue;
            }
            $otherId = $isOut ? $edge->to_node_id : $edge->from_node_id;
            $otherNode = $nodeIndex[$otherId] ?? null;
            $neighbors[] = [
                'direction' => $isOut ? 'out' : 'in',
                'edge' => $this->presentEdge($edge),
                'node' => $otherNode instanceof AiCodebaseWorldModelNode
                    ? $this->presentNode($otherNode)
                    : ['node_id' => $this->sanitizeGraphText($otherId), 'resolved' => false],
            ];
            if (count($neighbors) >= $limit) {
                break;
            }
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'world_model_id' => $this->sanitizeGraphText($model->model_id),
            'node' => $this->presentNode($start),
            'direction' => $direction,
            'limit' => $limit,
            'neighbors' => $neighbors,
            'count' => count($neighbors),
            'truncated' => count($neighbors) >= $limit && $edges->count() > count($neighbors),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function codePath(array $arguments): array
    {
        $tool = 'atlas_code_path';
        $model = $this->resolveGraphModel($arguments);
        if ($model === null) {
            return $this->noGraph($tool, $arguments);
        }

        $from = $this->resolveStartNode($model, ['node_id' => $arguments['from'] ?? null, 'query' => $arguments['from'] ?? null]);
        $to = $this->resolveStartNode($model, ['node_id' => $arguments['to'] ?? null, 'query' => $arguments['to'] ?? null]);
        if ($from === null || $to === null) {
            return [
                'ok' => false,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'error' => $from === null ? 'from_node_not_found' : 'to_node_not_found',
                'generated_at' => now()->toJSON(),
            ];
        }

        $maxDepth = $this->traversalMaxDepth();
        $maxNodes = $this->traversalMaxNodes();
        $adjacency = $this->adjacency($model);

        $pathNodeIds = $this->bfsShortestPath($from->node_id, $to->node_id, $adjacency, $maxDepth, $maxNodes);

        if ($pathNodeIds === null) {
            return [
                'ok' => true,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'from' => $this->presentNode($from),
                'to' => $this->presentNode($to),
                'found' => false,
                'reason' => 'no_path_within_traversal_limits',
                'max_depth' => $maxDepth,
                'max_nodes' => $maxNodes,
                'path' => [],
                'hops' => 0,
                'generated_at' => now()->toJSON(),
            ];
        }

        // AP-815 B3: load only the path's nodes, not the whole graph.
        $nodeIndex = $this->nodeIndex($model, $pathNodeIds);
        $path = [];
        foreach ($pathNodeIds as $nodeId) {
            $node = $nodeIndex[$nodeId] ?? null;
            $path[] = $node instanceof AiCodebaseWorldModelNode
                ? $this->presentNode($node)
                : ['node_id' => $this->sanitizeGraphText($nodeId), 'resolved' => false];
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'world_model_id' => $this->sanitizeGraphText($model->model_id),
            'from' => $this->presentNode($from),
            'to' => $this->presentNode($to),
            'found' => true,
            'max_depth' => $maxDepth,
            'max_nodes' => $maxNodes,
            'path' => $path,
            'hops' => max(0, count($path) - 1),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function codeExplain(array $arguments): array
    {
        $tool = 'atlas_code_explain';
        $model = $this->resolveGraphModel($arguments);
        if ($model === null) {
            return $this->noGraph($tool, $arguments);
        }

        $node = $this->resolveStartNode($model, $arguments);
        if ($node === null) {
            return [
                'ok' => false,
                'tool' => $tool,
                'world_model_id' => $this->sanitizeGraphText($model->model_id),
                'error' => 'node_not_found',
                'generated_at' => now()->toJSON(),
            ];
        }

        $maxNodes = $this->traversalMaxNodes();
        $edges = $this->edgesTouching($model, $node->node_id);
        // AP-815 B3: load only the adjacent nodes this call presents, not the whole graph.
        $adjacentIds = [];
        foreach ($edges as $edge) {
            $adjacentIds[] = $edge->from_node_id === $node->node_id ? $edge->to_node_id : $edge->from_node_id;
        }
        $nodeIndex = $this->nodeIndex($model, $adjacentIds);

        $outgoing = [];
        $incoming = [];
        foreach ($edges as $edge) {
            if ($edge->from_node_id === $node->node_id) {
                $other = $nodeIndex[$edge->to_node_id] ?? null;
                if (count($outgoing) < $maxNodes) {
                    $outgoing[] = [
                        'edge' => $this->presentEdge($edge),
                        'node' => $other instanceof AiCodebaseWorldModelNode
                            ? $this->presentNode($other)
                            : ['node_id' => $this->sanitizeGraphText($edge->to_node_id), 'resolved' => false],
                    ];
                }
            }
            if ($edge->to_node_id === $node->node_id) {
                $other = $nodeIndex[$edge->from_node_id] ?? null;
                if (count($incoming) < $maxNodes) {
                    $incoming[] = [
                        'edge' => $this->presentEdge($edge),
                        'node' => $other instanceof AiCodebaseWorldModelNode
                            ? $this->presentNode($other)
                            : ['node_id' => $this->sanitizeGraphText($edge->from_node_id), 'resolved' => false],
                    ];
                }
            }
        }

        return [
            'ok' => true,
            'tool' => $tool,
            'world_model_id' => $this->sanitizeGraphText($model->model_id),
            'node' => $this->presentNode($node),
            'outgoing_edges' => $outgoing,
            'incoming_edges' => $incoming,
            'outgoing_count' => count($outgoing),
            'incoming_count' => count($incoming),
            'degree' => $edges->count(),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * Pick the world model to traverse. Precedence (most → least specific):
     *
     *  1. explicit world_model_id → that exact model (unchanged);
     *  2. workspace (W-3, additive) → that workspace's SYMBOL-level world model
     *     via {@see CodeGraphWorkspaceModelResolver}, so a second workspace's
     *     graph no longer silently shadows atlas-server's latest;
     *  3. neither → most-recent built globally (byte-identical to pre-W-3).
     *
     * Returns null when the graph tables are missing or no model exists.
     *
     * @param  array<string,mixed>  $arguments
     */
    private function resolveGraphModel(array $arguments): ?AiCodebaseWorldModel
    {
        if (! $this->graphTablesReady()) {
            return null;
        }

        $worldModelId = $this->string($arguments['world_model_id'] ?? null);
        if ($worldModelId !== null) {
            return AiCodebaseWorldModel::query()
                ->where('model_id', $worldModelId)
                ->first();
        }

        $workspace = $this->string($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            return (new CodeGraphWorkspaceModelResolver)->symbolModel($workspace);
        }

        return AiCodebaseWorldModel::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Resolve a starting node from an explicit node_id (exact match) or, when
     * absent, a textual query routed through the read-only ranker.
     *
     * @param  array<string,mixed>  $arguments
     */
    private function resolveStartNode(AiCodebaseWorldModel $model, array $arguments): ?AiCodebaseWorldModelNode
    {
        $nodeId = $this->string($arguments['node_id'] ?? null);
        if ($nodeId !== null) {
            $node = AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $model->id)
                ->where('node_id', $nodeId)
                ->first();
            if ($node !== null) {
                return $node;
            }
        }

        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return null;
        }

        // When the query already looks like an exact node id, prefer that.
        $byId = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->where('node_id', $query)
            ->first();
        if ($byId !== null) {
            return $byId;
        }

        $ranking = (new WorldModelGraphRanker)->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => [$query],
            'world_model_id' => $model->model_id,
            'max_results' => 1,
        ]));
        $topNodeId = data_get($ranking, 'ranked_nodes.0.node_id');
        if (! is_string($topNodeId) || $topNodeId === '') {
            return null;
        }

        return AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->where('node_id', $topNodeId)
            ->first();
    }

    /**
     * @return Collection<int,AiCodebaseWorldModelEdge>
     */
    private function edgesTouching(AiCodebaseWorldModel $model, string $nodeId): Collection
    {
        // AP-815 B1: two index-seekable queries UNION'd, instead of a (from=? OR to=?)
        // predicate that no single composite index can serve. Each side hits the
        // (world_model_id, from_node_id) / (…, to_node_id) composite index directly.
        // unionAll (NOT union): a UNION's implicit DISTINCT makes pgsql compare every
        // selected column for equality, and the `metadata` json column has no `=`
        // operator (SQLSTATE 42883) — so we union-ALL and dedup the self-loop in PHP.
        // Structural backstop: the 2026_06_09_130000 migration converts these json
        // columns to jsonb (which HAS `=`), so the whole 42883 class is closed too.
        $incoming = AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where('to_node_id', $nodeId);

        return AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->where('from_node_id', $nodeId)
            ->unionAll($incoming)
            ->get()
            ->unique('id')
            ->sort(static fn (AiCodebaseWorldModelEdge $a, AiCodebaseWorldModelEdge $b): int => [$a->from_node_id, $a->to_node_id, $a->edge_type] <=> [$b->from_node_id, $b->to_node_id, $b->edge_type])
            ->values();
    }

    /** @var array<string,array<string,array<int,string>>> AP-815 B2: per-request adjacency memo, keyed by model id. */
    private array $adjacencyCache = [];

    /** @var array<string,array<string,AiCodebaseWorldModelNode>> AP-815 B3: per-request full node-index memo, keyed by model id. */
    private array $nodeIndexCache = [];

    /**
     * AP-815 B3: resolve graph nodes. With $nodeIds, fetch ONLY those (a traversal
     * visits ≤ max_nodes, not the whole graph); without, load all once and memoize so
     * repeated traversal calls in a request don't reload the entire node set.
     *
     * @param  array<int,string>|null  $nodeIds
     * @return array<string,AiCodebaseWorldModelNode>
     */
    private function nodeIndex(AiCodebaseWorldModel $model, ?array $nodeIds = null): array
    {
        if ($nodeIds !== null) {
            $nodeIds = array_values(array_unique(array_filter(
                $nodeIds,
                static fn ($id): bool => is_string($id) && $id !== '',
            )));
            if ($nodeIds === []) {
                return [];
            }

            return AiCodebaseWorldModelNode::query()
                ->where('world_model_id', $model->id)
                ->whereIn('node_id', $nodeIds)
                ->get()
                ->keyBy('node_id')
                ->all();
        }

        $key = (string) $model->id;
        if (array_key_exists($key, $this->nodeIndexCache)) {
            return $this->nodeIndexCache[$key];
        }

        return $this->nodeIndexCache[$key] = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->get()
            ->keyBy('node_id')
            ->all();
    }

    /**
     * Undirected adjacency map (both edge directions are walkable for path finding).
     *
     * AP-815 B2: built ONCE per request via the capped CodeGraphAdjacencyIndex (D-1)
     * and memoized — codeNeighbors/codePath/codeExplain on one model share a single
     * build instead of re-reading the full edge table every call.
     *
     * @return array<string,array<int,string>>
     */
    private function adjacency(AiCodebaseWorldModel $model): array
    {
        $key = (string) $model->id;
        if (array_key_exists($key, $this->adjacencyCache)) {
            return $this->adjacencyCache[$key];
        }

        $index = CodeGraphAdjacencyIndex::fromEdges(
            AiCodebaseWorldModelEdge::query()
                ->where('world_model_id', $model->id)
                ->get(['from_node_id', 'to_node_id'])
                ->map(static fn (AiCodebaseWorldModelEdge $edge): array => [
                    'from_node_id' => $edge->from_node_id,
                    'to_node_id' => $edge->to_node_id,
                ])
                ->all(),
        );

        $adjacency = [];
        foreach ($index->nodes() as $nodeId) {
            $adjacency[$nodeId] = array_values(array_unique(array_merge(
                $index->neighbors($nodeId),
                $index->incoming($nodeId),
            )));
        }

        return $this->adjacencyCache[$key] = $adjacency;
    }

    /**
     * Bounded BFS shortest path. Honours both the depth and node-budget caps.
     *
     * @param  array<string,array<int,string>>  $adjacency
     * @return array<int,string>|null
     */
    private function bfsShortestPath(
        string $from,
        string $to,
        array $adjacency,
        int $maxDepth,
        int $maxNodes,
    ): ?array {
        if ($from === $to) {
            return [$from];
        }

        $visited = [$from => true];
        $parents = [];
        $queue = [[$from, 0]];
        $expanded = 0;

        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) {
                continue;
            }
            if (++$expanded > $maxNodes) {
                break;
            }
            foreach ($adjacency[$current] ?? [] as $next) {
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = true;
                $parents[$next] = $current;
                if ($next === $to) {
                    return $this->reconstructPath($parents, $from, $to);
                }
                $queue[] = [$next, $depth + 1];
                if (count($visited) > $maxNodes) {
                    break 2;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,string>  $parents
     * @return array<int,string>
     */
    private function reconstructPath(array $parents, string $from, string $to): array
    {
        $path = [$to];
        $cursor = $to;
        while ($cursor !== $from && isset($parents[$cursor])) {
            $cursor = $parents[$cursor];
            $path[] = $cursor;
        }

        return array_reverse($path);
    }

    /**
     * @return array<string,mixed>
     */
    private function presentNode(AiCodebaseWorldModelNode $node): array
    {
        return [
            'node_id' => $this->sanitizeGraphText($node->node_id),
            'node_type' => $this->sanitizeGraphText($node->node_type),
            'path' => $this->sanitizeGraphText($node->path),
            'flow_id' => $this->sanitizeGraphText($node->flow_id),
            'capabilities' => $this->sanitizeGraphList((array) ($node->capabilities ?? [])),
            'risks' => $this->sanitizeGraphList((array) ($node->risks ?? [])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function presentEdge(AiCodebaseWorldModelEdge $edge): array
    {
        $metadata = is_array($edge->metadata) ? $edge->metadata : [];

        return [
            'from_node_id' => $this->sanitizeGraphText($edge->from_node_id),
            'to_node_id' => $this->sanitizeGraphText($edge->to_node_id),
            'edge_type' => $this->sanitizeGraphText($edge->edge_type),
            'confidence' => $this->sanitizeGraphText(
                is_scalar($metadata['confidence'] ?? null) ? (string) $metadata['confidence'] : null,
            ),
            'inferred' => (bool) ($metadata['inferred'] ?? false),
        ];
    }

    /**
     * Graceful "no graph" response — never throws when nothing is built.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    private function noGraph(string $tool, array $arguments): array
    {
        return [
            'ok' => true,
            'tool' => $tool,
            'graph_available' => false,
            'reason' => $this->graphTablesReady() ? 'no_world_model_built' : 'world_model_tables_missing',
            'message' => 'No code world model has been built yet. Build one before traversing the code graph.',
            'world_model_id' => $this->sanitizeGraphText($this->string($arguments['world_model_id'] ?? null)),
            'generated_at' => now()->toJSON(),
        ];
    }

    private function graphTablesReady(): bool
    {
        try {
            return Schema::hasTable('ai_codebase_world_models')
                && Schema::hasTable('ai_codebase_world_model_nodes')
                && Schema::hasTable('ai_codebase_world_model_edges');
        } catch (Throwable) {
            return false;
        }
    }

    private function traversalMaxDepth(): int
    {
        return max(1, (int) config('atlas.code_graph.traversal_max_depth', 4));
    }

    private function traversalMaxNodes(): int
    {
        return max(1, (int) config('atlas.code_graph.traversal_max_nodes', 60));
    }

    /**
     * Provider-safe sanitization for every graph-derived string that leaves
     * the service: the same secret/credential redaction used elsewhere, plus
     * a control-char strip and a hard length cap so graph text cannot smuggle
     * payloads or blow up the response.
     */
    private function sanitizeGraphText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            return null;
        }
        $string = (string) $value;
        $string = AtlasSecurity::redactString($string);
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string) ?? $string;
        $string = trim($string);
        if ($string === '') {
            return null;
        }
        if (mb_strlen($string) > 512) {
            $string = mb_substr($string, 0, 512).'…';
        }

        return $string;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function sanitizeGraphList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            $sanitized = $this->sanitizeGraphText($value);
            if ($sanitized !== null) {
                $clean[] = $sanitized;
            }
        }

        return array_values(array_unique($clean));
    }
}
