<?php

namespace App\Services\Engineering\CodeGraph;

use App\Models\AiCodebaseWorldModel;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
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

        $resolved = $this->resolveEdges($symbols, $relations);

        $edges = $resolved['edges'] ?? [];

        // AP-815 Tier-1 fusion: compute method->method call edges once (flag-gated; null
        // when off). Merge into the persisted graph ONLY when call_edges_merge is ON —
        // otherwise report-only (yield stats in the receipt, graph untouched). Call-edge
        // shape matches the symbol edges exactly, so insertEdges persists them unchanged.
        $callEdges = $this->computeCallEdges($symbols);
        if ($callEdges !== null && (bool) config('atlas.code_graph.call_edges_merge', false)) {
            $edges = array_merge($edges, $callEdges['edges']);
        }

        $maxEdges = (int) config('atlas.code_graph.max_edges', 200000);
        if ($maxEdges > 0 && count($edges) > $maxEdges) {
            $edges = array_slice($edges, 0, $maxEdges);
        }

        $model = $this->freshModel($workspaceId, count($resolved['symbol_node_ids'] ?? []), count($edges));

        $nodeCount = $this->insertNodes($model, $resolved['symbol_node_ids'] ?? []);
        $edgeCount = $this->insertEdges($model, $edges);

        return array_merge([
            'schema_version' => self::SCHEMA,
            'status' => self::STATUS_WRITTEN,
            'world_model_id' => (string) $model->id,
            'workspace_id' => $workspaceId,
            'symbol_nodes' => $nodeCount,
            'edges_written' => $edgeCount,
            'stats' => $resolved['stats'] ?? [],
        ], $this->postBuildAudit($workspaceId, $resolved['symbol_node_ids'] ?? [], $edges), $this->callEdgeReceipt($callEdges));
    }

    /**
     * AP-815 FUSION beachhead — wire the built-but-unwired advanced code-graph capabilities
     * onto the LIVE symbol graph: structural HealthAuditor (Q-1) + verifiable snapshot
     * IntegrityHasher (G-8). Flag-gated (default OFF): when OFF returns [] so build()'s
     * receipt is byte-identical to before; when ON adds 'health' + 'integrity' keys.
     * Additive — never mutates the persisted graph, only enriches the build receipt.
     *
     * @param  array<int,string>  $nodeIds
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,mixed>
     */
    private function postBuildAudit(string $workspaceId, array $nodeIds, array $edges): array
    {
        if (! (bool) config('atlas.code_graph.post_build_audit', false)) {
            return [];
        }

        return [
            'health' => (new CodeGraphHealthAuditor)->audit($nodeIds, $edges),
            'integrity' => (new CodeGraphIntegrityHasher)->snapshot($workspaceId, $nodeIds, $edges),
            // Q-4 edge-level anti-over-claim: report the inferred/over-claimed edge stats
            // WITHOUT mutating the persisted graph (report-only — the filtered edge set is
            // intentionally discarded here; a future slice may gate actual filtering).
            'edge_quality' => (new CodeGraphInferredGuard)->apply($edges)['stats'] ?? [],
        ];
    }

    /**
     * AP-815 Tier-1 fusion — compute method->method CALL edges from the workspace source
     * via the now-live `callgraph` op + the pure {@see CodeGraphCallResolver}. Runs ONLY
     * when a call-edge flag is on (report OR merge); returns null otherwise so build() does
     * no extra work and stays byte-identical. Any runtime block/failure or unreadable
     * source returns a well-formed result with EMPTY edges + a status note — never throws,
     * never breaks build(). The caller decides whether to merge the edges (call_edges_merge)
     * or only report their stats (call_edges); the resolved edge shape matches the symbol
     * edges exactly ({from_node_id,to_node_id,edge_type,metadata}) so insertEdges persists
     * them unchanged and the CallResolver nodeId scheme collides with the symbol nodes.
     *
     * ponytail: reads every source file once per build — bounded by call_edges_max_files.
     * The perf-serious path should feed off the incremental reindex (file_hash/mtime), not
     * this full re-read; this pass is opt-in, gated OFF by default.
     *
     * @param  array<int,array{name:string,type:string,file_path:string}>  $symbols
     * @return array{edges:array<int,array<string,mixed>>, stats:array<string,mixed>}|null
     */
    private function computeCallEdges(array $symbols): ?array
    {
        if (! (bool) config('atlas.code_graph.call_edges', false)
            && ! (bool) config('atlas.code_graph.call_edges_merge', false)) {
            return null;
        }

        $files = $this->loadCallFiles($symbols);
        if ($files === []) {
            return ['edges' => [], 'stats' => ['files' => 0, 'note' => 'no readable source']];
        }

        // 'typed' uses the receiver-type-aware extractor (EXTRACTED 1.0, more precise);
        // 'generic' (default) uses the heuristic short-name extractor (INFERRED 0.7). Only
        // ONE runs — the two produce overlapping 'calls' edges, so they are alternatives,
        // never merged together (that would double-count).
        $mode = config('atlas.code_graph.call_edges_mode') === 'typed' ? 'typed' : 'generic';
        $op = $mode === 'typed' ? 'typed_callgraph' : 'callgraph';

        $input = ['files' => $files];
        $receipt = CodeGraphRuntimeInvoker::mintReceipt($op, $input, 'atlas-kernel:code-graph-call-edges');
        $result = app(CodeGraphRuntimeInvoker::class)->invoke($op, $input, [], $receipt);

        if (($result['status'] ?? null) !== CodeGraphRuntimeInvoker::STATUS_SUCCEEDED) {
            return ['edges' => [], 'stats' => ['files' => count($files), 'mode' => $mode, 'status' => 'runtime_unavailable']];
        }

        $payload = $result['artifacts'][0]['result'] ?? [];
        $calls = is_array($payload['calls'] ?? null) ? $payload['calls'] : null;
        if ($calls === null) {
            return ['edges' => [], 'stats' => ['files' => count($files), 'mode' => $mode, 'status' => 'malformed_result']];
        }

        if ($mode === 'typed') {
            $imports = is_array($payload['imports_by_file'] ?? null) ? $payload['imports_by_file'] : [];
            $resolved = (new CodeGraphTypedCallResolver)->resolve($calls, $imports, $this->buildClassIndex($symbols));
        } else {
            $resolved = (new CodeGraphCallResolver)->resolveCalls($calls, $this->buildMethodIndex($symbols));
        }

        return [
            'edges' => is_array($resolved['edges'] ?? null) ? $resolved['edges'] : [],
            'stats' => array_merge(['files' => count($files), 'mode' => $mode], $resolved['stats'] ?? []),
        ];
    }

    /**
     * Format the call-edge computation for the build receipt: a 'call_edges' stats key
     * (report-only visibility), flagged with whether the edges were actually merged into
     * the persisted graph. Returns [] when call edges were not computed (flags off) so
     * the receipt is byte-identical to before this fusion.
     *
     * @param  array{edges:array<int,array<string,mixed>>, stats:array<string,mixed>}|null  $callEdges
     * @return array<string,mixed>
     */
    private function callEdgeReceipt(?array $callEdges): array
    {
        if ($callEdges === null) {
            return [];
        }

        return ['call_edges' => array_merge($callEdges['stats'], [
            'merged' => (bool) config('atlas.code_graph.call_edges_merge', false),
        ])];
    }

    /**
     * Method-name index for {@see CodeGraphCallResolver}: short method name -> list of
     * defining method FQNs, from the loaded symbols (type 'method', name shaped
     * "Namespace\Class::method" per the resolver's fqn() normalization). Pure, no IO.
     *
     * @param  array<int,array{name:string,type:string,file_path:string}>  $symbols
     * @return array<string,array<int,string>>
     */
    private function buildMethodIndex(array $symbols): array
    {
        $index = [];
        foreach ($symbols as $sym) {
            if (($sym['type'] ?? null) !== 'method') {
                continue;
            }
            $name = ltrim((string) ($sym['name'] ?? ''), '\\');
            $pos = strrpos($name, '::');
            if ($pos === false) {
                continue;
            }
            $short = substr($name, $pos + 2);
            if ($short !== '') {
                $index[$short][] = $name;
            }
        }

        return $index;
    }

    /**
     * Class index for {@see CodeGraphTypedCallResolver}: class FQN -> {parent, methods,
     * namespace}, from the loaded symbols (class/interface/trait/enum + their methods).
     * `parent` (extends) is not in the symbol read-model, so it stays null — the resolver
     * then skips only INHERITED-method resolution; self/static/direct-type calls still
     * resolve, which covers the common case ($this->/self::/static::). Pure, no IO.
     *
     * @param  array<int,array{name:string,type:string,file_path:string}>  $symbols
     * @return array<string,array{parent:?string, methods:array<int,string>, namespace:string}>
     */
    private function buildClassIndex(array $symbols): array
    {
        $namespaceOf = static function (string $fqn): string {
            $pos = strrpos($fqn, '\\');

            return $pos === false ? '' : substr($fqn, 0, $pos);
        };

        $classes = [];
        foreach ($symbols as $sym) {
            $type = $sym['type'] ?? null;
            $name = ltrim((string) ($sym['name'] ?? ''), '\\');
            if ($name === '') {
                continue;
            }
            if (in_array($type, self::NODE_TYPES, true)) {
                $classes[$name] ??= ['parent' => null, 'methods' => [], 'namespace' => $namespaceOf($name)];
            } elseif ($type === 'method') {
                $pos = strrpos($name, '::');
                if ($pos === false) {
                    continue;
                }
                $class = substr($name, 0, $pos);
                $short = substr($name, $pos + 2);
                $classes[$class] ??= ['parent' => null, 'methods' => [], 'namespace' => $namespaceOf($class)];
                if ($short !== '') {
                    $classes[$class]['methods'][] = $short;
                }
            }
        }

        return $classes;
    }

    /**
     * Read the unique source files referenced by the symbols into the callgraph op's
     * {path,language,content} shape. Bounded by call_edges_max_files; each read guarded;
     * unknown extensions skipped. Repo-relative paths resolve under base_path().
     *
     * @param  array<int,array{name:string,type:string,file_path:string}>  $symbols
     * @return array<int,array{path:string,language:string,content:string}>
     */
    private function loadCallFiles(array $symbols): array
    {
        $langByExt = [
            'php' => 'php', 'py' => 'python', 'js' => 'javascript', 'ts' => 'typescript',
            'go' => 'go', 'rb' => 'ruby', 'rs' => 'rust', 'java' => 'java',
        ];
        $max = (int) config('atlas.code_graph.call_edges_max_files', 5000);

        $seen = [];
        $files = [];
        foreach ($symbols as $sym) {
            $rel = (string) ($sym['file_path'] ?? '');
            if ($rel === '' || isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;

            $lang = $langByExt[strtolower(pathinfo($rel, PATHINFO_EXTENSION))] ?? null;
            if ($lang === null) {
                continue;
            }

            $abs = str_starts_with($rel, '/') ? $rel : base_path($rel);
            if (! is_file($abs) || ! is_readable($abs)) {
                continue;
            }
            $content = @file_get_contents($abs);
            if (! is_string($content) || $content === '') {
                continue;
            }

            $files[] = ['path' => $rel, 'language' => $lang, 'content' => $content];
            if (count($files) >= $max) {
                break;
            }
        }

        return $files;
    }

    /**
     * Resolve symbol->symbol edges. The PHP {@see CodeGraphSymbolResolver} is the
     * DEFAULT and the ONLY path unless the operator opts in.
     *
     * Honesty note (AP-815 C5): PHP stays the default because (a) it is the proven
     * 99.5%-precision path and (b) shipping ~100k symbols across the PHP->python
     * boundary carries IPC/serialization overhead that may negate any compute win
     * — so config('atlas.code_graph.python_resolve') is an OPT-IN to MEASURE, not a
     * default win. When that flag is OFF this method is byte-identical to calling
     * the PHP resolver directly. When ON (AND real_edges is on AND a Decision
     * Receipt mints), it resolves via the python_ai_data 'resolve_edges' op, which
     * faithfully mirrors the PHP resolver; if the op BLOCKS or FAILS for any reason
     * it FALLS BACK to the PHP resolver so the build never regresses.
     *
     * @param  array<int,array{name:string,type:string,file_path:string}>  $symbols
     * @param  array<int,array{file_path:string,symbol:string,kind:string}>  $relations
     * @return array{schema_version:string, symbol_node_ids:array<int,string>, edges:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    private function resolveEdges(array $symbols, array $relations): array
    {
        $php = static fn (): array => (new CodeGraphSymbolResolver)->resolve($symbols, $relations);

        if (! (bool) config('atlas.code_graph.python_resolve', false)) {
            return $php(); // DEFAULT path — unchanged, byte-identical to before C5.
        }

        $input = ['symbols' => $symbols, 'relations' => $relations];
        $receipt = CodeGraphRuntimeInvoker::mintReceipt('resolve_edges', $input, 'atlas-kernel:code-graph-symbol-build');

        $result = app(CodeGraphRuntimeInvoker::class)->invoke('resolve_edges', $input, [], $receipt);

        // Any non-success (flag/receipt/python-binary block, or runtime failure)
        // falls back to the proven PHP resolver — never regress the build.
        if (($result['status'] ?? null) !== CodeGraphRuntimeInvoker::STATUS_SUCCEEDED) {
            return $php();
        }

        $payload = $result['artifacts'][0]['result'] ?? null;
        if (! is_array($payload) || ! is_array($payload['edges'] ?? null)) {
            return $php(); // defensive: malformed runtime payload -> PHP fallback.
        }

        return [
            'schema_version' => is_string($payload['schema_version'] ?? null) ? $payload['schema_version'] : CodeGraphSymbolResolver::SCHEMA,
            'symbol_node_ids' => array_values(array_filter($payload['symbol_node_ids'] ?? [], 'is_string')),
            'edges' => array_values(array_filter($payload['edges'], 'is_array')),
            'stats' => is_array($payload['stats'] ?? null) ? $payload['stats'] : [],
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
        return $this->workspaceColumnCache[$table] ??= DatabaseTableAvailability::hasColumn($table, 'workspace_id');
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
