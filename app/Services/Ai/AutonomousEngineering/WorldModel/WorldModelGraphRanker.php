<?php

namespace App\Services\Ai\AutonomousEngineering\WorldModel;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AutonomousEngineering\AutonomousEngineeringHash;
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
 */
class WorldModelGraphRanker
{
    public const SCHEMA = 'atlas.ai.codebase_world_model.ranking.v1';

    /**
     * Edge type → boost weight applied when an edge connects the candidate
     * node to a node anchored by the query (target file/flow/seed).
     *
     * @var array<string,float>
     */
    private const EDGE_WEIGHTS = [
        'tests' => 0.45,
        'documents' => 0.50,
        'documented_by' => 0.50,
        'defines' => 0.30,
        'depends_on' => 0.25,
        'contains_symbol' => 0.20,
        'invokes' => 0.30,
    ];

    private const SCORE_CAP = 2.5;

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

        $model = $this->resolveModel($query->worldModelId);
        if ($model === null) {
            return $this->emptyResult($query, reason: 'world_model_not_found');
        }

        /** @var Collection<int,AiCodebaseWorldModelNode> $nodes */
        $nodes = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->orderBy('node_id')
            ->get();

        /** @var Collection<int,AiCodebaseWorldModelEdge> $edges */
        $edges = AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->orderBy('from_node_id')
            ->orderBy('to_node_id')
            ->orderBy('edge_type')
            ->get();

        $edgesByFrom = $edges->groupBy('from_node_id');
        $edgesByTo = $edges->groupBy('to_node_id');
        $nodeByNodeId = $nodes->keyBy('node_id');

        $textOnlyTop = $this->textOnlyTop($nodes, $query);

        $scored = [];
        foreach ($nodes as $node) {
            $scored[] = $this->scoreNode(
                node: $node,
                query: $query,
                edgesByFrom: $edgesByFrom,
                edgesByTo: $edgesByTo,
                nodeByNodeId: $nodeByNodeId,
            );
        }

        usort(
            $scored,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score']
                ?: strcmp((string) $a['node_id'], (string) $b['node_id']),
        );

        $top = array_slice($scored, 0, $query->maxResults);
        $graphTop = $top[0]['node_id'] ?? null;

        $rankedSources = $this->collapseToSources($top);

