<?php

namespace App\Services\Engineering\CodeGraph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AURG (Atlas Universal Reality Graph) ingestion for the Atlas Code Graph
 * (AP-811/AP-812 M-9 — the reality half of {@see CodeGraphUnifiedView}).
 *
 * The unified view can already stitch the *code* graph (file X imports module Y)
 * to the *reality* graph (docs / memory / evidence relations) — but only if
 * something hands it reality edges in the AURG shape. That ingestion was the
 * missing piece: this gatherer is it. It reads the real Atlas read-model tables
 * and emits reality nodes + edges in the exact
 *   {from_node_id, to_node_id, edge_type, confidence?, confidence_score?, metadata?}
 * shape {@see CodeGraphUnifiedView::unify()} consumes as its `$aurgEdges`
 * argument, so the two layers merge with no adapter glue between them.
 *
 * What it reads (each source wrapped in its own try/catch; a missing table is
 * skipped, never fatal — the gatherer degrades to fewer nodes, never throws):
 *  - docs     : atlas_engineering_knowledge_items (id, title, category,
 *               canonical_path, related_paths_json) -> node `doc:<id>`
 *               type 'document';
 *  - memory   : atlas_memory_entries (id, title) -> node `memory:<id>`
 *               type 'memory_entry';
 *  - evidence : atlas_ledger_events (event_id, event_type) -> node
 *               `evidence:<event_id>` type 'evidence'.
 *
 * Edges: where a doc's canonical/related path points at a code module, a cheap
 * `documents` edge is emitted from the doc node to the corresponding code node
 * id (`node:<path>` — the same id convention {@see CodeGraphEdgeResolver} and
 * {@see CodeGraphDocReconcileBridge} embed). Confidence is INFERRED because the
 * link is derived from a path-prefix signal, not proven at runtime; the unified
 * view's runtime-proven overlay can still light it up later if the path is in
 * the proven set. This is the modest, capped doc->code stitch the brief asks
 * for — not a semantic doc<->doc inference engine.
 *
 * READ-ONLY, read-model. Only SELECTs (DB::table()->...->limit()->get()); no
 * INSERT/UPDATE/DELETE, no migration, no provider, no clock, no decision. It
 * FEEDS the unified view / traversal; it decides nothing about providers,
 * models, domains or policy. Whether the merged edges are *stored* is gated
 * elsewhere behind config `atlas.code_graph.real_edges` (default OFF); this
 * gatherer itself performs no writes regardless of that flag, so it is safe to
 * call read-only at any time.
 *
 * @phpstan-type RealityNode array{node_id:string, node_type:string, label:string}
 * @phpstan-type RealityEdge array{from_node_id:string, to_node_id:string, edge_type:string, confidence:string, confidence_score:float, metadata:array<string,mixed>}
 */
class CodeGraphRealityIngestionService
{
    public const SCHEMA = 'atlas.code_graph.reality.v1';

    public const NODE_DOCUMENT = 'document';
    public const NODE_MEMORY = 'memory_entry';
    public const NODE_EVIDENCE = 'evidence';

    public const EDGE_DOCUMENTS = 'documents';

    /** Confidence for path-derived doc->code edges (not runtime-proven). */
    public const CONFIDENCE_INFERRED = 'INFERRED';

    private const SCORE_INFERRED = 0.5;

    /** Overall node budget — a modest cap so a huge KB cannot flood the graph. */
    private const DEFAULT_MAX_NODES = 5000;

    /**
     * Per-source row caps (each <= the overall budget). Kept small and explicit
     * so one runaway table cannot consume the whole budget before the others run.
     */
    private const MAX_DOCS = 5000;

    private const MAX_MEMORY = 5000;

    private const MAX_EVIDENCE = 5000;

    /**
     * Path prefixes that mark a value as pointing at a *code* module (the only
     * doc paths worth turning into a doc->code edge). Docs that reference
     * `docs/...` or external URLs produce a node but no code edge.
     */
    private const CODE_PATH_PREFIXES = ['app/', 'src/', 'runtimes/', 'config/', 'routes/', 'database/', 'tests/'];

