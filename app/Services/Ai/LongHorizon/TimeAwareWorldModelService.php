<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * TEOS-I4 · Time-Aware World Model.
 *
 * Read-only projection over the existing Codebase World Model tables. It
 * consumes Temporal Truth fields on edges to tell downstream RAG/planning
 * which relationships are current, stale, expired, or superseded at a given
 * instant. No new graph table is created.
 */
class TimeAwareWorldModelService
{
    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function snapshot(array $input = []): array
    {
        $now = ($input['at'] ?? $input['now'] ?? null) instanceof CarbonImmutable
            ? ($input['at'] ?? $input['now'])
            : CarbonImmutable::now();
        $worldModelId = $this->stringOrNull($input['world_model_id'] ?? null);
        $flowId = $this->stringOrNull($input['flow_id'] ?? null);
        $pathContains = $this->stringOrNull($input['path_contains'] ?? null);
        $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));

        if (! $this->tablesReady()) {
            return $this->blocked($now, 'world_model_tables_missing', $worldModelId);
        }

        $model = $this->resolveModel($worldModelId);
        if (! $model) {
            return $this->blocked($now, 'world_model_not_found', $worldModelId);
        }

        $nodes = $this->nodes($model, $flowId, $pathContains, $limit);
        $nodeIds = array_keys($nodes);
        $currentEdges = $this->edges($model, $nodeIds, $now, 'current', $limit);
        $expiredEdges = $this->edges($model, $nodeIds, $now, 'expired', $limit);
        $staleEdges = $this->edges($model, $nodeIds, $now, 'stale', $limit);
        $supersededEdges = $this->edges($model, $nodeIds, $now, 'superseded', $limit);

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::TIME_AWARE_WORLD_MODEL_SCHEMA_VERSION,
            'status' => self::STATUS_READY,
            'generated_at' => $now->toJSON(),
            'world_model' => [
                'id' => (string) $model->id,
                'model_id' => (string) $model->model_id,
                'scope' => (string) $model->scope,
                'status' => (string) $model->status,
                'model_hash' => (string) $model->model_hash,
            ],
            'query' => [
                'at' => $now->toJSON(),
                'world_model_id' => $worldModelId,
                'flow_id' => $flowId,
                'path_contains' => $pathContains,
                'limit' => $limit,
            ],
            'summary' => [
                'nodes_considered' => count($nodes),
                'current_edges' => count($currentEdges),
                'expired_edges' => count($expiredEdges),
                'stale_edges' => count($staleEdges),
                'superseded_edges' => count($supersededEdges),
                'legacy_current_edges' => count(array_filter($currentEdges, fn (array $edge): bool => (bool) ($edge['legacy_temporal_truth'] ?? false))),
            ],
            'nodes' => array_values($nodes),
            'edges' => [
                'current' => $currentEdges,
                'expired' => $expiredEdges,
                'stale' => $staleEdges,
                'superseded' => $supersededEdges,
            ],
            'freshness_policy' => [
                'planner_must_ignore_expired_edges' => true,
                'planner_must_recheck_stale_edges' => true,
                'legacy_null_temporal_fields_are_current' => true,
                'benchmark_not_run' => true,
            ],
            'claim_policy' => [
                'read_only' => true,
                'does_not_rebuild_world_model' => true,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
            ],
        ];
        $payload['snapshot_hash'] = $this->hashSnapshot($payload);

        return $payload;
    }

    private function tablesReady(): bool
    {
        return DatabaseTableAvailability::all([
            'ai_codebase_world_models',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_model_edges',
        ]);
    }

    private function resolveModel(?string $worldModelId): ?AiCodebaseWorldModel
    {
        $query = AiCodebaseWorldModel::query();
        if ($worldModelId !== null) {
            $query->where('model_id', $worldModelId);
            if (Str::isUuid($worldModelId)) {
                $query->orWhere('id', $worldModelId);
            }
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        return $query->first();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function nodes(AiCodebaseWorldModel $model, ?string $flowId, ?string $pathContains, int $limit): array
    {
        $query = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->orderBy('node_id')
            ->limit($limit);
        if ($flowId !== null) {
            $query->where('flow_id', $flowId);
        }
        if ($pathContains !== null) {
            $query->where('path', 'like', '%'.$pathContains.'%');
        }

        $nodes = [];
        foreach ($query->get() as $node) {
            $nodes[(string) $node->node_id] = [
                'node_id' => (string) $node->node_id,
                'node_type' => (string) $node->node_type,
                'path' => $node->path,
                'flow_id' => $node->flow_id,
                'capabilities' => array_values((array) ($node->capabilities ?? [])),
                'risks' => array_values((array) ($node->risks ?? [])),
            ];
        }

        return $nodes;
    }

    /**
     * @param  array<int,string>  $nodeIds
     * @return array<int,array<string,mixed>>
     */
    private function edges(AiCodebaseWorldModel $model, array $nodeIds, CarbonImmutable $now, string $bucket, int $limit): array
    {
        $query = AiCodebaseWorldModelEdge::query()
            ->where('world_model_id', $model->id)
            ->orderBy('from_node_id')
            ->orderBy('to_node_id')
            ->orderBy('edge_type')
            ->limit($limit);

        if ($nodeIds !== []) {
            $query->where(function ($q) use ($nodeIds): void {
                $q->whereIn('from_node_id', $nodeIds)->orWhereIn('to_node_id', $nodeIds);
            });
        }

        match ($bucket) {
            'current' => $query->current($now),
            'stale' => $query->stale($now),
            'superseded' => $query->superseded(),
            'expired' => $query->whereNotNull('valid_until')->where('valid_until', '<=', $now),
            default => null,
        };

        return $query->get()
            ->map(fn (AiCodebaseWorldModelEdge $edge): array => $this->edge($edge, $bucket))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function edge(AiCodebaseWorldModelEdge $edge, string $bucket): array
    {
        return [
            'id' => (string) $edge->id,
            'from_node_id' => (string) $edge->from_node_id,
            'to_node_id' => (string) $edge->to_node_id,
            'edge_type' => (string) $edge->edge_type,
            'bucket' => $bucket,
            'valid_from' => $edge->valid_from?->toJSON(),
            'valid_until' => $edge->valid_until?->toJSON(),
            'stale_after' => $edge->stale_after?->toJSON(),
            'superseded_by' => $edge->superseded_by,
            'source_hash' => $edge->source_hash,
            'authority_level' => $edge->authority_level,
            'legacy_temporal_truth' => $edge->valid_from === null
                && $edge->valid_until === null
                && $edge->stale_after === null
                && $edge->superseded_by === null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(CarbonImmutable $now, string $reason, ?string $worldModelId): array
    {
        $payload = [
            'schema_version' => AtlasLongHorizonCanon::TIME_AWARE_WORLD_MODEL_SCHEMA_VERSION,
            'status' => self::STATUS_BLOCKED,
            'generated_at' => $now->toJSON(),
            'world_model_id' => $worldModelId,
            'blockers' => [$reason],
            'claim_policy' => [
                'read_only' => true,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
            ],
        ];
        $payload['snapshot_hash'] = $this->hashSnapshot($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashSnapshot(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
