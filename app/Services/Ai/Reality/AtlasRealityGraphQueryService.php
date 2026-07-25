<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\Memory\AtlasMemoryVectorSearchService;
use App\Services\Ai\Reality\Support\RealityGraphQuerySupport;
use App\Services\Ai\RuntimeBoundary\GraphRankRuntimeClient;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AURG Phase-2 — F2 brain query with provenance (Salto 1, "AURG vivo").
 *
 * Answers a natural query over the fused store built by
 * {@see AtlasRealityGraphIngestionService} (atlas_aurg_nodes/atlas_aurg_edges)
 * and returns the CROSS-LAYER chain for every answer: seeds → bounded BFS
 * traversal → node→edge→node paths, with full provenance on every element.
 *
 * SEEDING (hybrid, honest):
 *  - semantic: memory_entry brain nodes scored by the EXISTING
 *    {@see AtlasMemoryVectorSearchService} (real pgvector cosine over the
 *    ALREADY-EMBEDDED memory rows; on sqlite / missing engine it returns an
 *    empty map and this service degrades to lexical-only — semantic seeds are
 *    never faked from lexical hits);
 *  - lexical: ALL node kinds, per-term LIKE over label + meta (the AP-815 K2
 *    per-term pattern: OR across terms so multi-word queries recall nodes
 *    matching ANY term), re-scored in PHP by literal matched-term count
 *    (str_contains) so SQL `_`/`%` wildcard over-matches are dropped
 *    (cite-or-omit at the seed boundary too).
 *  Seeds are capped (default 8): semantic first up to half the cap, lexical
 *  fills the rest, leftover semantic tops up.
 *
 * TRAVERSAL: undirected BFS from the seeds, depth <= 2 by default
 * (configurable, HARD cap 3), bounded by max nodes/edges caps (default
 * 60/120). Deterministic: edges are walked in (from,to,kind) order; node
 * admit order is the insertion order the honest fallback ranking uses. Every
 * reached node keeps a parent pointer so the answer carries the full
 * node→edge→node chain back to its seed (paths), flagged cross_layer when the
 * chain spans more than one source read-model — the point of the brain.
 *
 * RANKING: when the result exceeds the threshold (default 12 nodes) ranking
 * is delegated to the EXISTING Python networkx runtime via
 * {@see GraphRankRuntimeClient} (the runtime_language_boundary canon: PHP
 * does IO/joins only, graph math lives in Python). Seed-anchored
 * deterministically: seed nodes carry the 'seed' capability and the query
 * requests it (capability_overlap boost), and the query terms are the textual
 * anchors that gate edge-boost propagation. HONEST fallback only: if the
 * runtime is absent/disabled/fails, nodes stay in insertion order and the
 * output says so (ranking 'unranked_*') — scores are NEVER fabricated, no
 * PHP stand-in math.
 *
 * PRIVACY (structural): opts['provider_bound']=true restricts seeds AND every
 * traversal step to provider_safe && !sensitive nodes. Because BFS never
 * crosses an excluded node, sensitive domains AND everything reachable ONLY
 * through them are excluded by construction, not by post-filtering. The MCP
 * surface forces provider_bound=true; the unbounded view is local CLI only.
 */
class AtlasRealityGraphQueryService
{
    public const RANKING_PYTHON = 'python_graph_rank';

    public const RANKING_BELOW_THRESHOLD = 'unranked_below_threshold';

    public const RANKING_RUNTIME_ABSENT = 'unranked_runtime_absent';

    public const RANKING_RUNTIME_ERROR = 'unranked_runtime_error';

    public const RANKING_DISABLED = 'unranked_disabled';

    public const RANKING_EMPTY_QUERY = 'unranked_empty_query';

    public const RANKING_STORE_MISSING = 'unranked_store_missing';

    public const SEED_VIA_SEMANTIC = 'semantic_memory_vector';

    public const SEED_VIA_LEXICAL = 'lexical';

    public const SEED_VIA_ENTITY_EXACT = 'entity_exact';

    /** Spec hard cap — opts can lower depth, never raise past this. */
    private const HARD_MAX_DEPTH = 3;

    private const HARD_MAX_NODES = 200;

    private const HARD_MAX_EDGES = 400;

    private const HARD_MAX_SEEDS = 16;

    /** Lexical SQL candidate window = seed cap × this (re-scored in PHP). */
    private const LEXICAL_CANDIDATE_FACTOR = 5;

    /** Per-BFS-layer edge fetch bound (deterministic truncation by order). */
    private const EDGE_FETCH_LIMIT = 2000;

    /** MAXD-07: default cap for federated module→symbol drill-down. Small on purpose. */
    public const EXPAND_SYMBOLS_PER_MODULE_DEFAULT = 5;

    /** MAXD-07: HARD ceiling; opt/config cannot raise it (budget guard). */
    public const EXPAND_SYMBOLS_PER_MODULE_HARD_CAP = 20;

    private readonly AtlasMemoryVectorSearchService $vectorSearch;

    private readonly GraphRankRuntimeClient $graphRank;

    public function __construct(
        ?AtlasMemoryVectorSearchService $vectorSearch = null,
        ?GraphRankRuntimeClient $graphRank = null,
    ) {
        // Container-resolved by default (the WorldModelGraphRanker pattern) so
        // `new AtlasRealityGraphQueryService` keeps working without wiring.
        $this->vectorSearch = $vectorSearch ?? app(AtlasMemoryVectorSearchService::class);
        $this->graphRank = $graphRank ?? app(GraphRankRuntimeClient::class);
    }