    /**
     * Gather the AURG reality graph as nodes + edges in the unified-view shape.
     *
     * @param  array<string,mixed>  $opts  optional knobs:
     *   - max_nodes (int): overall node budget (default 5000, also clamped by
     *     config atlas.code_graph.max_edges when that is smaller);
     *   - sources (array<int,string>): subset of {'docs','memory','evidence'} to
     *     read (default all three).
     * @return array{schema_version:string, nodes:array<int,array{node_id:string,node_type:string,label:string}>, edges:array<int,array<string,mixed>>, stats:array<string,int>}
     */
    public function gather(array $opts = []): array
    {
        $budget = $this->resolveBudget($opts);
        $sources = $this->resolveSources($opts);

        /** @var array<string,array{node_id:string,node_type:string,label:string}> $nodes */
        $nodes = [];
        /** @var array<string,array<string,mixed>> $edges keyed by from|to|type */
        $edges = [];
        $stats = [
            'docs' => 0,
            'memory' => 0,
            'evidence' => 0,
            'edges' => 0,
            'sources_read' => 0,
            'sources_skipped' => 0,
            'truncated' => 0,
        ];

        if (in_array('docs', $sources, true)) {
            $this->gatherDocs($nodes, $edges, $stats, $budget);
        }
        if (in_array('memory', $sources, true)) {
            $this->gatherMemory($nodes, $stats, $budget);
        }
        if (in_array('evidence', $sources, true)) {
            $this->gatherEvidence($nodes, $stats, $budget);
        }

        $nodeList = array_values($nodes);
        usort(
            $nodeList,
            static fn (array $a, array $b): int => [$a['node_type'], $a['node_id']] <=> [$b['node_type'], $b['node_id']],
        );

        $edgeList = array_values($edges);
        usort(
            $edgeList,
            static fn (array $a, array $b): int => [$a['from_node_id'], $a['to_node_id'], $a['edge_type']]
                <=> [$b['from_node_id'], $b['to_node_id'], $b['edge_type']],
        );

        return [
            'schema_version' => self::SCHEMA,
            'nodes' => $nodeList,
            'edges' => $edgeList,
            'stats' => $stats,
        ];
    }

