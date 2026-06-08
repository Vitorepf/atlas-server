<?php

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AtlasEngineeringCodeModule;
use Illuminate\Support\Facades\DB;

/**
 * Persistence adapter for the AP-811 P0 code graph.
 *
 * Reads the code-intelligence read-model
 * ({@see \App\Services\Engineering\EngineeringCodeIntelligenceService} —
 * the `atlas_engineering_code_file_snapshots` table), feeds the pure keystone
 * {@see CodeGraphEdgeResolver}, and upserts the resolved, confidence-graded
 * edges into `ai_codebase_world_model_edges` for a given
 * {@see \App\Models\AiCodebaseWorldModel}.
 *
 * Strictly ADDITIVE and GATED by config('atlas.code_graph.real_edges'):
 *  - When the flag is OFF (the safe default) build() early-returns a
 *    {status:'disabled'} summary and writes nothing — fixtures
 *    (AtlasAutonomousEngineeringService::buildWorldModel) stay canonical.
 *  - When ON, real edges are upserted keyed on
 *    (world_model_id, from_node_id, to_node_id, edge_type) and tagged with
 *    metadata.resolver so they are distinguishable from fixtures. Fixture
 *    edges are never deleted.
 *
 * DB-touching logic is kept thin; pure mapping lives in small private methods.
 */
class CodeGraphEdgeBuilder
{
    public const SCHEMA = 'atlas.code_graph.persisted_edges.v1';

    public const STATUS_DISABLED = 'disabled';
    public const STATUS_WRITTEN = 'written';

    /**
     * Build and persist the real code-graph edges for a world model.
     *
     * @return array{schema_version:string, status:string, world_model_id:string, edges_written:int, stats:array<string,int>}
     */
    public function build(AiCodebaseWorldModel $model): array
    {
        if (! (bool) config('atlas.code_graph.real_edges')) {
            return [
                'schema_version' => self::SCHEMA,
                'status' => self::STATUS_DISABLED,
                'world_model_id' => (string) $model->id,
                'edges_written' => 0,
                'stats' => [],
            ];
        }

        $fileRecords = $this->loadFileRecords();
        $nodeIdForModule = $this->makeNodeIdForModuleResolver($model);

        $resolved = (new CodeGraphEdgeResolver)->resolve($fileRecords, $nodeIdForModule);

        $maxEdges = (int) config('atlas.code_graph.max_edges', 200000);
        $edges = $resolved['edges'] ?? [];
        if ($maxEdges > 0 && count($edges) > $maxEdges) {
            $edges = array_slice($edges, 0, $maxEdges);
        }

        $written = $this->upsertEdges($model, $edges);

        return [
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_WRITTEN,
            'world_model_id' => (string) $model->id,
            'edges_written' => $written,
            'stats' => $resolved['stats'] ?? [],
        ];
    }

    /**
     * Build the resolver input from the active file-snapshot read-model.
     *
     * @return array<int,array{file_path:string, module_slug:string, relations:array<string,mixed>}>
     */
    private function loadFileRecords(): array
    {
        $records = [];

        DB::table('atlas_engineering_code_file_snapshots')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->select(['file_path', 'module_slug', 'relations_json'])
            ->orderBy('file_path')
            ->chunk(500, function ($rows) use (&$records): void {
                foreach ($rows as $row) {
                    $records[] = [
                        'file_path' => (string) $row->file_path,
                        'module_slug' => (string) $row->module_slug,
                        'relations' => $this->decodeRelations($row->relations_json ?? null),
                    ];
                }
            });

        return $records;
    }

    /**
     * Map a module slug to its world-model node_id ("node:<root_path>") ONLY if
     * such a node actually exists for this model (existence gate); else null.
     *
     * @return callable(string):?string
     */
    private function makeNodeIdForModuleResolver(AiCodebaseWorldModel $model): callable
    {
        $rootPathBySlug = $this->moduleRootPaths();
        $existingNodeIds = $this->existingNodeIds($model);

        /** @var array<string,?string> $cache */
        $cache = [];

        return static function (string $moduleSlug) use ($rootPathBySlug, $existingNodeIds, &$cache): ?string {
            if (array_key_exists($moduleSlug, $cache)) {
                return $cache[$moduleSlug];
            }

            $rootPath = $rootPathBySlug[$moduleSlug] ?? null;
            $nodeId = $rootPath !== null ? 'node:'.$rootPath : null;

            $resolved = ($nodeId !== null && isset($existingNodeIds[$nodeId])) ? $nodeId : null;
            $cache[$moduleSlug] = $resolved;

            return $resolved;
        };
    }

    /**
     * slug => root_path for every known code-intelligence module.
     *
     * @return array<string,string>
     */
    private function moduleRootPaths(): array
    {
        return AtlasEngineeringCodeModule::query()
            ->whereNotNull('root_path')
            ->pluck('root_path', 'slug')
            ->map(static fn (mixed $path): string => (string) $path)
            ->all();
    }

    /**
     * Set of node_ids present for this world model (used as the existence gate).
     *
     * @return array<string,true>
     */
    private function existingNodeIds(AiCodebaseWorldModel $model): array
    {
        $ids = AiCodebaseWorldModelNode::query()
            ->where('world_model_id', $model->id)
            ->pluck('node_id')
            ->all();

        $set = [];
        foreach ($ids as $id) {
            $set[(string) $id] = true;
        }

        return $set;
    }

    /**
     * Additively upsert resolved edges. Keyed on the natural edge identity so a
     * re-run refreshes metadata in place and never duplicates or deletes
     * fixture edges.
     *
     * @param  array<int,array<string,mixed>>  $edges
     */
    private function upsertEdges(AiCodebaseWorldModel $model, array $edges): int
    {
        $written = 0;

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $fromNodeId = $this->stringOrNull($edge['from_node_id'] ?? null);
            $toNodeId = $this->stringOrNull($edge['to_node_id'] ?? null);
            $edgeType = $this->stringOrNull($edge['edge_type'] ?? null);
            if ($fromNodeId === null || $toNodeId === null || $edgeType === null) {
                continue;
            }

            $metadata = is_array($edge['metadata'] ?? null) ? $edge['metadata'] : [];
            // Guarantee the resolver tag is present so real edges are always
            // distinguishable from fixture edges.
            $metadata['resolver'] ??= CodeGraphEdgeResolver::SCHEMA;

            AiCodebaseWorldModelEdge::query()->updateOrCreate(
                [
                    'world_model_id' => $model->id,
                    'from_node_id' => $fromNodeId,
                    'to_node_id' => $toNodeId,
                    'edge_type' => $edgeType,
                ],
                [
                    'metadata' => $metadata,
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeRelations(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