    /**
     * @param  array<string,mixed>  $opts  depth|max_nodes|max_edges|seed_limit|provider_bound
     * @return array<string,mixed> {query, terms, provider_bound, depth, seeds, nodes, edges, paths, ranking, caps_hit, generated_at}
     */
    public function query(string $query, array $opts = []): array
    {
        $query = trim($query);
        $providerBound = (bool) ($opts['provider_bound'] ?? false);
        $workspaceId = trim((string) ($opts['workspace_id'] ?? ''));

        $requestedDepth = (int) ($opts['depth'] ?? $this->cap('query_depth', 2));
        $depth = max(1, min(self::HARD_MAX_DEPTH, $requestedDepth));
        $maxNodes = max(1, min(self::HARD_MAX_NODES, (int) ($opts['max_nodes'] ?? $this->cap('query_max_nodes', 60))));
        $maxEdges = max(1, min(self::HARD_MAX_EDGES, (int) ($opts['max_edges'] ?? $this->cap('query_max_edges', 120))));
        $seedLimit = max(1, min(self::HARD_MAX_SEEDS, (int) ($opts['seed_limit'] ?? $this->cap('query_seed_limit', 8))));

        $capsHit = [
            'seeds' => false,
            'nodes' => false,
            'edges' => false,
            'depth_clamped' => $requestedDepth > self::HARD_MAX_DEPTH,
        ];

        if ($query === '') {
            return $this->result($query, [], $providerBound, $workspaceId, $depth, [], [], [], [], self::RANKING_EMPTY_QUERY, $capsHit);
        }
        if (! $this->storeReady()) {
            return $this->result($query, [], $providerBound, $workspaceId, $depth, [], [], [], [], self::RANKING_STORE_MISSING, $capsHit);
        }

        $terms = RealityGraphQuerySupport::terms($query);

        // ------------------------------------------------------------------
        // 1) SEEDS — semantic (real vectors, honest empty off-pgsql) + lexical.
        // ------------------------------------------------------------------
        $semanticTruncated = false;
        $lexicalTruncated = false;
        $entityTruncated = false;
        $entity = $this->entityExactSeeds($query, $terms, $providerBound, $workspaceId, $seedLimit, $entityTruncated);
        $semantic = $this->semanticSeeds($query, $providerBound, $workspaceId, $seedLimit, $semanticTruncated);
        $lexical = $this->lexicalSeeds($terms, $providerBound, $workspaceId, $seedLimit, $lexicalTruncated);
        $seeds = RealityGraphQuerySupport::mergeSeeds($entity, $semantic, $lexical, $seedLimit, $capsHit['seeds']);
        $capsHit['seeds'] = $capsHit['seeds'] || $entityTruncated || $semanticTruncated || $lexicalTruncated;

        if ($seeds === []) {
            return $this->result($query, $terms, $providerBound, $workspaceId, $depth, [], [], [], [], self::RANKING_BELOW_THRESHOLD, $capsHit);
        }

        // ------------------------------------------------------------------
        // 2) TRAVERSAL — bounded undirected BFS with parent pointers.
        // ------------------------------------------------------------------
        $traversal = $this->traverse(array_column($seeds, 'node_id'), $depth, $maxNodes, $maxEdges, $providerBound, $workspaceId, $capsHit);

        // ------------------------------------------------------------------
        // 3) PATHS — every reached node carries its node→edge→node chain.
        // ------------------------------------------------------------------
        $paths = $this->paths($traversal);

        // ------------------------------------------------------------------
        // 4) RANKING — Python networkx via the boundary, or HONEST unranked.
        // ------------------------------------------------------------------
        $pprShadow = $this->pprShadowDualRead($query, $terms, $traversal, $opts);
        [$orderedIds, $rankScores, $ranking] = $this->rank($traversal, $terms);
        $paths = RealityGraphQuerySupport::orderPaths($paths, $orderedIds);

        $nodes = [];
        foreach ($orderedIds as $nodeId) {
            $nodes[] = $this->nodePayload($traversal, $nodeId, $rankScores[$nodeId] ?? null);
        }
        $edges = array_map(fn (AtlasAurgEdge $edge): array => $this->edgePayload($edge), $traversal['edges']);

        $result = $this->result($query, $terms, $providerBound, $workspaceId, $depth, $seeds, $nodes, $edges, $paths, $ranking, $capsHit);

        // MAXD-07: federated drill-down module→símbolos at query-time. Off by
        // default (progressive disclosure); when opt-in, expand ONLY the
        // module nodes already in the answer, cap N/module small (default 5),
        // and NEVER persist the resulting symbols in the AURG store.
        if (in_array('code', RealityGraphQuerySupport::normalizeExpand($opts['expand'] ?? null), true)) {
            $perModule = (int) ($opts['expand_symbols_per_module']
                ?? config('atlas.aurg.query_expand_symbols_per_module', self::EXPAND_SYMBOLS_PER_MODULE_DEFAULT));
            $result = $this->expandCodeSymbols($result, $perModule);
        }
        if ($pprShadow !== null) {
            $result['ppr_shadow'] = $pprShadow;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Seeding
    // ------------------------------------------------------------------

    /**
     * MAXD-06 exact entity seeds: explicit repo paths/FQCNs, memory ids and
     * domain ids occupy seed slots before vector/LIKE recall. Every match is
     * cite-or-omit against an existing node; no fuzzy fallback is introduced here.
     *
     * @param  list<string>  $terms
     * @return list<array<string,mixed>>
     */
    private function entityExactSeeds(string $query, array $terms, bool $providerBound, string $workspaceId, int $cap, bool &$truncated = false): array
    {
        $seeds = [];
        $seen = [];
        $push = function (AtlasAurgNode $node, string $entityType, string $matched) use (&$seeds, &$seen, &$truncated, $providerBound, $workspaceId, $cap): void {
            $nodeId = (string) $node->id;
            if (isset($seen[$nodeId])) {
                return;
            }
            if ($providerBound && ! RealityGraphQuerySupport::providerAdmissible((bool) $node->provider_safe, (bool) $node->sensitive)) {
                return;
            }
            if (! RealityGraphQuerySupport::workspaceAdmissible($node->workspace_id, $workspaceId)) {
                return;
            }
            if (count($seeds) >= $cap) {
                $truncated = true;

                return;
            }
            $seen[$nodeId] = true;
            $seeds[] = [
                'node_id' => $nodeId,
                'via' => self::SEED_VIA_ENTITY_EXACT,
                'entity_type' => $entityType,
                'matched' => $matched,
                'score' => 1.0,
                'label' => (string) $node->label,
                'source_kind' => (string) $node->source_kind,
                'kind' => (string) $node->kind,
            ];
        };

        $paths = RealityGraphQuerySupport::entityRepoPaths($query);
        if ($paths !== []) {
            $modules = $this->moduleCandidates($providerBound, $workspaceId);
            foreach ($paths as $path) {
                foreach ($modules as $module) {
                    $rootPath = (string) (((array) ($module->meta ?? []))['root_path'] ?? '');
                    if ($rootPath === '') {
                        continue;
                    }
                    if ($path === $rootPath || str_starts_with($path, rtrim($rootPath, '/').'/')) {
                        $push($module, 'path', $path);
                        break;
                    }
                }
            }
        }

        $refs = RealityGraphQuerySupport::entityMemoryRefs($query);
        if ($refs !== []) {
            $memoryRows = AtlasAurgNode::query()
                ->where('source_kind', 'memory')
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY)
                ->whereIn('source_id', $refs)
                ->orderBy('id')
                ->get();
            foreach ($memoryRows as $memory) {
                $push($memory, 'memory_id', (string) $memory->source_id);
            }
        }

        if ($terms !== []) {
            $domains = AtlasAurgNode::query()
                ->where('source_kind', 'domain')
                ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN)
                ->whereIn('source_id', $terms)
                ->orderBy('id')
                ->get();
            foreach ($domains as $domain) {
                $push($domain, 'domain_id', (string) $domain->source_id);
            }
        }

        return $seeds;
    }

