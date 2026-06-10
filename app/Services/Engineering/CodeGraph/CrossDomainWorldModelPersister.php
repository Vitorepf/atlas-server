<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M-8 Cross-Domain Graph world-model persistence (AP-814, Fase-3).
 *
 * Persists an already-assembled cross-domain graph (nodes + edges in the canonical
 * shape produced by {@see CrossDomainGraphIngestionService::gather()}) into the
 * EXISTING world-model tables (`ai_codebase_world_models` + `_nodes` + `_edges`) so
 * the same graph the cross-domain surfaces read is ALSO visible to the generic
 * world-model readers — `WorldModelGraphRanker` and the MCP traversal tools — without
 * a parallel store. Reuse, not recreate.
 *
 * Single STABLE world model (`model_id = 'cross-domain'`, `scope = 'cross-domain'`):
 * a rebuild updates that row and replaces its nodes/edges wholesale, so re-running is
 * idempotent (no duplicate models, no accumulating rows). Strictly additive to the
 * world-model space — it never touches the symbol/module models that already live
 * there (different scope, different model_id).
 *
 * Pure write-of-given-data: deterministic, no provider, no python. Availability-guarded
 * and FAIL-OPEN — if the world-model tables are absent it returns a
 * zero-count result instead of throwing, so an environment without the autonomous
 * engineering migrations degrades quietly rather than fatally.
 */
final class CrossDomainWorldModelPersister
{
    public const SCHEMA = 'atlas.code_graph.cross_domain.world_model.v1';

    /** Stable single-model identity — a rebuild reuses (not appends to) this. */
    public const MODEL_ID = 'cross-domain';

    public const SCOPE = 'cross-domain';

    public const NODE_TYPE_FALLBACK = 'domain';

    public const EDGE_TYPE_FALLBACK = 'cross_domain_edge';

    private const MODELS_TABLE = 'ai_codebase_world_models';

    private const NODES_TABLE = 'ai_codebase_world_model_nodes';

    private const EDGES_TABLE = 'ai_codebase_world_model_edges';

    private const CHUNK = 1000;

    /**
     * Persist the assembled graph into the cross-domain world model (idempotent rebuild).
     *
     * @param  list<array<string,mixed>>  $nodes  canonical nodes ({node_id,node_type,label,metadata})
     * @param  list<array<string,mixed>>  $edges  canonical edges ({from_node_id,to_node_id,edge_type,metadata,...})
     * @param  array<string,mixed>  $opts  optional {scope?:string, model_id?:string}
     * @return array{schema_version:string, world_model_id:?string, model_id:string, node_count:int, edge_count:int}
     */
    public function persist(array $nodes, array $edges, array $opts = []): array
    {
        $modelId = $this->stringOpt($opts, 'model_id', self::MODEL_ID);
        $scope = $this->stringOpt($opts, 'scope', self::SCOPE);

        $empty = [
            'schema_version' => self::SCHEMA,
            'world_model_id' => null,
            'model_id' => $modelId,
            'node_count' => 0,
            'edge_count' => 0,
        ];

        if (! $this->hasWorldModelTables()) {
            return $empty;
        }

        try {
            $model = $this->upsertModel($modelId, $scope, count($nodes), count($edges));

            // Idempotent rebuild: drop the prior nodes/edges of THIS model first so a
            // re-run replaces rather than duplicates.
            DB::table(self::NODES_TABLE)->where('world_model_id', $model->id)->delete();
            DB::table(self::EDGES_TABLE)->where('world_model_id', $model->id)->delete();

            $nodeCount = $this->insertNodes($model, $nodes);
            $edgeCount = $this->insertEdges($model, $edges);

            return [
                'schema_version' => self::SCHEMA,
                'world_model_id' => (string) $model->id,
                'model_id' => $modelId,
                'node_count' => $nodeCount,
                'edge_count' => $edgeCount,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * Create or refresh the single stable cross-domain world model. Keyed on the
     * stable model_id so a rebuild updates the same row (model_hash refreshed to a
     * content-derived value).
     */
    private function upsertModel(string $modelId, string $scope, int $nodeCount, int $edgeCount): AiCodebaseWorldModel
    {
        return AiCodebaseWorldModel::query()->updateOrCreate(
            ['model_id' => $modelId],
            [
                'goal_record_id' => null,
                'scope' => $scope,
                'status' => 'built',
                'capabilities' => ['cross_domain', 'graph'],
                'risks' => [],
                'receipt' => [
                    'source' => 'atlas:code-graph:cross-domain (world-model persist)',
                    'schema_version' => self::SCHEMA,
                    'node_count' => $nodeCount,
                    'edge_count' => $edgeCount,
                ],
                'model_hash' => hash('sha256', self::SCHEMA.':'.$modelId.':'.$nodeCount.':'.$edgeCount),
            ],
        );
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     */
    private function insertNodes(AiCodebaseWorldModel $model, array $nodes): int
    {
        $now = now();
        $count = 0;
        foreach (array_chunk($nodes, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $nodeId = $this->str($node['node_id'] ?? null);
                if ($nodeId === '') {
                    continue;
                }
                $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
                // Keep the canonical label discoverable inside metadata (the world-model
                // node table has no dedicated label column).
                if (! array_key_exists('label', $metadata) && isset($node['label'])) {
                    $metadata['label'] = $this->str($node['label']);
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'world_model_id' => $model->id,
                    'node_id' => $nodeId,
                    'node_type' => $this->str($node['node_type'] ?? null) ?: self::NODE_TYPE_FALLBACK,
                    'path' => null,
                    'flow_id' => null,
                    'capabilities' => json_encode([]),
                    'risks' => json_encode([]),
                    'metadata' => json_encode($metadata),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table(self::NODES_TABLE)->insert($rows);
                $count += count($rows);
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string,mixed>>  $edges
     */
    private function insertEdges(AiCodebaseWorldModel $model, array $edges): int
    {
        $now = now();
        $count = 0;
        foreach (array_chunk($edges, self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $edge) {
                if (! is_array($edge)) {
                    continue;
                }
                $from = $this->str($edge['from_node_id'] ?? null);
                $to = $this->str($edge['to_node_id'] ?? null);
                if ($from === '' || $to === '') {
                    continue;
                }
                $metadata = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
                // Preserve the canonical confidence on the persisted edge metadata so the
                // generic edge table (no confidence columns) keeps the EXTRACTED provenance.
                if (! array_key_exists('confidence', $metadata) && isset($edge['confidence'])) {
                    $metadata['confidence'] = $this->str($edge['confidence']);
                }
                if (! array_key_exists('confidence_score', $metadata) && isset($edge['confidence_score'])) {
                    $metadata['confidence_score'] = (float) $edge['confidence_score'];
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'world_model_id' => $model->id,
                    'from_node_id' => $from,
                    'to_node_id' => $to,
                    'edge_type' => $this->str($edge['edge_type'] ?? null) ?: self::EDGE_TYPE_FALLBACK,
                    'metadata' => json_encode($metadata),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table(self::EDGES_TABLE)->insert($rows);
                $count += count($rows);
            }
        }

        return $count;
    }

    private function hasWorldModelTables(): bool
    {
        return DatabaseTableAvailability::all([
            self::MODELS_TABLE,
            self::NODES_TABLE,
            self::EDGES_TABLE,
        ]);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function stringOpt(array $opts, string $key, string $default): string
    {
        $value = $opts[$key] ?? null;
        if (! is_string($value)) {
            return $default;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? $default : $trimmed;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