    /**
     * docs: knowledge items -> 'document' nodes, plus path-derived doc->code edges.
     *
     * @param  array<string,array{node_id:string,node_type:string,label:string}>  $nodes
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,int>  $stats
     */
    private function gatherDocs(array &$nodes, array &$edges, array &$stats, int $budget): void
    {
        $this->readSource('docs', $stats, function () use (&$nodes, &$edges, &$stats, $budget): void {
            if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
                $stats['sources_skipped']++;

                return;
            }
            $stats['sources_read']++;

            // Only the columns we actually project. related_paths_json is a JSON
            // text column; we decode it defensively below.
            $rows = DB::table('atlas_engineering_knowledge_items')
                ->select(['id', 'title', 'category', 'canonical_path', 'related_paths_json'])
                ->orderBy('id')
                ->limit(min($budget, self::MAX_DOCS))
                ->get();

            foreach ($rows as $row) {
                $id = $this->str($row->id ?? null);
                if ($id === null) {
                    continue;
                }
                if (count($nodes) >= $budget) {
                    $stats['truncated'] = 1;
                    break;
                }

                $nodeId = 'doc:'.$id;
                $title = $this->str($row->title ?? null);
                $category = $this->str($row->category ?? null);
                $label = $title ?? ('document '.$id);

                $nodes[$nodeId] = [
                    'node_id' => $nodeId,
                    'node_type' => self::NODE_DOCUMENT,
                    'label' => $this->clampLabel($label),
                ];
                $stats['docs']++;

                // Path-derived doc->code edges: canonical_path + related_paths_json.
                foreach ($this->codePathsForDoc($row) as $codePath) {
                    $codeNodeId = 'node:'.$codePath;
                    $this->addEdge($edges, $stats, $nodeId, $codeNodeId, self::EDGE_DOCUMENTS, [
                        'source' => 'doc_path_signal',
                        'doc_id' => $id,
                        'category' => $category,
                        'code_path' => $codePath,
                    ]);
                }
            }
        });
    }

    /**
     * memory: memory entries -> 'memory_entry' nodes (no edges — kept node-only;
     * memory relations live in their own table and are out of this modest scope).
     *
     * @param  array<string,array{node_id:string,node_type:string,label:string}>  $nodes
     * @param  array<string,int>  $stats
     */
    private function gatherMemory(array &$nodes, array &$stats, int $budget): void
    {
        $this->readSource('memory', $stats, function () use (&$nodes, &$stats, $budget): void {
            if (! Schema::hasTable('atlas_memory_entries')) {
                $stats['sources_skipped']++;

                return;
            }
            $stats['sources_read']++;

            $rows = DB::table('atlas_memory_entries')
                ->select(['id', 'title'])
                ->orderBy('id')
                ->limit(min($budget, self::MAX_MEMORY))
                ->get();

            foreach ($rows as $row) {
                $id = $this->str($row->id ?? null);
                if ($id === null) {
                    continue;
                }
                if (count($nodes) >= $budget) {
                    $stats['truncated'] = 1;
                    break;
                }

                $nodeId = 'memory:'.$id;
                $label = $this->str($row->title ?? null) ?? ('memory '.$id);

                $nodes[$nodeId] = [
                    'node_id' => $nodeId,
                    'node_type' => self::NODE_MEMORY,
                    'label' => $this->clampLabel($label),
                ];
                $stats['memory']++;
            }
        });
    }

    /**
     * evidence: ledger events -> 'evidence' nodes. The ledger table's primary key
     * is `event_id` (not `id`); we read it defensively and fall back to `id` if a
     * variant schema exposes that instead, so a column rename never breaks us.
     *
     * @param  array<string,array{node_id:string,node_type:string,label:string}>  $nodes
     * @param  array<string,int>  $stats
     */
    private function gatherEvidence(array &$nodes, array &$stats, int $budget): void
    {
        $this->readSource('evidence', $stats, function () use (&$nodes, &$stats, $budget): void {
            if (! Schema::hasTable('atlas_ledger_events')) {
                $stats['sources_skipped']++;

                return;
            }
            $stats['sources_read']++;

            // The canonical PK is event_id; tolerate an `id` variant too.
            $idColumn = Schema::hasColumn('atlas_ledger_events', 'event_id')
                ? 'event_id'
                : (Schema::hasColumn('atlas_ledger_events', 'id') ? 'id' : null);
            if ($idColumn === null) {
                $stats['sources_skipped']++;

                return;
            }

            $select = [$idColumn];
            if (Schema::hasColumn('atlas_ledger_events', 'event_type')) {
                $select[] = 'event_type';
            }

            $rows = DB::table('atlas_ledger_events')
                ->select($select)
                ->orderBy($idColumn)
                ->limit(min($budget, self::MAX_EVIDENCE))
                ->get();

            foreach ($rows as $row) {
                $id = $this->str($row->{$idColumn} ?? null);
                if ($id === null) {
                    continue;
                }
                if (count($nodes) >= $budget) {
                    $stats['truncated'] = 1;
                    break;
                }

                $nodeId = 'evidence:'.$id;
                $eventType = $this->str($row->event_type ?? null);
                $label = $eventType !== null ? ($eventType.' '.$id) : ('evidence '.$id);

                $nodes[$nodeId] = [
                    'node_id' => $nodeId,
                    'node_type' => self::NODE_EVIDENCE,
                    'label' => $this->clampLabel($label),
                ];
                $stats['evidence']++;
            }
        });
    }

    /**
     * Run one source closure inside its own try/catch. ANY failure (missing
     * table racing the schema check, a JSON decode quirk, a driver hiccup) is
     * swallowed into a skipped-source tally — the gatherer must degrade, never
     * throw, because it is a read-model that FEEDS callers.
     *
     * @param  array<string,int>  $stats
     */
    private function readSource(string $name, array &$stats, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable) {
            $stats['sources_skipped']++;
        }
    }

    /**
     * Add (or dedup) a reality edge in the unified-view shape. Same
     * strongest-evidence dedup family as the resolvers, but reality edges are
     * single-confidence (INFERRED) so dedup just counts occurrences.
     *
     * @param  array<string,array<string,mixed>>  $edges
     * @param  array<string,int>  $stats
     * @param  array<string,mixed>  $signal
     */
    private function addEdge(array &$edges, array &$stats, string $from, string $to, string $type, array $signal): void
    {
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }
        $key = $from.'|'.$to.'|'.$type;
        if (isset($edges[$key])) {
            $edges[$key]['metadata']['occurrences']++;

            return;
        }

        $edges[$key] = [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'confidence' => self::CONFIDENCE_INFERRED,
            'confidence_score' => self::SCORE_INFERRED,
            'metadata' => array_merge([
                'ingestion' => self::SCHEMA,
                'confidence' => self::CONFIDENCE_INFERRED,
                'confidence_score' => self::SCORE_INFERRED,
                'inferred' => true,
                'occurrences' => 1,
            ], $signal),
        ];
        $stats['edges']++;
    }

    /**
     * Extract the set of code-module paths a doc points at, from its
     * canonical_path and related_paths_json. Only paths under a known code
     * prefix qualify; everything else (doc paths, urls) is ignored for edges.
     *
     * @return array<int,string>
     */
    private function codePathsForDoc(object $row): array
    {
        $candidates = [];

        $canonical = $this->normalizePath($row->canonical_path ?? null);
        if ($canonical !== null) {
            $candidates[] = $canonical;
        }

        foreach ($this->decodeJsonList($row->related_paths_json ?? null) as $related) {
            $norm = $this->normalizePath($related);
            if ($norm !== null) {
                $candidates[] = $norm;
            }
        }

        $out = [];
        foreach ($candidates as $path) {
            if ($this->isCodePath($path)) {
                $out[$path] = true;
            }
        }

        return array_keys($out);
    }

    private function isCodePath(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::CODE_PATH_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decode a JSON list column (related_paths_json) into a flat list of strings.
     * Tolerant of null, a non-JSON string, an already-decoded array, or a JSON
     * object (values taken). Never throws.
     *
     * @return array<int,string>
     */
    private function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
        } else {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Resolve the node budget: opts.max_nodes if a positive int, else the
     * default, then clamped down (never up) by config atlas.code_graph.max_edges
     * so the global cap is respected. Always at least 1.
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveBudget(array $opts): int
    {
        $budget = self::DEFAULT_MAX_NODES;
        $requested = $opts['max_nodes'] ?? null;
        if (is_int($requested) && $requested > 0) {
            $budget = $requested;
        } elseif (is_string($requested) && ctype_digit($requested) && (int) $requested > 0) {
            $budget = (int) $requested;
        }

        $configMax = config('atlas.code_graph.max_edges');
        if (is_int($configMax) && $configMax > 0 && $configMax < $budget) {
            $budget = $configMax;
        }

        return max(1, $budget);
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array<int,string>
     */
    private function resolveSources(array $opts): array
    {
        $all = ['docs', 'memory', 'evidence'];
        $requested = $opts['sources'] ?? null;
        if (! is_array($requested) || $requested === []) {
            return $all;
        }

        $out = [];
        foreach ($requested as $name) {
            if (is_string($name) && in_array($name, $all, true)) {
                $out[$name] = true;
            }
        }

        return $out === [] ? $all : array_keys($out);
    }

    private function clampLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '(untitled)';
        }

        return mb_strlen($label) > 220 ? mb_substr($label, 0, 217).'...' : $label;
    }

    private function normalizePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = str_replace('\\', '/', trim($value));
        $trimmed = trim($trimmed, '/ ');

        return $trimmed === '' ? null : $trimmed;
    }

    private function str(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