    /**
     * memory_entry seeds via the EXISTING memory vectors. The brain stores no
     * vectors itself (plain portable tables) — candidates are the bounded
     * memory-node refs and the similarity comes from pgvector over the SOURCE
     * rows. On sqlite / engine-missing the score maps are empty → no semantic
     * seeds, no fake substitutes.
     *
     * @return list<array<string,mixed>>
     */
    private function semanticSeeds(string $query, bool $providerBound, string $workspaceId, int $cap, bool &$truncated = false): array
    {
        $builder = AtlasAurgNode::query()
            ->where('source_kind', 'memory')
            ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY);
        if ($providerBound) {
            $builder->where('provider_safe', true)->where('sensitive', false);
        }
        $this->applyWorkspaceScope($builder, $workspaceId);
        $candidates = $builder
            ->orderBy('id')
            ->limit($this->cap('max_nodes', 5000))
            ->get(['id', 'source_id', 'label', 'kind', 'source_kind', 'meta']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $entryBydSourceId = [];
        $verbatimBySourceId = [];
        foreach ($candidates as $node) {
            $type = (string) (((array) ($node->meta ?? []))['type'] ?? '');
            if (str_starts_with($type, 'verbatim:')) {
                $verbatimBySourceId[(string) $node->source_id] = $node;
            } else {
                $entryBydSourceId[(string) $node->source_id] = $node;
            }
        }

        $scores = [];
        foreach ($this->vectorSearch->scoreEntries($query, array_keys($entryBydSourceId)) as $sourceId => $similarity) {
            $node = $entryBydSourceId[$sourceId] ?? null;
            if ($node !== null) {
                $scores[(string) $node->id] = ['node' => $node, 'score' => (float) $similarity];
            }
        }
        foreach ($this->vectorSearch->scoreVerbatims($query, array_keys($verbatimBySourceId)) as $sourceId => $similarity) {
            $node = $verbatimBySourceId[$sourceId] ?? null;
            if ($node !== null && ! isset($scores[(string) $node->id])) {
                $scores[(string) $node->id] = ['node' => $node, 'score' => (float) $similarity];
            }
        }

        // Similarity DESC, node id ASC tie-break (total deterministic order).
        $sortable = [];
        foreach ($scores as $nodeId => $entry) {
            $sortable[] = ['node_id' => (string) $nodeId] + $entry;
        }
        usort($sortable, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['node_id'], $b['node_id']));

        $truncated = count($sortable) > $cap;
        $seeds = [];
        foreach ($sortable as $entry) {
            if (count($seeds) >= $cap) {
                break;
            }
            $seeds[] = [
                'node_id' => $entry['node_id'],
                'via' => self::SEED_VIA_SEMANTIC,
                'score' => round($entry['score'], 4),
                'label' => (string) $entry['node']->label,
                'source_kind' => (string) $entry['node']->source_kind,
                'kind' => (string) $entry['node']->kind,
            ];
        }