        $payload = [
            'schema_version' => self::SCHEMA,
            'world_model_id' => $model->model_id,
            'graph_version' => $model->model_hash,
            'graph_hash' => $this->graphHash($model, $nodes, $edges),
            'node_count' => $nodes->count(),
            'edge_count' => $edges->count(),
            'query' => $query->toArray(),
            'query_signature' => $query->signature(),
            'ranked_nodes' => $top,
            'ranked_sources' => $rankedSources,
            'metrics' => [
                'text_only_top_node' => $textOnlyTop,
                'graph_top_node' => $graphTop,
                'graph_changed_top' => $textOnlyTop !== null && $graphTop !== null && $textOnlyTop !== $graphTop,
                'considered_nodes' => $nodes->count(),
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

    private function resolveModel(?string $worldModelId): ?AiCodebaseWorldModel
    {
        $query = AiCodebaseWorldModel::query();
        if ($worldModelId !== null && $worldModelId !== '') {
            $query->where('model_id', $worldModelId);
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        return $query->first();
    }

    /**
     * @param  Collection<string,Collection<int,AiCodebaseWorldModelEdge>>  $edgesByFrom
     * @param  Collection<string,Collection<int,AiCodebaseWorldModelEdge>>  $edgesByTo
     * @param  Collection<string,AiCodebaseWorldModelNode>  $nodeByNodeId
     * @return array<string,mixed>
     */
    private function scoreNode(
        AiCodebaseWorldModelNode $node,
        WorldModelRankingQuery $query,
        Collection $edgesByFrom,
        Collection $edgesByTo,
        Collection $nodeByNodeId,
    ): array {
        $textScore = $this->textScore($node, $query);
        $graphScore = 0.0;
        $reasons = [];
        $relationPath = [];

        if ($this->pathMatchesTargetFiles($node, $query)) {
            $graphScore += 0.60;
            $reasons[] = 'query_target_file_match';
        }
        if ($node->flow_id !== null && in_array(strtolower((string) $node->flow_id), $query->targetFlows, true)) {
            $graphScore += 0.45;
            $reasons[] = 'query_target_flow_match';
        }

        $capabilityOverlap = $this->intersection(
            (array) ($node->capabilities ?? []),
            $query->targetCapabilities,
        );
        if ($capabilityOverlap !== []) {
            $graphScore += min(0.40, 0.20 * count($capabilityOverlap));
            $reasons[] = 'capability_overlap:'.implode(',', $capabilityOverlap);
        }

        $riskOverlap = $this->intersection(
            (array) ($node->risks ?? []),
            $query->targetRisks,
        );
        if ($riskOverlap !== []) {
            $boost = $query->isRiskElevated() ? 0.50 : 0.22;
            $graphScore += $boost;
            $reasons[] = 'risk_match:'.implode(',', $riskOverlap);
        }

        // Edge-derived boosts. A boost requires the OTHER endpoint of the
        // edge to be anchored by the query (target file/flow/seed match).
        foreach ($this->outgoingEdges($edgesByFrom, $node->node_id) as $edge) {
            $other = $nodeByNodeId->get($edge->to_node_id);
            if (! $this->edgeAnchored($other, $query)) {
                continue;
            }
            $weight = self::EDGE_WEIGHTS[$edge->edge_type] ?? 0.12;
            $graphScore += $weight;
            $reasons[] = $this->edgeReason($node, $edge, outgoing: true);
            $relationPath[] = [
                'from' => $edge->from_node_id,
                'to' => $edge->to_node_id,
                'edge_type' => $edge->edge_type,
                'direction' => 'outgoing',
            ];
        }

        foreach ($this->incomingEdges($edgesByTo, $node->node_id) as $edge) {
            $other = $nodeByNodeId->get($edge->from_node_id);
            if (! $this->edgeAnchored($other, $query)) {
                continue;
            }
            $weight = self::EDGE_WEIGHTS[$edge->edge_type] ?? 0.12;
            $graphScore += $weight;
            $reasons[] = $this->edgeReason($node, $edge, outgoing: false);
            $relationPath[] = [
                'from' => $edge->from_node_id,
                'to' => $edge->to_node_id,
                'edge_type' => $edge->edge_type,
                'direction' => 'incoming',
            ];
        }

        if ($query->boostTests && $node->node_type === 'test') {
            $graphScore += 0.12;
            $reasons[] = 'task_requests_test_boost';
        }
        if ($query->boostDocs && $node->node_type === 'doc') {
            $graphScore += 0.18;
            $reasons[] = 'task_requests_doc_boost';
        }

        $combined = round(min(self::SCORE_CAP, max(0.0, $textScore * 0.55 + $graphScore * 0.85)), 4);
        $confidence = round(min(1.0, $textScore * 0.30 + min(1.0, $graphScore) * 0.70), 4);

        if ($reasons === [] && $textScore > 0.0) {
            $reasons[] = 'textual_match_only';
        }

        return [
            'node_id' => $node->node_id,
            'node_type' => $node->node_type,
            'path' => $node->path,
            'flow_id' => $node->flow_id,
            'capabilities' => array_values((array) ($node->capabilities ?? [])),
            'risks' => array_values((array) ($node->risks ?? [])),
            'text_score' => round($textScore, 4),
            'graph_score' => round($graphScore, 4),
            'score' => $combined,
            'confidence' => $confidence,
            'reasons' => array_values(array_unique($reasons)),
            'relation_path' => $relationPath,
        ];
    }

    private function textScore(AiCodebaseWorldModelNode $node, WorldModelRankingQuery $query): float
    {
        if ($query->textualSeeds === []) {
            return 0.0;
        }
        $haystack = strtolower(implode(' ', array_filter([
            (string) ($node->path ?? ''),
            (string) ($node->flow_id ?? ''),
            implode(' ', (array) ($node->capabilities ?? [])),
            implode(' ', (array) ($node->risks ?? [])),
            (string) ($node->node_id ?? ''),
        ])));
        if ($haystack === '') {
            return 0.0;
        }

        $hits = 0;
        foreach ($query->textualSeeds as $seed) {
            if ($seed === '') {
                continue;
            }
            if (str_contains($haystack, $seed)) {
                $hits++;
            }
        }

        return $hits === 0
            ? 0.0
            : round(min(1.0, $hits / max(1, count($query->textualSeeds))), 4);
    }

    private function pathMatchesTargetFiles(AiCodebaseWorldModelNode $node, WorldModelRankingQuery $query): bool
    {
        if ($query->targetFiles === []) {
            return false;
        }
        $path = strtolower((string) ($node->path ?? ''));
        if ($path === '') {
            return false;
        }
        foreach ($query->targetFiles as $target) {
            if ($target !== '' && str_contains($path, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A node is "anchored" by the query if it matches a target file, target
     * flow, target capability or a textual seed — i.e., the query treats it
     * as one of the entry points.
     */
    private function edgeAnchored(?AiCodebaseWorldModelNode $node, WorldModelRankingQuery $query): bool
    {
        if (! $node instanceof AiCodebaseWorldModelNode) {
            return false;
        }
        if ($this->pathMatchesTargetFiles($node, $query)) {
            return true;
        }
        if ($node->flow_id !== null && in_array(strtolower((string) $node->flow_id), $query->targetFlows, true)) {
            return true;
        }
        $caps = $this->intersection((array) ($node->capabilities ?? []), $query->targetCapabilities);
        if ($caps !== []) {
            return true;
        }
        $textScore = $this->textScore($node, $query);

        return $textScore >= 0.5;
    }

    /**
     * @param  Collection<string,Collection<int,AiCodebaseWorldModelEdge>>  $edgesByFrom
     * @return array<int,AiCodebaseWorldModelEdge>
     */
    private function outgoingEdges(Collection $edgesByFrom, string $nodeId): array
    {
        $bucket = $edgesByFrom->get($nodeId);

        return $bucket === null ? [] : $bucket->all();
    }

    /**
     * @param  Collection<string,Collection<int,AiCodebaseWorldModelEdge>>  $edgesByTo
     * @return array<int,AiCodebaseWorldModelEdge>
     */
    private function incomingEdges(Collection $edgesByTo, string $nodeId): array
    {
        $bucket = $edgesByTo->get($nodeId);

        return $bucket === null ? [] : $bucket->all();
    }

    private function edgeReason(
        AiCodebaseWorldModelNode $node,
        AiCodebaseWorldModelEdge $edge,
        bool $outgoing,
    ): string {
        $type = $edge->edge_type;
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
     * @param  array<int,mixed>  $a
     * @param  array<int,string>  $b
     * @return array<int,string>
     */
    private function intersection(array $a, array $b): array
    {
        if ($a === [] || $b === []) {
            return [];
        }
        $left = array_values(array_unique(array_filter(array_map(
            static fn ($value): ?string => is_string($value) ? strtolower(trim($value)) : null,
            $a,
        ))));
        $intersection = array_values(array_intersect($left, $b));
        sort($intersection);

        return $intersection;
    }

    /**
     * @param  Collection<int,AiCodebaseWorldModelNode>  $nodes
     */
    private function textOnlyTop(Collection $nodes, WorldModelRankingQuery $query): ?string
    {
        if ($query->textualSeeds === []) {
            return null;
        }
        $best = null;
        $bestScore = -1.0;
        foreach ($nodes as $node) {
            $score = $this->textScore($node, $query);
            if ($score > $bestScore || ($score === $bestScore && $best !== null && strcmp($node->node_id, $best) < 0)) {
                $best = $node->node_id;
                $bestScore = $score;
            }
        }

        return $bestScore > 0.0 ? $best : null;
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
