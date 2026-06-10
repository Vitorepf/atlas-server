<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\Memory\AtlasMemoryVectorSearchService;
use App\Services\Ai\RuntimeBoundary\GraphRankRuntimeClient;
use App\Services\Ai\Support\DatabaseTableAvailability;
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

    /** Spec hard cap — opts can lower depth, never raise past this. */
    private const HARD_MAX_DEPTH = 3;

    private const HARD_MAX_NODES = 200;

    private const HARD_MAX_EDGES = 400;

    private const HARD_MAX_SEEDS = 16;

    /** Bounded multi-term tokenisation of the query. */
    private const MAX_QUERY_TERMS = 8;

    /** Lexical SQL candidate window = seed cap × this (re-scored in PHP). */
    private const LEXICAL_CANDIDATE_FACTOR = 5;

    /** Per-BFS-layer edge fetch bound (deterministic truncation by order). */
    private const EDGE_FETCH_LIMIT = 2000;

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
            return $this->result($query, [], $providerBound, $depth, [], [], [], [], self::RANKING_EMPTY_QUERY, $capsHit);
        }
        if (! $this->storeReady()) {
            return $this->result($query, [], $providerBound, $depth, [], [], [], [], self::RANKING_STORE_MISSING, $capsHit);
        }

        $terms = $this->terms($query);

        // ------------------------------------------------------------------
        // 1) SEEDS — semantic (real vectors, honest empty off-pgsql) + lexical.
        // ------------------------------------------------------------------
        $semanticTruncated = false;
        $lexicalTruncated = false;
        $semantic = $this->semanticSeeds($query, $providerBound, $seedLimit, $semanticTruncated);
        $lexical = $this->lexicalSeeds($terms, $providerBound, $seedLimit, $lexicalTruncated);
        $seeds = $this->mergeSeeds($semantic, $lexical, $seedLimit, $capsHit['seeds']);
        $capsHit['seeds'] = $capsHit['seeds'] || $semanticTruncated || $lexicalTruncated;

        if ($seeds === []) {
            return $this->result($query, $terms, $providerBound, $depth, [], [], [], [], self::RANKING_BELOW_THRESHOLD, $capsHit);
        }

        // ------------------------------------------------------------------
        // 2) TRAVERSAL — bounded undirected BFS with parent pointers.
        // ------------------------------------------------------------------
        $traversal = $this->traverse(array_column($seeds, 'node_id'), $depth, $maxNodes, $maxEdges, $providerBound, $capsHit);

        // ------------------------------------------------------------------
        // 3) PATHS — every reached node carries its node→edge→node chain.
        // ------------------------------------------------------------------
        $paths = $this->paths($traversal);

        // ------------------------------------------------------------------
        // 4) RANKING — Python networkx via the boundary, or HONEST unranked.
        // ------------------------------------------------------------------
        [$orderedIds, $rankScores, $ranking] = $this->rank($traversal, $terms);

        $nodes = [];
        foreach ($orderedIds as $nodeId) {
            $nodes[] = $this->nodePayload($traversal, $nodeId, $rankScores[$nodeId] ?? null);
        }
        $edges = array_map(fn (AtlasAurgEdge $edge): array => $this->edgePayload($edge), $traversal['edges']);

        return $this->result($query, $terms, $providerBound, $depth, $seeds, $nodes, $edges, $paths, $ranking, $capsHit);
    }

    // ------------------------------------------------------------------
    // Seeding
    // ------------------------------------------------------------------

    /**
     * memory_entry seeds via the EXISTING memory vectors. The brain stores no
     * vectors itself (plain portable tables) — candidates are the bounded
     * memory-node refs and the similarity comes from pgvector over the SOURCE
     * rows. On sqlite / engine-missing the score maps are empty → no semantic
     * seeds, no fake substitutes.
     *
     * @return list<array<string,mixed>>
     */
    private function semanticSeeds(string $query, bool $providerBound, int $cap, bool &$truncated = false): array
    {
        $builder = AtlasAurgNode::query()
            ->where('source_kind', 'memory')
            ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY);
        if ($providerBound) {
            $builder->where('provider_safe', true)->where('sensitive', false);
        }
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
    private function lexicalSeeds(array $terms, bool $providerBound, int $cap, bool &$truncated = false): array
    {
        if ($terms === []) {
            return [];
        }

        $metaExpression = DB::connection()->getDriverName() === 'pgsql' ? 'meta::text' : 'meta';

        $builder = AtlasAurgNode::query();
        if ($providerBound) {
            $builder->where('provider_safe', true)->where('sensitive', false);
        }
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
            $haystack = mb_strtolower(
                (string) $node->label.' '
                .(json_encode($node->meta ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            );
            $matched = [];
            foreach ($terms as $term) {
                if (str_contains($haystack, $term)) {
                    $matched[] = $term;
                }
            }
            // Cite-or-omit: a SQL wildcard artifact with zero literal hits is dropped.
            if ($matched === []) {
                continue;
            }
            $scored[] = ['node' => $node, 'matched' => $matched];
        }

        usort($scored, static function (array $a, array $b): int {
            return count($b['matched']) <=> count($a['matched'])
                ?: strcmp((string) $a['node']->id, (string) $b['node']->id);
        });

        $truncated = count($scored) > $cap;
        $seeds = [];
        foreach (array_slice($scored, 0, $cap) as $entry) {
            $seeds[] = [
                'node_id' => (string) $entry['node']->id,
                'via' => self::SEED_VIA_LEXICAL,
                'matched_terms' => $entry['matched'],
                'label' => (string) $entry['node']->label,
                'source_kind' => (string) $entry['node']->source_kind,
                'kind' => (string) $entry['node']->kind,
            ];
        }

        return $seeds;
    }

    /**
     * Semantic first up to half the cap, lexical fills the rest, leftover
     * semantic tops up. Dedup by node id (semantic wins — the stronger signal).
     *
     * @param  list<array<string,mixed>>  $semantic
     * @param  list<array<string,mixed>>  $lexical
     * @return list<array<string,mixed>>
     */
    private function mergeSeeds(array $semantic, array $lexical, int $cap, bool &$overflow): array
    {
        $picked = [];
        $push = static function (array $seed) use (&$picked, $cap): void {
            if (count($picked) < $cap && ! isset($picked[$seed['node_id']])) {
                $picked[$seed['node_id']] = $seed;
            }
        };

        foreach (array_slice($semantic, 0, (int) ceil($cap / 2)) as $seed) {
            $push($seed);
        }
        foreach ($lexical as $seed) {
            $push($seed);
        }
        foreach ($semantic as $seed) {
            $push($seed);
        }

        $distinct = [];
        foreach (array_merge($semantic, $lexical) as $seed) {
            $distinct[$seed['node_id']] = true;
        }
        $overflow = count($distinct) > $cap;

        return array_values($picked);
    }

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
    private function traverse(array $seedIds, int $depth, int $maxNodes, int $maxEdges, bool $providerBound, array &$capsHit): array
    {
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
            if ($providerBound && ! $this->providerAdmissible($row)) {
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
                if ($providerBound && ! $this->providerAdmissible($neighborRow)) {
                    continue; // structural privacy: never traverse INTO excluded nodes
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

    private function providerAdmissible(AtlasAurgNode $node): bool
    {
        // provider_safe AND not sensitive — belt and suspenders: F1 marks
        // sensitive domains provider_safe=false already, but a sensitive row
        // must never ride a provider-bound answer regardless of its safe bit.
        return (bool) $node->provider_safe && ! (bool) $node->sensitive;
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
                'path' => (string) $node->label,
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
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Lowercased whitespace terms (>=2 chars), bounded. No accent folding —
     * lexical match is literal by design (deterministic); semantics belong to
     * the vector seeds.
     *
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [];
        $terms = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token !== '' && mb_strlen($token) >= 2 && ! in_array($token, $terms, true)) {
                $terms[] = $token;
            }
        }

        return array_slice($terms, 0, self::MAX_QUERY_TERMS);
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