        return $seeds;
    }

    /**
     * Per-term LIKE over label + meta (K2 pattern), then PHP re-score by
     * LITERAL matched-term count so SQL wildcard chars in terms never inflate
     * matches. Deterministic order: matched-terms DESC, node id ASC.
     *
     * @param  list<string>  $terms
     * @return list<array<string,mixed>>
     */
    private function lexicalSeeds(array $terms, bool $providerBound, string $workspaceId, int $cap, bool &$truncated = false): array
    {
        if ($terms === []) {
            return [];
        }

        $metaExpression = DB::connection()->getDriverName() === 'pgsql' ? 'meta::text' : 'meta';

        $builder = AtlasAurgNode::query();
        if ($providerBound) {
            $builder->where('provider_safe', true)->where('sensitive', false);
        }
        $this->applyWorkspaceScope($builder, $workspaceId);
        $builder->where(function ($query) use ($terms, $metaExpression): void {
            foreach ($terms as $term) {
                $like = '%'.$term.'%';
                $query->orWhereRaw('LOWER(label) LIKE ?', [$like])
                    ->orWhereRaw('LOWER('.$metaExpression.') LIKE ?', [$like]);
            }
        });

        $candidates = $builder
            ->orderBy('id')
            ->limit($cap * self::LEXICAL_CANDIDATE_FACTOR)
            ->get(['id', 'source_id', 'label', 'kind', 'source_kind', 'meta']);

        $scored = [];
        foreach ($candidates as $node) {
            $labelHaystack = mb_strtolower((string) $node->label);
            $metaHaystack = mb_strtolower((string) (json_encode($node->meta ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
            $haystack = $labelHaystack.' '.$metaHaystack;
            $matched = [];
            $labelMatched = [];
            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    $matched[] = $term;
                }
                if (str_contains($labelHaystack, $term)) {
                    $labelMatched[] = $term;
                }
            }
            // Cite-or-omit: a SQL wildcard artifact with zero literal hits is dropped.
            if ($matched === []) {
                continue;
            }
            $quality = RealityGraphQuerySupport::lexicalSeedQuality(
                (string) $node->source_kind,
                (string) $node->kind,
                (string) $node->label,
                $labelMatched,
                $providerBound,
            );
            if ($quality === null) {
                continue;
            }
            $scored[] = ['node' => $node, 'matched' => $matched, 'label_matched' => $labelMatched, 'quality' => $quality];
        }

        usort($scored, static function (array $a, array $b): int {
            return count($b['matched']) <=> count($a['matched'])
                ?: count($b['label_matched']) <=> count($a['label_matched'])
                ?: ((int) $b['quality'] <=> (int) $a['quality'])
                ?: strcmp((string) $a['node']->id, (string) $b['node']->id);
        });

        $truncated = count($scored) > $cap;
        $seeds = [];
        foreach (array_slice($scored, 0, $cap) as $entry) {
            $seeds[] = [
                'node_id' => (string) $entry['node']->id,
                'via' => self::SEED_VIA_LEXICAL,
                'matched_terms' => $entry['matched'],
                'matched_label_terms' => $entry['label_matched'],
                'label' => (string) $entry['node']->label,
                'source_kind' => (string) $entry['node']->source_kind,
                'kind' => (string) $entry['node']->kind,
            ];
        }

        return $seeds;
    }

    /**
    // ------------------------------------------------------------------
    // Traversal
    // ------------------------------------------------------------------

    /**
     * Bounded undirected BFS. provider_bound is enforced at EVERY admit step,
     * so anything reachable only through an excluded node is structurally
     * unreachable (never collected then filtered).
     *
     * @param  list<string>  $seedIds
     * @param  array<string,bool>  $capsHit  mutated: nodes/edges flags
     * @return array{order:list<string>, nodesById:array<string,AtlasAurgNode>, edges:list<AtlasAurgEdge>, parents:array<string,array{via:string, edge:AtlasAurgEdge, depth:int}>, depths:array<string,int>, seedIds:array<string,bool>}
     */
    private function traverse(
        array $seedIds,
        int $depth,
        int $maxNodes,
        int $maxEdges,
        bool $providerBound,
        string $workspaceId,
        array &$capsHit,
    ): array {
        $seedRows = AtlasAurgNode::query()->whereIn('id', $seedIds)->get()->keyBy('id');

        $order = [];
        $nodesById = [];
        $depths = [];
        $seedSet = [];
        foreach ($seedIds as $seedId) {
            $row = $seedRows->get($seedId);
            if ($row === null || isset($nodesById[$seedId])) {
                continue;
            }
            if ($providerBound && ! RealityGraphQuerySupport::providerAdmissible((bool) $row->provider_safe, (bool) $row->sensitive)) {
                continue;
            }
            if (! RealityGraphQuerySupport::workspaceAdmissible($row->workspace_id, $workspaceId)) {
                continue;
            }
            if (count($order) >= $maxNodes) {
                $capsHit['nodes'] = true;
                break;
            }
            $order[] = $seedId;
            $nodesById[$seedId] = $row;
            $depths[$seedId] = 0;
            $seedSet[$seedId] = true;
        }

        $edges = [];
        $edgeSeen = [];
        $parents = [];
        $frontier = $order;

        for ($layer = 1; $layer <= $depth && $frontier !== []; $layer++) {
            $rows = AtlasAurgEdge::query()
                ->where(function ($query) use ($frontier): void {
                    $query->whereIn('from_node_id', $frontier)
                        ->orWhereIn('to_node_id', $frontier);
                })
                ->orderBy('from_node_id')
                ->orderBy('to_node_id')
                ->orderBy('kind')
                ->limit(self::EDGE_FETCH_LIMIT)
                ->get();

            // Pre-fetch the not-yet-visited endpoints once per layer (IO/joins
            // in PHP, math elsewhere — the sanctioned split).
            $candidateIds = [];
            foreach ($rows as $edge) {
                foreach ([(string) $edge->from_node_id, (string) $edge->to_node_id] as $endpoint) {
                    if (! isset($nodesById[$endpoint])) {
                        $candidateIds[$endpoint] = true;
                    }
                }
            }
            $candidateRows = $candidateIds === []
                ? collect()
                : AtlasAurgNode::query()->whereIn('id', array_keys($candidateIds))->get()->keyBy('id');

            $next = [];
            foreach ($rows as $edge) {
                $edgeKey = (string) $edge->id;
                if (isset($edgeSeen[$edgeKey])) {
                    continue;
                }
                $from = (string) $edge->from_node_id;
                $to = (string) $edge->to_node_id;
                $fromVisited = isset($nodesById[$from]);
                $toVisited = isset($nodesById[$to]);

                if ($fromVisited && $toVisited) {
                    // Closing edge between two admitted nodes — provenance only.
                    if (count($edges) < $maxEdges) {
                        $edges[] = $edge;
                        $edgeSeen[$edgeKey] = true;
                    } else {
                        $capsHit['edges'] = true;
                    }

                    continue;
                }
                if (! $fromVisited && ! $toVisited) {
                    continue;
                }

                $via = $fromVisited ? $from : $to;
                $neighbor = $fromVisited ? $to : $from;

                $neighborRow = $candidateRows->get($neighbor);
                if (! $neighborRow instanceof AtlasAurgNode) {
                    continue; // dangling endpoint — cite-or-omit at read time too
                }
                if ($providerBound && ! RealityGraphQuerySupport::providerAdmissible((bool) $neighborRow->provider_safe, (bool) $neighborRow->sensitive)) {
                    continue; // structural privacy: never traverse INTO excluded nodes
                }
                if (! RealityGraphQuerySupport::workspaceAdmissible($neighborRow->workspace_id, $workspaceId)) {
                    continue;
                }
                if (count($order) >= $maxNodes) {
                    $capsHit['nodes'] = true;

                    continue;
                }

                $order[] = $neighbor;
                $nodesById[$neighbor] = $neighborRow;
                $depths[$neighbor] = $layer;
                $parents[$neighbor] = ['via' => $via, 'edge' => $edge, 'depth' => $layer];
                $next[] = $neighbor;

                if (count($edges) < $maxEdges) {
                    $edges[] = $edge;
                    $edgeSeen[$edgeKey] = true;
                } else {
                    $capsHit['edges'] = true;
                }
            }

            $frontier = $next;
        }

        return [
            'order' => $order,
            'nodesById' => $nodesById,
            'edges' => $edges,
            'parents' => $parents,
            'depths' => $depths,
            'seedIds' => $seedSet,
        ];
    }

    private function applyWorkspaceScope(Builder $builder, string $workspaceId): void
    {
        if ($workspaceId === '') {
            return;
        }

        $builder->where(function (Builder $query) use ($workspaceId): void {
            $query->whereNull('workspace_id')
                ->orWhere('workspace_id', $workspaceId);
        });
    }

    // ------------------------------------------------------------------
    // Paths (the cross-layer chains)
    // ------------------------------------------------------------------

    /**
     * One chain per reached non-seed node, walking the parent pointers back to
     * its seed. Hop direction is explicit ('forward' = stored edge direction).
     *
     * @param  array{order:list<string>, nodesById:array<string,AtlasAurgNode>, parents:array<string,array{via:string, edge:AtlasAurgEdge, depth:int}>, depths:array<string,int>, seedIds:array<string,bool>}  $traversal
     * @return list<array<string,mixed>>
     */
    private function paths(array $traversal): array
    {
        $paths = [];
        foreach ($traversal['order'] as $nodeId) {
            if (isset($traversal['seedIds'][$nodeId])) {
                continue;
            }

            $hops = [];
            $chain = [$nodeId];
            $cursor = $nodeId;
            while (isset($traversal['parents'][$cursor])) {
                $parent = $traversal['parents'][$cursor];
                /** @var AtlasAurgEdge $edge */
                $edge = $parent['edge'];
                $hops[] = [
                    'from' => $parent['via'],
                    'to' => $cursor,
                    'edge_kind' => (string) $edge->kind,
                    'edge_source' => (string) $edge->source,
                    'confidence' => round((float) $edge->confidence, 4),
                    'direction' => (string) $edge->from_node_id === $parent['via'] ? 'forward' : 'reverse',
                ];
                $cursor = $parent['via'];
                $chain[] = $cursor;
            }

            $hops = array_reverse($hops);
            $chain = array_reverse($chain);

            $sourceKinds = [];
            foreach ($chain as $chainNodeId) {
                $row = $traversal['nodesById'][$chainNodeId] ?? null;
                if ($row !== null) {
                    $sourceKinds[(string) $row->source_kind] = true;
                }
            }

            $paths[] = [
                'target' => $nodeId,
                'seed' => $chain[0],
                'depth' => $traversal['depths'][$nodeId] ?? count($hops),
                'nodes' => $chain,
                'hops' => $hops,
                'cross_layer' => count($sourceKinds) > 1,
            ];
        }

        return $paths;
    }

    // ------------------------------------------------------------------
    // Ranking (Python boundary or honest unranked)
    // ------------------------------------------------------------------

    /**
     * @param  array{order:list<string>, nodesById:array<string,AtlasAurgNode>, edges:list<AtlasAurgEdge>, seedIds:array<string,bool>}  $traversal
     * @param  list<string>  $terms
     * @return array{0:list<string>, 1:array<string,array<string,float>>, 2:string} [orderedIds, scoresByNodeId, rankingMode]
     */
    private function rank(array $traversal, array $terms): array
    {
        $insertion = $traversal['order'];
        $threshold = $this->cap('query_rank_threshold', 12);

        if (count($insertion) <= $threshold) {
            return [$insertion, [], self::RANKING_BELOW_THRESHOLD];
        }
        if (! (bool) config('atlas.aurg.query_rank_enabled', true)) {
            return [$insertion, [], self::RANKING_DISABLED];
        }
        if (! $this->graphRank->available()) {
            return [$insertion, [], self::RANKING_RUNTIME_ABSENT];
        }

        $nodePayloads = [];
        foreach ($insertion as $nodeId) {
            $node = $traversal['nodesById'][$nodeId];
            $nodePayloads[] = [
                'node_id' => $nodeId,
                'node_type' => (string) $node->kind,
                // Label as the textual surface the runtime matches the query
                // terms against (it lowercases the haystack itself).
                'path' => RealityGraphQuerySupport::rankTextSurface(
                    (string) $node->label,
                    (string) $node->source_id,
                    (array) ($node->meta ?? []),
                ),
                'flow_id' => null,
                // Deterministic seed anchor: the runtime's capability_overlap
                // boost fires exactly for the seeds (target_capabilities below).
                'capabilities' => isset($traversal['seedIds'][$nodeId]) ? ['seed'] : [],
                'risks' => [],
            ];
        }
        $edgePayloads = [];
        foreach ($traversal['edges'] as $edge) {
            $edgePayloads[] = [
                'from_node_id' => (string) $edge->from_node_id,
                'to_node_id' => (string) $edge->to_node_id,
                'edge_type' => (string) $edge->kind,
            ];
        }

        try {
            $ranking = $this->graphRank->rank($nodePayloads, $edgePayloads, [
                'textual_seeds' => $terms,
                'target_files' => [],
                'target_flows' => [],
                'target_capabilities' => ['seed'],
                'target_risks' => [],
                'risk_elevated' => false,
                'boost_docs' => false,
                'boost_tests' => false,
            ]);
        } catch (Throwable $throwable) {
            report($throwable);

            // Honest degrade: the runtime failed — insertion order, flagged,
            // never a PHP score stand-in.
            return [$insertion, [], self::RANKING_RUNTIME_ERROR];
        }

        $scores = [];
        foreach ((array) ($ranking['scored'] ?? []) as $entry) {
            if (! is_array($entry) || ! is_string($entry['node_id'] ?? null)) {
                continue;
            }
            $scores[$entry['node_id']] = [
                'score' => round((float) ($entry['score'] ?? 0.0), 4),
                'text_score' => round((float) ($entry['text_score'] ?? 0.0), 4),
                'graph_score' => round((float) ($entry['graph_score'] ?? 0.0), 4),
            ];
        }

        $ordered = [];
        $known = array_fill_keys($insertion, true);
        foreach ((array) ($ranking['ranked_order'] ?? []) as $nodeId) {
            if (is_string($nodeId) && isset($known[$nodeId]) && ! in_array($nodeId, $ordered, true)) {
                $ordered[] = $nodeId;
            }
        }
        foreach ($insertion as $nodeId) {
            if (! in_array($nodeId, $ordered, true)) {
                $ordered[] = $nodeId; // defensive: never drop a traversed node
            }
        }

        return [$ordered, $scores, self::RANKING_PYTHON];
    }

    /**
     * MAXD-04 — default-OFF PPR shadow dual-read against the BFS insertion
     * order. The returned query answer is never reordered by this method; it
     * only appends a governed JSONL ledger row for golden-v2/window analysis.
     *
     * @param  array{order:list<string>, nodesById:array<string,AtlasAurgNode>, edges:list<AtlasAurgEdge>, seedIds:array<string,bool>}  $traversal
     * @param  list<string>  $terms
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>|null
     */
    private function pprShadowDualRead(string $query, array $terms, array $traversal, array $opts): ?array
    {
        if (! (bool) config('atlas.aurg.query_ppr_shadow_enabled', false)) {
            return null;
        }

        $started = microtime(true);
        $latencyBudgetMs = max(1, (int) config('atlas.aurg.query_ppr_shadow_latency_budget_ms', 2000));
        $targets = RealityGraphQuerySupport::normaliseTargetNodeIds($opts['ppr_targets'] ?? $opts['targets'] ?? []);

        if (! $this->graphRank->available()) {
            return [
                'schema_version' => AtlasAurgPprShadowDualReadLedger::SCHEMA,
                'status' => 'runtime_absent',
                'applied_to_answer' => false,
                'ledger_recorded' => false,
                'latency_budget_ms' => $latencyBudgetMs,
            ];
        }

        try {
            $shadow = $this->graphRank->pprShadow(
                $this->rankNodePayloads($traversal, $terms),
                $this->rankEdgePayloads($traversal, includeConfidence: true),
                [
                    'textual_seeds' => $terms,
                    'target_files' => [],
                    'target_flows' => [],
                    'target_capabilities' => ['seed'],
                    'target_risks' => [],
                    'seed_node_ids' => array_keys($traversal['seedIds']),
                    'risk_elevated' => false,
                    'boost_docs' => false,
                    'boost_tests' => false,
                ],
                $traversal['order'],
                $targets,
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return [
                'schema_version' => AtlasAurgPprShadowDualReadLedger::SCHEMA,
                'status' => 'runtime_error',
                'applied_to_answer' => false,
                'ledger_recorded' => false,
                'latency_budget_ms' => $latencyBudgetMs,
            ];
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $dualRead = (array) ($shadow['dual_read'] ?? []);
        $cases = (int) ($dualRead['cases'] ?? count($targets));
        $targetsAvailable = (int) ($dualRead['targets_available'] ?? 0);
        $baselineRecall = $dualRead['baseline_recall_at_5'] ?? null;
        $pprRecall = $dualRead['ppr_recall_at_5'] ?? null;
        $withinLatencyBudget = $latencyMs < $latencyBudgetMs;
        $status = RealityGraphQuerySupport::pprShadowStatus($cases, $targetsAvailable, $baselineRecall, $pprRecall, $withinLatencyBudget);
        $hashPayload = [
            'schema_version' => AtlasAurgPprShadowDualReadLedger::SCHEMA,
            'query_hash' => hash('sha256', $query),
            'terms' => $terms,
            'baseline_top5' => $dualRead['baseline_top5'] ?? [],
            'ppr_top5' => $dualRead['ppr_top5'] ?? [],
            'targets' => $dualRead['targets'] ?? [],
            'missing_targets' => $dualRead['missing_targets'] ?? [],
            'latency_ms' => $latencyMs,
            'status' => $status,
        ];
        $dualReadHash = hash('sha256', (string) json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $receipt = [
            'schema_version' => AtlasAurgPprShadowDualReadLedger::SCHEMA,
            'series' => 'atlas.aurg.ppr_shadow_dual_read.v1',
            'slice' => 'MAXD-04',
            'recorded_at' => now()->toJSON(),
            'query_hash' => $hashPayload['query_hash'],
            'terms' => $terms,
            'node_count' => count($traversal['order']),
            'edge_count' => count($traversal['edges']),
            'baseline' => 'bfs_insertion_order',
            'candidate' => 'personalized_pagerank',
            'baseline_top5' => $dualRead['baseline_top5'] ?? [],
            'ppr_top5' => $dualRead['ppr_top5'] ?? [],
            'targets' => $dualRead['targets'] ?? [],
            'missing_targets' => $dualRead['missing_targets'] ?? [],
            'targets_available' => $targetsAvailable,
            'cases' => $cases,
            'baseline_recall_at_5' => $baselineRecall,
            'ppr_recall_at_5' => $pprRecall,
            'latency_ms' => $latencyMs,
            'latency_budget_ms' => $latencyBudgetMs,
            'within_latency_budget' => $withinLatencyBudget,
            'status' => $status,
            'dual_read_hash' => $dualReadHash,
        ];

        (new AtlasAurgPprShadowDualReadLedger)->append($receipt);

        return [
            'schema_version' => AtlasAurgPprShadowDualReadLedger::SCHEMA,
            'status' => $status,
            'applied_to_answer' => false,
            'ledger_recorded' => true,
            'series' => 'atlas.aurg.ppr_shadow_dual_read.v1',
            'dual_read_hash' => $dualReadHash,
            'baseline_top5' => $receipt['baseline_top5'],
            'ppr_top5' => $receipt['ppr_top5'],
            'targets_available' => $targetsAvailable,
            'cases' => $cases,
            'baseline_recall_at_5' => $baselineRecall,
            'ppr_recall_at_5' => $pprRecall,
            'latency_ms' => $latencyMs,
            'latency_budget_ms' => $latencyBudgetMs,
            'within_latency_budget' => $withinLatencyBudget,
        ];
    }

    /**
     * @param  array{order:list<string>, nodesById:array<string,AtlasAurgNode>, seedIds:array<string,bool>}  $traversal
     * @param  list<string>  $terms
     * @return list<array<string,mixed>>
     */
    private function rankNodePayloads(array $traversal, array $terms): array
    {
        $nodePayloads = [];
        foreach ($traversal['order'] as $nodeId) {
            $node = $traversal['nodesById'][$nodeId];
            $nodePayloads[] = [
                'node_id' => $nodeId,
                'node_type' => (string) $node->kind,
                'path' => RealityGraphQuerySupport::rankTextSurface(
                    (string) $node->label,
                    (string) $node->source_id,
                    (array) ($node->meta ?? []),
                ),
                'flow_id' => null,
                'capabilities' => isset($traversal['seedIds'][$nodeId]) ? ['seed'] : [],
                'risks' => [],
            ];
        }

        return $nodePayloads;
    }

    /**
     * @param  array{edges:list<AtlasAurgEdge>}  $traversal
     * @return list<array<string,mixed>>
     */
    private function rankEdgePayloads(array $traversal, bool $includeConfidence = false): array
    {
        $edgePayloads = [];
        foreach ($traversal['edges'] as $edge) {
            $payload = [
                'from_node_id' => (string) $edge->from_node_id,
                'to_node_id' => (string) $edge->to_node_id,
                'edge_type' => (string) $edge->kind,
            ];
            if ($includeConfidence) {
                $payload['confidence'] = round((float) $edge->confidence, 4);
            }
            $edgePayloads[] = $payload;
        }

        return $edgePayloads;
    }

    // ------------------------------------------------------------------
    // Payloads
    // ------------------------------------------------------------------

    /**
     * @param  array{nodesById:array<string,AtlasAurgNode>, depths:array<string,int>, seedIds:array<string,bool>}  $traversal
     * @param  array<string,float>|null  $rank
     * @return array<string,mixed>
     */
    private function nodePayload(array $traversal, string $nodeId, ?array $rank): array
    {
        $node = $traversal['nodesById'][$nodeId];

        $payload = [
            'id' => (string) $node->id,
            'kind' => (string) $node->kind,
            'source_kind' => (string) $node->source_kind,
            'source_id' => (string) $node->source_id,
            'label' => (string) $node->label,
            'workspace_id' => $node->workspace_id !== null ? (string) $node->workspace_id : null,
            'provider_safe' => (bool) $node->provider_safe,
            'sensitive' => (bool) $node->sensitive,
            'content_hash' => (string) $node->content_hash,
            'meta' => (array) ($node->meta ?? []),
            'seed' => isset($traversal['seedIds'][$nodeId]),
            'depth' => $traversal['depths'][$nodeId] ?? null,
        ];
        if ($rank !== null) {
            // Present ONLY when the real Python runtime ranked — never fabricated.
            $payload['rank'] = $rank;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function edgePayload(AtlasAurgEdge $edge): array
    {
        return [
            'from' => (string) $edge->from_node_id,
            'to' => (string) $edge->to_node_id,
            'kind' => (string) $edge->kind,
            'source' => (string) $edge->source,
            'confidence' => round((float) $edge->confidence, 4),
            'meta' => (array) ($edge->meta ?? []),
        ];
    }

    /**
     * @param  list<string>  $terms
     * @param  list<array<string,mixed>>  $seeds
     * @param  list<array<string,mixed>>  $nodes
     * @param  list<array<string,mixed>>  $edges
     * @param  list<array<string,mixed>>  $paths
     * @param  array<string,bool>  $capsHit
     * @return array<string,mixed>
     */
    private function result(
        string $query,
        array $terms,
        bool $providerBound,
        string $workspaceId,
        int $depth,
        array $seeds,
        array $nodes,
        array $edges,
        array $paths,
        string $ranking,
        array $capsHit,
    ): array {
        return [
            'query' => $query,
            'terms' => $terms,
            'provider_bound' => $providerBound,
            'workspace_id' => $workspaceId !== '' ? $workspaceId : null,
            'depth' => $depth,
            'seeds' => $seeds,
            'nodes' => $nodes,
            'edges' => $edges,
            'paths' => $paths,
            'ranking' => $ranking,
            'counts' => [
                'seeds' => count($seeds),
                'nodes' => count($nodes),
                'edges' => count($edges),
                'paths' => count($paths),
                'cross_layer_paths' => count(array_filter($paths, static fn (array $p): bool => (bool) $p['cross_layer'])),
            ],
            'caps_hit' => $capsHit,
            'generated_at' => now()->toJSON(),
        ];
    }

    // ------------------------------------------------------------------
    // MAXD-07 — federated drill-down module→símbolos (query-time only)
    // ------------------------------------------------------------------

    /**
     * Expand every `module` node in the result into top-N symbols read live
     * from `atlas_engineering_code_symbols`. The expansion is FEDERATED:
     * symbols are NOT persisted in the AURG store (no new nodes/edges), the
     * store hash is invariant across `--expand=code` runs, and the payload is
     * clearly marked `federated=true`.
     *
     * The formal AURG↔Code Intelligence bridge for MAXD-07: it materialises
     * the module→symbol join at answer time, using the ALREADY-CURATED module
     * set the query returned, so the brain stays "top-K modules, not 300k
     * symbols" and the drill-down never crosses budget.
     *
     * Ordering is deterministic (symbol_name asc, then id) — a re-run over
     * the same DB state returns the same top-N and the same content_hash for
     * `federated_expansions` (see the MAXD-07 aceite).
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public function expandCodeSymbols(array $result, int $perModule): array
    {
        $cap = max(1, min(self::EXPAND_SYMBOLS_PER_MODULE_HARD_CAP, $perModule));

        $modulesTable = 'atlas_engineering_code_modules';
        $symbolsTable = 'atlas_engineering_code_symbols';
        $ready = DatabaseTableAvailability::has($modulesTable)
            && DatabaseTableAvailability::has($symbolsTable);

        $expansions = [];
        $totalSymbols = 0;
        $modulesTouched = 0;
        $modulesMissing = 0;
        $storeNodesBefore = 0;
        $storeEdgesBefore = 0;
        if (DatabaseTableAvailability::has('atlas_aurg_nodes')) {
            $storeNodesBefore = (int) AtlasAurgNode::query()->count();
        }
        if (DatabaseTableAvailability::has('atlas_aurg_edges')) {
            $storeEdgesBefore = (int) AtlasAurgEdge::query()->count();
        }

        if ($ready) {
            $moduleNodes = array_values(array_filter(
                $result['nodes'] ?? [],
                static fn (array $node): bool => (string) ($node['source_kind'] ?? '') === 'code'
                    && (string) ($node['kind'] ?? '') === AtlasRealityGraphSnapshotBuilderService::NODE_MODULE,
            ));

            foreach ($moduleNodes as $moduleNode) {
                $slug = trim((string) ($moduleNode['meta']['slug'] ?? ''));
                if ($slug === '') {
                    continue;
                }

                $moduleRow = DB::table($modulesTable)
                    ->where('slug', $slug)
                    ->first(['id', 'slug', 'name', 'root_path']);
                if ($moduleRow === null) {
                    $modulesMissing++;

                    continue;
                }

                $symbolRows = DB::table($symbolsTable)
                    ->where('module_id', $moduleRow->id)
                    ->where('status', 'active')
                    ->orderBy('symbol_name')
                    ->orderBy('id')
                    ->limit($cap)
                    ->get(['id', 'symbol_type', 'symbol_name', 'file_path', 'line_start', 'namespace']);

                if ($symbolRows->isEmpty()) {
                    continue;
                }

                $symbols = [];
                foreach ($symbolRows as $sym) {
                    $symbols[] = [
                        'id' => (string) $sym->id,
                        'symbol_type' => (string) $sym->symbol_type,
                        'symbol_name' => (string) $sym->symbol_name,
                        'file_path' => (string) $sym->file_path,
                        'line_start' => $sym->line_start !== null ? (int) $sym->line_start : null,
                        'namespace' => $sym->namespace !== null ? (string) $sym->namespace : null,
                        'federated' => true,
                    ];
                }

                $expansions[] = [
                    'module_node_id' => (string) $moduleNode['id'],
                    'module_id' => (string) $moduleRow->id,
                    'slug' => (string) $moduleRow->slug,
                    'name' => (string) $moduleRow->name,
                    'root_path' => $moduleRow->root_path !== null ? (string) $moduleRow->root_path : null,
                    'federated' => true,
                    'symbols' => $symbols,
                    'symbols_count' => count($symbols),
                    'symbols_capped' => count($symbols) >= $cap,
                ];
                $modulesTouched++;
                $totalSymbols += count($symbols);
            }
        }

        $storeNodesAfter = 0;
        $storeEdgesAfter = 0;
        if (DatabaseTableAvailability::has('atlas_aurg_nodes')) {
            $storeNodesAfter = (int) AtlasAurgNode::query()->count();
        }
        if (DatabaseTableAvailability::has('atlas_aurg_edges')) {
            $storeEdgesAfter = (int) AtlasAurgEdge::query()->count();
        }

        $result['expand'] = ['code'];
        $result['federated_expansions'] = [
            'code' => [
                'ready' => $ready,
                'modules_expanded' => $modulesTouched,
                'modules_missing_in_ci' => $modulesMissing,
                'symbols_returned' => $totalSymbols,
                'per_module_cap' => $cap,
                'per_module_hard_cap' => self::EXPAND_SYMBOLS_PER_MODULE_HARD_CAP,
                'items' => $expansions,
                'store_invariant' => [
                    'nodes_before' => $storeNodesBefore,
                    'nodes_after' => $storeNodesAfter,
                    'edges_before' => $storeEdgesBefore,
                    'edges_after' => $storeEdgesAfter,
                    'delta_nodes' => $storeNodesAfter - $storeNodesBefore,
                    'delta_edges' => $storeEdgesAfter - $storeEdgesBefore,
                ],
                'notes' => 'MAXD-07 federated drill-down: symbols read live from atlas_engineering_code_symbols, never persisted in the AURG store.',
            ],
        ];

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return Collection<int,AtlasAurgNode>
     */
    private function moduleCandidates(bool $providerBound, string $workspaceId)
    {
        $builder = AtlasAurgNode::query()
            ->where('source_kind', 'code')
            ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($providerBound) {
            $builder->where('provider_safe', true)->where('sensitive', false);
        }
        $this->applyWorkspaceScope($builder, $workspaceId);

        return $builder->orderBy('id')->limit(self::HARD_MAX_NODES)->get();
    }

    private function storeReady(): bool
    {
        return DatabaseTableAvailability::all(['atlas_aurg_nodes', 'atlas_aurg_edges']);
    }

    private function cap(string $key, int $default): int
    {
        $value = (int) config('atlas.aurg.'.$key, $default);

        return $value > 0 ? $value : $default;
    }
}
