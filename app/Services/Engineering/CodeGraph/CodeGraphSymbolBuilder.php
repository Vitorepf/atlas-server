<?php

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Symbol-level persistence for the code graph (AP-811 granularity upgrade).
 *
 * Loads the real class/interface/trait/enum symbols + per-file relations from the
 * Code Intelligence read-model, runs the pure {@see CodeGraphSymbolResolver} to
 * get symbol->symbol (FQN) edges, and persists them into a FRESH world model so
 * the graph is queryable at symbol granularity — not just module.
 *
 * Gated by config('atlas.code_graph.real_edges'); strictly additive (its own
 * fresh world model, never touches module-level models or fixtures).
 */
class CodeGraphSymbolBuilder
{
    public const SCHEMA = 'atlas.code_graph.symbol_build.v1';

    public const STATUS_DISABLED = 'disabled';
    public const STATUS_WRITTEN = 'written';

    private const NODE_TYPES = ['class', 'interface', 'trait', 'enum'];

    /** @var array<string,bool> AP-815 W-2: memoized "is this table workspace-keyed?" checks. */
    private array $workspaceColumnCache = [];

    /**
     * AP-815 W-2: build the symbol graph for a specific workspace (defaults to the
     * primary). Reads only that workspace's symbols/relations and writes a world model
     * scoped "<workspace_id>-symbols" — so a second project gets its OWN symbol graph.
     *
     * @return array{schema_version:string, status:string, world_model_id:?string, workspace_id:?string, symbol_nodes:int, edges_written:int, stats:array<string,int>}
     */
    public function build(?string $workspaceId = null): array
    {
        if (! (bool) config('atlas.code_graph.real_edges')) {
            return ['schema_version' => self::SCHEMA, 'status' => self::STATUS_DISABLED, 'world_model_id' => null, 'workspace_id' => null, 'symbol_nodes' => 0, 'edges_written' => 0, 'stats' => []];
        }

        $workspaceId = $this->resolveWorkspaceId($workspaceId);

        $symbols = $this->loadSymbols($workspaceId);
        $relations = $this->loadRelations($workspaceId);

        $resolved = (new CodeGraphSymbolResolver)->resolve($symbols, $relations);

        $maxEdges = (int) config('atlas.code_graph.max_edges', 200000);
        $edges = $resolved['edges'] ?? [];
        if ($maxEdges > 0 && count($edges) > $maxEdges) {
            $edges = array_slice($edges, 0, $maxEdges);
        }

        $model = $this->freshModel($workspaceId, count($resolved['symbol_node_ids'] ?? []), count($edges));

        $nodeCount = $this->insertNodes($model, $resolved['symbol_node_ids'] ?? []);
        $edgeCount = $this->insertEdges($model, $edges);

        return [
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_WRITTEN,
            'world_model_id' => (string) $model->id,
            'workspace_id' => $workspaceId,
            'symbol_nodes' => $nodeCount,
            'edges_written' => $edgeCount,
            'stats' => $resolved['stats'] ?? [],
        ];
    }

    private function resolveWorkspaceId(?string $workspaceId): string
    {
        if (is_string($workspaceId) && trim($workspaceId) !== '') {
            return $workspaceId;
        }

        return app(CodeGraphWorkspaceIdentity::class)->default();
    }

    private function workspaceColumn(string $table): bool
    {
        return $this->workspaceColumnCache[$table] ??= Schema::hasColumn($table, 'workspace_id');
    }

    /**
     * @return array<int,array{name:string,type:string,file_path:string}>
     */
    private function loadSymbols(string $workspaceId): array
    {
        $out = [];
        DB::table('atlas_engineering_code_symbols')
            ->whereIn('symbol_type', self::NODE_TYPES)
            ->when($this->workspaceColumn('atlas_engineering_code_symbols'), fn ($q) => $q->where('workspace_id', $workspaceId))
            ->select(['symbol_name', 'symbol_type', 'file_path'])
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$out): void {
                foreach ($rows as $r) {
                    $out[] = ['name' => (string) $r->symbol_name, 'type' => (string) $r->symbol_type, 'file_path' => (string) $r->file_path];
                }
            });

        return $out;
    }

    /**
     * Flatten per-file relations_json into {file_path, symbol, kind} records.
     *
     * @return array<int,array{file_path:string,symbol:string,kind:string}>
     */
    private function loadRelations(string $workspaceId): array
    {
        $out = [];
        DB::table('atlas_engineering_code_file_snapshots')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->when($this->workspaceColumn('atlas_engineering_code_file_snapshots'), fn ($q) => $q->where('workspace_id', $workspaceId))
            ->select(['file_path', 'relations_json'])
            ->orderBy('file_path')
            ->chunk(500, function ($rows) use (&$out): void {
                foreach ($rows as $row) {
                    $file = (string) $row->file_path;
                    $rel = $this->decode($row->relations_json ?? null);
                    foreach (['dependencies', 'symbol_references', 'test_targets'] as $bucket) {
                        foreach (($rel[$bucket] ?? []) as $entry) {
                            if (! is_array($entry)) {
                                continue;
                            }
                            $symbol = $entry['symbol'] ?? null;
                            if (! is_string($symbol) || trim($symbol) === '') {
                                continue;
                            }
                            $out[] = [
                                'file_path' => is_string($entry['file_path'] ?? null) ? (string) $entry['file_path'] : (is_string($entry['test_path'] ?? null) ? (string) $entry['test_path'] : $file),
                                'symbol' => $symbol,
                                'kind' => is_string($entry['kind'] ?? null) ? (string) $entry['kind'] : $bucket,
                            ];
                        }
                    }
                }
            });

        return $out;
    }

    private function freshModel(string $workspaceId, int $nodes, int $edges): AiCodebaseWorldModel
    {
        $token = (string) Str::uuid();

        return AiCodebaseWorldModel::query()->create([
            'goal_record_id' => null,
            'model_id' => 'code-graph-symbols-'.substr(hash('sha256', $token), 0, 18),
            'scope' => $workspaceId.'-symbols',
            'status' => 'built',
            'capabilities' => ['code_graph', 'symbol_level'],
            'risks' => [],
            'receipt' => ['source' => 'atlas:code-graph:build --symbols', 'workspace_id' => $workspaceId, 'symbol_nodes' => $nodes, 'edges' => $edges],
            'model_hash' => hash('sha256', 'code-graph-symbols:'.$token),
        ]);
    }

    /**
     * @param  array<int,string>  $nodeIds
     */
    private function insertNodes(AiCodebaseWorldModel $model, array $nodeIds): int
    {
        $now = now();
        $count = 0;
        foreach (array_chunk($nodeIds, 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $nodeId) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'world_model_id' => $model->id,
                    'node_id' => $nodeId,
                    'node_type' => 'symbol',
                    'path' => null,
                    'metadata' => json_encode(['source' => 'code_graph_symbol']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('ai_codebase_world_model_nodes')->insert($rows);
            $count += count($rows);
        }

        return $count;
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     */
    private function insertEdges(AiCodebaseWorldModel $model, array $edges): int
    {
        $now = now();
        $count = 0;
        foreach (array_chunk($edges, 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $edge) {
                if (! is_array($edge)) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'world_model_id' => $model->id,
                    'from_node_id' => (string) $edge['from_node_id'],
                    'to_node_id' => (string) $edge['to_node_id'],
                    'edge_type' => (string) $edge['edge_type'],
                    'metadata' => json_encode(is_array($edge['metadata'] ?? null) ? $edge['metadata'] : []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('ai_codebase_world_model_edges')->insert($rows);
                $count += count($rows);
            }
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $d = json_decode($value, true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}
