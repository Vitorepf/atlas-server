<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AURG Phase-2 — F1 fused-store ingestion (Salto 1, "AURG vivo").
 *
 * Federates BOUNDED, provider-safe projections of the 5 REAL read-models into the
 * atlas_aurg_nodes / atlas_aurg_edges store promised by the canonical
 * {@see AtlasRealityGraphSnapshotBuilderService} docblock:
 *
 *   memory    — atlas_memory_entries (+ atlas_verbatim_memories where
 *               external_ai_allowed). Labels/meta come ONLY from the already-redacted
 *               provider projection ({@see AtlasMemoryPrivacyService}); raw text never
 *               enters the brain. Blocked verbatims are skipped entirely; blocked
 *               entries become provider_safe=false nodes (local-only).
 *   code      — 1 workspace node per indexed workspace + module nodes from
 *               atlas_engineering_code_modules (modules ONLY — the code-graph stays
 *               canonical for code-to-code; the brain holds a bounded projection,
 *               never the symbols).
 *   domains   — the 21 canonical domains from {@see CrossDomainTaxonomyMap}
 *               (sensitive flag from the map) + mesh allowed-crossing edges from the
 *               EXISTING {@see AtlasCrossDomainMeshService} topology (reuse, not
 *               recreate). Sensitive domains are provider_safe=false.
 *   evidence  — recent N atlas_engineering_evidence rows as refs: label = event
 *               type, meta = ids/hashes ONLY, never payloads.
 *   strategic — atlas_reality_entities (expired valid_until skipped, honouring the
 *               ASRE 14-day decay) + atlas_reality_relationships as edges.
 *
 * CROSS-LAYER LINKERS (the point of the brain) are DETERMINISTIC and cite-or-omit —
 * no LLM, no fuzzy scores, no invented edges. Every emitted edge cites in meta the
 * exact field/value that matched; when a linker finds nothing it emits nothing:
 *   (a) memory→code   'references' — a path in the memory's source metadata equal to
 *       a module root_path (1.0) or under it / a label token equal to a module slug (0.7);
 *   (b) memory→domain 'belongs_to' — a memory tag / metadata domain field that
 *       resolves through CrossDomainTaxonomyMap (1.0, exact id resolution);
 *   (c) evidence→memory/code 'proves' — evidence target_id / metadata memory id equal
 *       to a memory source_id (1.0), or an evidence file path matching a module
 *       root_path (exact 1.0 / prefix 0.7);
 *   (d) code workspace→engineering domain 'belongs_to' (1.0, by construction).
 *
 * CONFIDENCE LADDER (deterministic, documented, closed):
 *   1.0 — exact id match: FK rows (verbatim→entry, module→workspace, strategic
 *         relationships), taxonomy-resolved domain ids, mesh topology rules,
 *         exact path equality.
 *   0.7 — derived match: path-prefix under a module root_path, or a redacted-label
 *         token equal to a module slug.
 * No other values are produced. Confidence is never model output and never faked.
 *
 * Idempotent: nodes upsert on their deterministic key; edges upsert on
 * (from,to,kind); re-running without source changes is a no-op. --prune removes
 * brain nodes whose source row vanished, scoped per source_kind, plus edges left
 * dangling by those removals — it never touches other source kinds.
 *
 * PHP does IO/joins only here (the sanctioned runtime-boundary pattern); graph
 * ranking/math belongs to the Python graph_rank runtime via GraphRankRuntimeClient
 * (consumed by later F-slices, not F1).
 *
 * F4 (compounding + temporal) adds on top of the same store:
 *  - {@see self::ingestMemoryEntry()} — best-effort per-row accrual invoked from the
 *    AtlasMemoryRegistryService write path (memory is the live accruing source);
 *  - {@see self::recordTemporalSnapshot()} — a REAL graph-state tick (non-null
 *    snapshot_hash via the canonical Builder) appended to the AURG-4D chain after
 *    each full sync, consumed by AtlasRealityGraphStatusService for growth deltas.
 */
class AtlasRealityGraphIngestionService
{
    public const SOURCES = ['memory', 'code', 'domains', 'evidence', 'strategic'];

    public const CONFIDENCE_EXACT = 1.0;

    public const CONFIDENCE_DERIVED = 0.7;

    /** Per-node cap on linker-discovered candidate paths kept in meta. */
    private const MAX_META_PATHS = 10;

    /** Per-node cap on resolved canonical domains kept in meta. */
    private const MAX_META_DOMAINS = 5;

    /** Per-memory/evidence cap on emitted linker edges (bound, deterministic order). */
    private const MAX_LINKS_PER_NODE = 10;

    public function __construct(
        private readonly CrossDomainTaxonomyMap $taxonomy,
        private readonly AtlasMemoryPrivacyService $memoryPrivacy,
        private readonly ?AtlasCrossDomainMeshService $mesh = null,
    ) {}

    /**
     * Idempotent fused-store sync.
     *
     * @param  array<int,string>  $sources  subset of self::SOURCES
     * @return array<string,mixed> stats (per-source node/edge counts, linker counts, prune counts, totals, duration)
     */
    public function sync(array $sources = self::SOURCES, bool $prune = false): array
    {
        $startedAt = microtime(true);
        $sources = array_values(array_intersect(self::SOURCES, $sources));
        if ($sources === []) {
            $sources = self::SOURCES;
        }

        $stats = ['sources' => [], 'linkers' => [], 'pruned' => ['nodes' => 0, 'edges' => 0]];

        foreach ($sources as $source) {
            $gathered = match ($source) {
                'memory' => $this->gatherMemory(),
                'code' => $this->gatherCode(),
                'domains' => $this->gatherDomains(),
                'evidence' => $this->gatherEvidence(),
                'strategic' => $this->gatherStrategic(),
            };

            $this->upsertNodes($gathered['nodes']);
            $edgeCount = $this->upsertEdges($gathered['edges']);

            $stats['sources'][$source] = [
                'nodes' => count($gathered['nodes']),
                'edges' => $edgeCount,
            ];

            // Prune only when the source read-model is actually readable — a missing
            // source table means "cannot verify vanishing", not "everything vanished"
            // (honest degrade: never wipe a layer on infrastructure absence).
            if ($prune && $this->sourceAvailable($source)) {
                $pruned = $this->pruneSource($this->sourceKindFor($source), array_column($gathered['nodes'], 'id'));
                $stats['pruned']['nodes'] += $pruned['nodes'];
                $stats['pruned']['edges'] += $pruned['edges'];
            }
        }

        // Cross-layer linkers run over DB state (not just this run's batch) so a
        // partial --source sync still links against previously ingested layers.
        $stats['linkers'] = [
            'memory_code' => $this->linkMemoryToCode(),
            'memory_domain' => $this->linkMemoryToDomain(),
            'evidence_links' => $this->linkEvidence(),
            'code_domain' => $this->linkWorkspaceToEngineeringDomain(),
        ];

        $stats['totals'] = [
            'nodes_in_store' => (int) AtlasAurgNode::query()->count(),
            'edges_in_store' => (int) AtlasAurgEdge::query()->count(),
            'provider_safe_nodes' => (int) AtlasAurgNode::query()->where('provider_safe', true)->count(),
            'sensitive_nodes' => (int) AtlasAurgNode::query()->where('sensitive', true)->count(),
        ];
        $stats['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        $stats['prune'] = $prune;

        return $stats;
    }

    // ------------------------------------------------------------------
    // Source 1 — MEMORY (provider-safe projection only)
    // ------------------------------------------------------------------

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    private function gatherMemory(): array
    {
        $nodes = [];
        $edges = [];

        if ($this->tableExists('atlas_memory_entries')) {
            $limit = $this->cap('memory_limit', 500);
            $query = AtlasMemoryEntry::query()->active();
            if (Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
                $query->whereNull('superseded_by_id');
            }
            $entries = $query->latest('recorded_at')->limit($limit)->get();

            foreach ($entries as $entry) {
                $nodes[] = $this->memoryEntryNode($entry);
            }
        }

        if ($this->tableExists('atlas_verbatim_memories')) {
            $limit = $this->cap('memory_limit', 500);
            // Blocked verbatim rows are SKIPPED ENTIRELY (spec). Same policy
            // authority as AtlasMemoryPrivacyService::externalAiAllowed(): the
            // stored bit AND the privacy-class blocklist (config + 'secret').
            $blockedClasses = array_values(array_unique(array_merge(
                array_filter((array) config('atlas.privacy.block_external_ai_for_sensitivity', []), 'is_string'),
                ['secret'],
            )));
            $verbatims = AtlasVerbatimMemory::query()
                ->active()
                ->where('external_ai_allowed', true)
                ->whereNotIn('privacy_class', $blockedClasses)
                ->latest('recorded_at')
                ->limit($limit)
                ->get();

            foreach ($verbatims as $verbatim) {
                $label = AtlasSecurity::redactString((string) ($verbatim->title ?: 'verbatim:'.$verbatim->verbatim_type));
                $nodes[] = $this->node(
                    id: $this->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $verbatim->id),
                    kind: AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY,
                    sourceKind: 'memory',
                    sourceId: (string) $verbatim->id,
                    label: $label,
                    providerSafe: true,
                    sensitive: $verbatim->privacy_class === 'sensitive',
                    meta: [
                        'type' => 'verbatim:'.$verbatim->verbatim_type,
                        'scope' => (string) $verbatim->scope_type,
                        'privacy_class' => (string) $verbatim->privacy_class,
                        'paths' => $this->candidatePaths(array_merge(
                            (array) ($verbatim->metadata ?? []),
                            ['tags' => (array) ($verbatim->tags ?? [])],
                        )),
                        'domains' => $this->candidateDomains((array) ($verbatim->tags ?? []), (array) ($verbatim->metadata ?? [])),
                    ],
                    contentHash: (string) ($verbatim->redacted_hash ?: $verbatim->content_hash ?: hash('sha256', $label)),
                );

                // Exact FK → parent memory entry (confidence 1.0, exact id).
                if (is_string($verbatim->memory_entry_id) && $verbatim->memory_entry_id !== '') {
                    $edges[] = $this->edge(
                        from: $this->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $verbatim->id),
                        to: $this->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $verbatim->memory_entry_id),
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                        source: 'memory_ingest',
                        confidence: self::CONFIDENCE_EXACT,
                        meta: ['matched' => 'memory_entry_id'],
                    );
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The canonical brain projection of ONE memory entry — shared verbatim by the
     * full sync (gatherMemory) and the F4 ingest-on-write accrual so the per-row
     * path can never drift from the batch path. Label ONLY from the redacted
     * provider projection — never raw title.
     *
     * @return array<string,mixed>
     */
    private function memoryEntryNode(AtlasMemoryEntry $entry): array
    {
        $decision = $this->memoryPrivacy->providerDecision($entry);
        $privacyClass = (string) $decision['privacy_class'];
        $label = $this->memoryPrivacy->providerTitle($entry)
            ?? $this->memoryPrivacy->providerSummary($entry)
            ?? ('memory:'.$entry->memory_type);

        return $this->node(
            id: $this->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $entry->id),
            kind: AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY,
            sourceKind: 'memory',
            sourceId: (string) $entry->id,
            label: $label,
            providerSafe: (bool) $decision['allowed'],
            sensitive: in_array($privacyClass, ['sensitive', 'secret'], true),
            meta: [
                'type' => (string) $entry->memory_type,
                'scope' => (string) $entry->scope_type,
                'privacy_class' => $privacyClass,
                'paths' => $this->candidatePaths(array_merge(
                    (array) ($entry->metadata ?? []),
                    ['tags' => (array) ($entry->tags ?? [])],
                )),
                'domains' => $this->candidateDomains((array) ($entry->tags ?? []), (array) ($entry->metadata ?? [])),
            ],
            contentHash: is_string($entry->content_hash) && $entry->content_hash !== ''
                ? $entry->content_hash
                : hash('sha256', $label.'|'.$entry->memory_type.'|'.$entry->scope_type),
        );
    }

    // ------------------------------------------------------------------
    // F4 — COMPOUNDING: ingest-on-write for the live accruing source (memory)
    // ------------------------------------------------------------------

    /**
     * F4 (Salto 1 — "AURG vivo") accrual: upsert the brain node for ONE memory
     * entry and re-run the deterministic memory→code / memory→domain linkers FOR
     * THAT ROW ONLY — never a full sync inline on the write path.
     *
     * Honest-skip contract (the caller in AtlasMemoryRegistryService is fail-open
     * on top of this): returns false without writing when the brain is disabled,
     * the ingest-on-write switch is off, the brain tables are absent (brain not
     * provisioned ≠ error), or the row would not be in the full-sync projection
     * (non-active / superseded rows never accrue; the daily --prune sweeps them).
     *
     * Same node builder and same linker rungs as the batch path (cite-or-omit,
     * confidence ladder 1.0/0.7) — accrual can only ever produce a subset of what
     * the next full sync would produce, so the daily sync stays idempotent over it.
     */
    public function ingestMemoryEntry(AtlasMemoryEntry $entry): bool
    {
        if (! (bool) config('atlas.aurg.enabled', true) || ! (bool) config('atlas.aurg.ingest_on_write', true)) {
            return false;
        }
        if (! $this->tableExists('atlas_aurg_nodes') || ! $this->tableExists('atlas_aurg_edges')) {
            return false;
        }
        if (! $entry->getKey()) {
            return false;
        }
        if ((string) $entry->status !== 'active' || $entry->archived_at !== null) {
            return false;
        }
        if (Schema::hasColumn('atlas_memory_entries', 'superseded_by_id') && $entry->superseded_by_id !== null) {
            return false;
        }

        $node = $this->memoryEntryNode($entry);
        $this->upsertNodes([$node]);

        // Row-scoped linkers: the SAME deterministic rules, THIS node only.
        $memory = [
            'id' => (string) $node['id'],
            'source_id' => (string) $node['source_id'],
            'label' => (string) $node['label'],
            'kind' => (string) $node['kind'],
            'meta' => (array) $node['meta'],
        ];

        $edges = [];
        $modules = $this->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules !== []) {
            $edges = $this->memoryCodeEdgesFor($memory, $modules, $this->moduleSlugIndex($modules));
        }
        $domainIds = $this->domainIdIndex();
        if ($domainIds !== []) {
            $edges = array_merge($edges, $this->memoryDomainEdgesFor($memory, $domainIds));
        }
        $this->upsertEdges($edges);

        return true;
    }

    // ------------------------------------------------------------------
    // S2.F1 — CLOSED MISSION LOOP: record a delivered-mission outcome back
    // ------------------------------------------------------------------

    /**
     * S2.F1 ("the brain feeds the hands, the hands feed the brain"): after a
     * governed delivery materializes a branch, record the OUTCOME back INTO the
     * fused store so the NEXT mission's brain query sees it (compounding for
     * execution). Writes, all under the 'mission' source_kind (its own prune
     * scope — never touched by the 5 read-model syncs):
     *
     *   - a MISSION node (kind=mission) labelled with the request, carrying the
     *     branch ref + ids/hashes ONLY in meta (NEVER source code, NEVER diffs);
     *   - an EVIDENCE node (kind=evidence) for the test/measure RESULT (status +
     *     branch + receipt hash, no payloads);
     *   - mission --generated--> evidence (1.0, by construction: the mission
     *     produced exactly this branch+evidence);
     *   - mission --references--> module, cite-or-omit, for every TOUCHED file
     *     that resolves to an existing brain code module (exact root 1.0 /
     *     under-root 0.7) — the SAME deterministic ladder as the memory→code
     *     linker, no fuzzy match, no invented edges;
     *   - mission --references--> memory_entry (1.0) for any cited memory id that
     *     is an existing brain memory node.
     *
     * Contracts:
     *   - PRIVACY: only ids/hashes/labels/branch-ref/paths enter the brain. The
     *     request label is redacted via AtlasSecurity; sensitive=false,
     *     provider_safe=true (the outcome of a provider-bound delivery is itself
     *     provider-safe by construction). Touched paths are cited but no file
     *     bytes are ever stored.
     *   - NEVER-MERGE: the recorded ref is the BRANCH (atlas/materialize/<id>),
     *     never a merge — the caller's materializer already enforces branch-only.
     *   - IDEMPOTENT: nodes upsert on the deterministic key, edges on
     *     (from,to,kind); re-recording the same outcome is a no-op (no dup).
     *   - HONEST-SKIP / FAIL-OPEN at the boundary: returns recorded=false without
     *     writing when the brain is disabled or its tables are absent (the caller
     *     wraps this in try/catch so a brain outage never breaks a delivery).
     *
     * @param  array<string,mixed>  $outcome  {id, request, branch, delivered(bool),
     *     provider?, receipt?, files?:list<string>, measure?:array{status?,ok?},
     *     memory_refs?:list<string>}
     * @return array<string,mixed> {recorded(bool), reason?, mission_node?, evidence_node?, edges?:int}
     */
    public function recordMissionOutcome(array $outcome): array
    {
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['recorded' => false, 'reason' => 'aurg_disabled'];
        }
        if (! $this->tableExists('atlas_aurg_nodes') || ! $this->tableExists('atlas_aurg_edges')) {
            return ['recorded' => false, 'reason' => 'store_missing'];
        }

        $id = trim((string) ($outcome['id'] ?? ''));
        $request = trim((string) ($outcome['request'] ?? ''));
        if ($id === '' || $request === '') {
            return ['recorded' => false, 'reason' => 'id_and_request_required'];
        }

        $branch = trim((string) ($outcome['branch'] ?? ''));
        $delivered = (bool) ($outcome['delivered'] ?? false);
        $provider = isset($outcome['provider']) && is_string($outcome['provider']) ? $outcome['provider'] : null;
        $receipt = isset($outcome['receipt']) && is_string($outcome['receipt']) ? $outcome['receipt'] : null;
        $files = array_values(array_filter((array) ($outcome['files'] ?? []), 'is_string'));
        $measure = (array) ($outcome['measure'] ?? []);
        $memoryRefs = array_values(array_filter((array) ($outcome['memory_refs'] ?? []), 'is_string'));

        // 1) MISSION node — request label (redacted), branch + ids/hashes only.
        $missionNodeId = $this->nodeKey('mission', AtlasRealityGraphSnapshotBuilderService::NODE_MISSION, $id);
        $missionNode = $this->node(
            id: $missionNodeId,
            kind: AtlasRealityGraphSnapshotBuilderService::NODE_MISSION,
            sourceKind: 'mission',
            sourceId: $id,
            label: AtlasSecurity::redactString($request),
            providerSafe: true,
            sensitive: false,
            meta: array_filter([
                'branch' => $branch !== '' ? $branch : null,
                'delivered' => $delivered,
                'provider' => $provider,
                'receipt' => $receipt,
                'never_merged' => true,
                'touched_paths' => array_slice($files, 0, self::MAX_META_PATHS),
            ], static fn ($v): bool => $v !== null),
            // State fingerprint: request + branch + delivered + receipt — re-recording
            // an unchanged outcome yields the same hash (idempotent, deterministic).
            contentHash: hash('sha256', $id.'|'.$request.'|'.$branch.'|'.($delivered ? '1' : '0').'|'.((string) $receipt)),
        );

        // 2) EVIDENCE node — the test/measure RESULT for this mission (no payloads).
        $measureStatus = is_string($measure['status'] ?? null)
            ? (string) $measure['status']
            : (array_key_exists('ok', $measure) ? ((bool) $measure['ok'] ? 'passed' : 'failed') : ($delivered ? 'delivered' : 'blocked'));
        $evidenceNodeId = $this->nodeKey('mission', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE, $id);
        $evidenceNode = $this->node(
            id: $evidenceNodeId,
            kind: AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE,
            sourceKind: 'mission',
            sourceId: $id,
            label: 'mission_outcome',
            providerSafe: true,
            sensitive: false,
            meta: array_filter([
                'mission_id' => $id,
                'status' => $measureStatus,
                'branch' => $branch !== '' ? $branch : null,
                'receipt' => $receipt,
            ], static fn ($v): bool => $v !== null),
            contentHash: hash('sha256', 'mission_outcome|'.$id.'|'.$measureStatus.'|'.$branch.'|'.($delivered ? '1' : '0')),
        );

        $this->upsertNodes([$missionNode, $evidenceNode]);

        // 3) EDGES — generated (mission→evidence) + cite-or-omit references.
        $edges = [];

        // mission --generated--> evidence (1.0, by construction).
        $edges[] = $this->edge(
            from: $missionNodeId,
            to: $evidenceNodeId,
            kind: AtlasRealityGraphSnapshotBuilderService::EDGE_GENERATED,
            source: 'mission_outcome',
            confidence: self::CONFIDENCE_EXACT,
            meta: array_filter([
                'branch' => $branch !== '' ? $branch : null,
                'status' => $measureStatus,
            ], static fn ($v): bool => $v !== null),
        );

        // mission --references--> module (cite-or-omit, same ladder as memory→code).
        $modules = $this->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules !== [] && $files !== []) {
            $edges = array_merge($edges, $this->missionTouchedModuleEdges($missionNodeId, $files, $modules, $this->moduleSlugIndex($modules)));
        }

        // mission --references--> memory_entry (1.0) for cited, existing memory nodes.
        if ($memoryRefs !== []) {
            $edges = array_merge($edges, $this->missionMemoryEdges($missionNodeId, $memoryRefs));
        }

        $edgeCount = $this->upsertEdges($edges);

        return [
            'recorded' => true,
            'mission_node' => $missionNodeId,
            'evidence_node' => $evidenceNodeId,
            'edges' => $edgeCount,
        ];
    }

    /**
     * mission→module 'references' for each touched file path that resolves to an
     * existing brain module: exact root_path = 1.0, under root = 0.7, label token
     * equal to a module slug = 0.7. Deterministic, bounded, cite-or-omit — the
     * SAME ladder/rules as {@see self::memoryCodeEdgesFor()}.
     *
     * @param  list<string>  $files
     * @param  list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $modules
     * @param  array<string,array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $bySlug
     * @return list<array<string,mixed>>
     */
    private function missionTouchedModuleEdges(string $missionNodeId, array $files, array $modules, array $bySlug): array
    {
        $edges = [];
        $emitted = 0;
        $linked = [];

        foreach ($files as $path) {
            if ($emitted >= self::MAX_LINKS_PER_NODE) {
                break;
            }
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            foreach ($modules as $module) {
                $rootPath = (string) ($module['meta']['root_path'] ?? '');
                if ($rootPath === '') {
                    continue;
                }
                $confidence = null;
                if ($path === $rootPath) {
                    $confidence = self::CONFIDENCE_EXACT;
                } elseif (str_starts_with($path, rtrim($rootPath, '/').'/')) {
                    $confidence = self::CONFIDENCE_DERIVED;
                }
                if ($confidence === null || isset($linked[$module['id']])) {
                    continue;
                }
                $edges[] = $this->edge(
                    from: $missionNodeId,
                    to: $module['id'],
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'mission_outcome',
                    confidence: $confidence,
                    meta: ['matched_path' => $path, 'module_root' => $rootPath],
                );
                $linked[$module['id']] = true;
                $emitted++;
                break;
            }
        }

        // Fall back to label-token = module-slug (0.7) for paths that matched no root.
        if ($emitted < self::MAX_LINKS_PER_NODE) {
            foreach ($files as $path) {
                if ($emitted >= self::MAX_LINKS_PER_NODE) {
                    break;
                }
                foreach ($this->labelTokens($path) as $token) {
                    $module = $bySlug[$token] ?? null;
                    if ($module === null || isset($linked[$module['id']])) {
                        continue;
                    }
                    $edges[] = $this->edge(
                        from: $missionNodeId,
                        to: $module['id'],
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                        source: 'mission_outcome',
                        confidence: self::CONFIDENCE_DERIVED,
                        meta: ['matched_token' => $token],
                    );
                    $linked[$module['id']] = true;
                    $emitted++;
                    break;
                }
            }
        }

        return $edges;
    }

    /**
     * mission→memory_entry 'references' (1.0) for each cited memory id that is an
     * EXISTING brain memory node. Cite-or-omit: an unknown id emits nothing.
     *
     * @param  list<string>  $memoryRefs
     * @return list<array<string,mixed>>
     */
    private function missionMemoryEdges(string $missionNodeId, array $memoryRefs): array
    {
        $bySourceId = [];
        foreach ($this->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $bySourceId[$memory['source_id']] = $memory['id'];
        }
        if ($bySourceId === []) {
            return [];
        }

        $edges = [];
        $emitted = 0;
        $seen = [];
        foreach ($memoryRefs as $ref) {
            if ($emitted >= self::MAX_LINKS_PER_NODE) {
                break;
            }
            $ref = trim($ref);
            $target = $bySourceId[$ref] ?? null;
            if ($target === null || isset($seen[$target])) {
                continue;
            }
            $edges[] = $this->edge(
                from: $missionNodeId,
                to: $target,
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                source: 'mission_outcome',
                confidence: self::CONFIDENCE_EXACT,
                meta: ['matched_memory_id' => $ref],
            );
            $seen[$target] = true;
            $emitted++;
        }

        return $edges;
    }

    // ------------------------------------------------------------------
    // F4 — TEMPORAL: real 4D snapshot tick after a full sync
    // ------------------------------------------------------------------

    /**
     * F4 (Salto 1 — "AURG vivo") temporal fix: record a REAL graph-state tick into
     * the append-only AURG-4D chain after a FULL sync (the command path — never
     * per-row). Prior writers only appended empty rationale_event ticks; this one
     * builds a canonical 3D snapshot via {@see AtlasRealityGraphSnapshotBuilderService}
     * over the REAL bounded node/edge id+hash list of the fused store, so the tick
     * carries a non-null snapshot_hash derived from actual graph state.
     *
     * STATE-DETERMINISTIC by construction: the builder payload is (node id, kind,
     * content_hash-as-label, provider_safe, created_at) + (edge triple, confidence,
     * source, created_at) — created_at/content_hash survive idempotent re-upserts,
     * so an unchanged graph yields the SAME snapshot_hash and any node/edge change
     * yields a different one. No labels/payloads enter the tick log: only the hash
     * and honest per-kind counts (delta_summary) — which is also what the status
     * surface uses for the growth delta.
     *
     * Bounded: reads at most aurg.snapshot_max_nodes/snapshot_max_edges rows (the
     * brain is hundreds-to-low-thousands by design); overflow is reported honestly
     * as truncated=true rather than silently hashing a partial state.
     *
     * @return array<string,mixed> {recorded, tick_id?, snapshot_hash?, node_count?, edge_count?, truncated?, reason?}
     */
    public function recordTemporalSnapshot(
        AtlasUnifiedRealityGraphTemporalService $temporal,
        string $actor = 'atlas',
        string $rationale = '',
    ): array {
        if (! $this->tableExists('atlas_aurg_nodes') || ! $this->tableExists('atlas_aurg_edges')) {
            return ['recorded' => false, 'reason' => 'store_missing'];
        }

        $maxNodes = $this->cap('snapshot_max_nodes', 20000);
        $maxEdges = $this->cap('snapshot_max_edges', 60000);

        $nodeRows = AtlasAurgNode::query()
            ->orderBy('id')
            ->limit($maxNodes + 1)
            ->get(['id', 'kind', 'content_hash', 'provider_safe', 'created_at']);
        $nodesTruncated = $nodeRows->count() > $maxNodes;
        if ($nodesTruncated) {
            $nodeRows = $nodeRows->slice(0, $maxNodes)->values();
        }

        $edgeRows = AtlasAurgEdge::query()
            ->orderBy('from_node_id')
            ->orderBy('to_node_id')
            ->orderBy('kind')
            ->limit($maxEdges + 1)
            ->get(['from_node_id', 'to_node_id', 'kind', 'confidence', 'source', 'created_at']);
        $edgesTruncated = $edgeRows->count() > $maxEdges;
        if ($edgesTruncated) {
            $edgeRows = $edgeRows->slice(0, $maxEdges)->values();
        }

        $nodes = [];
        foreach ($nodeRows as $row) {
            $nodes[] = [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                // State fingerprint, not display: the content hash is the label so
                // content changes flip the snapshot hash; no text rides the payload.
                'label' => (string) $row->content_hash,
                'source' => 'aurg_store',
                // created_at survives idempotent upserts (updated_at does not) —
                // this keeps the snapshot hash deterministic for unchanged state.
                'observed_at' => $row->created_at?->toIso8601String() ?? '',
                'confidence' => 1.0,
                'provider_safe' => (bool) $row->provider_safe,
            ];
        }

        $edges = [];
        foreach ($edgeRows as $row) {
            $edges[] = [
                'from_id' => (string) $row->from_node_id,
                'to_id' => (string) $row->to_node_id,
                'kind' => (string) $row->kind,
                'weight' => (float) $row->confidence,
                'source' => (string) $row->source,
                'observed_at' => $row->created_at?->toIso8601String() ?? '',
            ];
        }

        $snapshot = (new AtlasRealityGraphSnapshotBuilderService)->buildSnapshot($nodes, $edges);
        $stats = (array) ($snapshot['stats'] ?? []);
        $truncated = $nodesTruncated || $edgesTruncated;

        $tick = $temporal->recordTick([
            'kind' => AtlasUnifiedRealityGraphTemporalService::KIND_SNAPSHOT_RECORDED,
            'actor' => $actor,
            'snapshot_hash' => (string) $snapshot['snapshot_hash'],
            'rationale' => $rationale,
            // Honest graph-state counts (computed by the Builder over the real
            // rows, never fabricated) — the temporal chain's growth signal.
            'delta_summary' => [
                'node_count' => (int) ($stats['node_count'] ?? 0),
                'edge_count' => (int) ($stats['edge_count'] ?? 0),
                'kinds' => (array) ($stats['kinds'] ?? []),
                'edge_kinds' => (array) ($stats['edge_kinds'] ?? []),
                'provider_safe_nodes' => (int) ($stats['provider_safe_nodes'] ?? 0),
                'blocked_nodes' => (int) ($stats['blocked_nodes'] ?? 0),
                'truncated' => $truncated,
            ],
        ]);

        return [
            'recorded' => true,
            'tick_id' => (string) $tick['tick_id'],
            'snapshot_hash' => (string) $snapshot['snapshot_hash'],
            'node_count' => (int) ($stats['node_count'] ?? 0),
            'edge_count' => (int) ($stats['edge_count'] ?? 0),
            'truncated' => $truncated,
        ];
    }

    // ------------------------------------------------------------------
    // Source 2 — CODE (bounded projection: workspaces + modules, never symbols)
    // ------------------------------------------------------------------

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    private function gatherCode(): array
    {
        $nodes = [];
        $edges = [];

        if (! $this->tableExists('atlas_engineering_code_modules')) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        $hasWorkspace = Schema::hasColumn('atlas_engineering_code_modules', 'workspace_id');
        $defaultWorkspace = (string) config('atlas.code_graph.default_workspace_id', 'atlas-server');
        $perWorkspace = $this->cap('modules_per_workspace', 300);

        $workspaceIds = $hasWorkspace
            ? DB::table('atlas_engineering_code_modules')->whereNull('archived_at')->distinct()->pluck('workspace_id')->filter()->values()->all()
            : [$defaultWorkspace];
        if ($workspaceIds === []) {
            $workspaceIds = [$defaultWorkspace];
        }

        foreach ($workspaceIds as $workspaceId) {
            $workspaceId = (string) $workspaceId;
            $moduleQuery = DB::table('atlas_engineering_code_modules')
                ->whereNull('archived_at')
                ->where('status', 'active');
            if ($hasWorkspace) {
                $moduleQuery->where('workspace_id', $workspaceId);
            }
            $modules = $moduleQuery
                ->orderByDesc('symbol_count')
                ->orderBy('slug')
                ->limit($perWorkspace)
                ->get(['id', 'slug', 'name', 'layer', 'root_path', 'source_hash', 'symbol_count']);

            $workspaceNodeId = $this->nodeKey('code', AtlasRealityGraphSnapshotBuilderService::NODE_WORKSPACE, $workspaceId);
            $nodes[] = $this->node(
                id: $workspaceNodeId,
                kind: AtlasRealityGraphSnapshotBuilderService::NODE_WORKSPACE,
                sourceKind: 'code',
                sourceId: $workspaceId,
                label: $workspaceId,
                providerSafe: true,
                sensitive: false,
                meta: ['module_count' => $modules->count()],
                contentHash: hash('sha256', 'workspace|'.$workspaceId),
                workspaceId: $workspaceId,
            );

            foreach ($modules as $module) {
                $sourceId = $workspaceId.'/'.(string) $module->slug;
                $moduleNodeId = $this->nodeKey('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE, $sourceId);
                $nodes[] = $this->node(
                    id: $moduleNodeId,
                    kind: AtlasRealityGraphSnapshotBuilderService::NODE_MODULE,
                    sourceKind: 'code',
                    sourceId: $sourceId,
                    label: (string) $module->name,
                    providerSafe: true,
                    sensitive: false,
                    meta: [
                        'slug' => (string) $module->slug,
                        'layer' => (string) $module->layer,
                        'root_path' => $module->root_path !== null ? (string) $module->root_path : null,
                    ],
                    contentHash: (string) ($module->source_hash ?: hash('sha256', $sourceId)),
                    workspaceId: $workspaceId,
                );

                // Same indexed row carries both ends → exact id (1.0).
                $edges[] = $this->edge(
                    from: $moduleNodeId,
                    to: $workspaceNodeId,
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                    source: 'code_ingest',
                    confidence: self::CONFIDENCE_EXACT,
                    meta: ['matched' => 'workspace_id'],
                );
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    // ------------------------------------------------------------------
    // Source 3 — DOMAINS (21 canonical + mesh allowed-crossing edges)
    // ------------------------------------------------------------------

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    private function gatherDomains(): array
    {
        $nodes = [];
        $edges = [];

        foreach ($this->taxonomy->all() as $canonical => $meta) {
            $nodes[] = $this->node(
                id: $this->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, (string) $canonical),
                kind: AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN,
                sourceKind: 'domain',
                sourceId: (string) $canonical,
                label: $meta['label'],
                // Sensitive domains NEVER enter a provider prompt → structurally unsafe.
                providerSafe: ! $meta['sensitive'],
                sensitive: (bool) $meta['sensitive'],
                meta: ['mesh_id' => $meta['mesh'], 'registry_id' => $meta['registry']],
                contentHash: hash('sha256', $canonical.'|'.$meta['label'].'|'.(int) $meta['sensitive']),
            );
        }

        // Mesh allowed-crossing rules → references edges (reuse the EXISTING topology).
        if ($this->mesh !== null) {
            try {
                $topology = $this->mesh->topology();
            } catch (Throwable) {
                $topology = [];
            }
            $allowed = is_array($topology['edges_allowed'] ?? null) ? $topology['edges_allowed'] : [];
            foreach ($allowed as $rule) {
                if (! is_array($rule)) {
                    continue;
                }
                $from = $this->taxonomy->canonical((string) ($rule['from'] ?? ''));
                $to = $this->taxonomy->canonical((string) ($rule['to'] ?? ''));
                if ($from === null || $to === null || $from === $to) {
                    continue;
                }
                $edges[] = $this->edge(
                    from: $this->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, $from),
                    to: $this->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, $to),
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'domain_mesh',
                    confidence: self::CONFIDENCE_EXACT,
                    meta: [
                        'matched' => 'mesh_topology_rule',
                        'privacy_classes_allowed' => is_array($rule['privacy_classes_allowed'] ?? null)
                            ? array_values($rule['privacy_classes_allowed'])
                            : [],
                    ],
                );
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    // ------------------------------------------------------------------
    // Source 4 — EVIDENCE (refs only: ids/hashes, never payloads)
    // ------------------------------------------------------------------

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    private function gatherEvidence(): array
    {
        $nodes = [];

        if (! $this->tableExists('atlas_engineering_evidence')) {
            return ['nodes' => $nodes, 'edges' => []];
        }

        $limit = $this->cap('evidence_limit', 200);
        $rows = DB::table('atlas_engineering_evidence')
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get(['id', 'evidence_type', 'status', 'task_id', 'trace_id', 'target_id', 'files', 'metadata', 'recorded_at']);

        foreach ($rows as $row) {
            $files = $this->decodeJsonList($row->files ?? null);
            $metadata = $this->decodeJsonMap($row->metadata ?? null);

            $nodes[] = $this->node(
                id: $this->nodeKey('evidence', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE, (string) $row->id),
                kind: AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE,
                sourceKind: 'evidence',
                sourceId: (string) $row->id,
                label: (string) $row->evidence_type,
                providerSafe: true,
                sensitive: false,
                // ids/hashes ONLY — summary/command/output never enter the brain.
                meta: [
                    'status' => (string) $row->status,
                    'task_id' => $row->task_id !== null ? (string) $row->task_id : null,
                    'trace_id' => $row->trace_id !== null ? (string) $row->trace_id : null,
                    'target_id' => $row->target_id !== null ? (string) $row->target_id : null,
                    'memory_ref' => $this->memoryRefFrom($metadata),
                    'paths' => array_slice(array_values(array_filter($files, 'is_string')), 0, self::MAX_META_PATHS),
                ],
                contentHash: hash('sha256', $row->id.'|'.$row->evidence_type.'|'.(string) $row->recorded_at),
            );
        }

        return ['nodes' => $nodes, 'edges' => []];
    }

    // ------------------------------------------------------------------
    // Source 5 — STRATEGIC (ASRE entities + relationships, decay honoured)
    // ------------------------------------------------------------------

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    private function gatherStrategic(): array
    {
        $nodes = [];
        $edges = [];

        if (! $this->tableExists('atlas_reality_entities')) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        $limit = $this->cap('strategic_limit', 500);
        $entities = AtlasRealityEntity::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        $entityIds = [];
        foreach ($entities as $entity) {
            $entityIds[(string) $entity->id] = true;
            $attributes = (array) ($entity->attributes ?? []);
            $privacyClass = is_string($attributes['privacy_class'] ?? null) ? $attributes['privacy_class'] : 'normal';
            $sensitive = in_array($privacyClass, ['sensitive', 'secret'], true);

            $entityType = (string) $entity->entity_type;
            $kind = in_array($entityType, AtlasRealityGraphSnapshotBuilderService::ALLOWED_NODE_KINDS, true)
                ? $entityType
                : AtlasRealityGraphSnapshotBuilderService::NODE_REALITY_ENTITY;

            $nodes[] = $this->node(
                id: $this->nodeKey('strategic', $kind, (string) $entity->id),
                kind: $kind,
                sourceKind: 'strategic',
                sourceId: (string) $entity->id,
                label: AtlasSecurity::redactString((string) $entity->name),
                providerSafe: ! $sensitive,
                sensitive: $sensitive,
                meta: [
                    'entity_type' => $entityType,
                    'entity_key' => (string) $entity->entity_key,
                    'authority_level' => (string) $entity->authority_level,
                    'freshness_status' => (string) $entity->freshness_status,
                ],
                contentHash: (string) ($entity->entity_hash ?: hash('sha256', (string) $entity->entity_key)),
            );
        }

        if ($this->tableExists('atlas_reality_relationships')) {
            $kindByEntity = [];
            foreach ($nodes as $node) {
                $kindByEntity[$node['source_id']] = $node['kind'];
            }
            $relationships = AtlasRealityRelationship::query()
                ->where('status', 'active')
                ->latest('updated_at')
                ->limit($limit)
                ->get();

            foreach ($relationships as $relationship) {
                $sourceId = (string) ($relationship->source_entity_id ?? '');
                $targetId = (string) ($relationship->target_entity_id ?? '');
                // Cite-or-omit: both endpoints must be ingested entities.
                if (! isset($entityIds[$sourceId], $entityIds[$targetId]) || $sourceId === $targetId) {
                    continue;
                }
                $relationType = (string) $relationship->relationship_type;
                $kind = in_array($relationType, AtlasRealityGraphSnapshotBuilderService::ALLOWED_EDGE_KINDS, true)
                    ? $relationType
                    : AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES;

                $edges[] = $this->edge(
                    from: $this->nodeKey('strategic', $kindByEntity[$sourceId], $sourceId),
                    to: $this->nodeKey('strategic', $kindByEntity[$targetId], $targetId),
                    kind: $kind,
                    source: 'strategic_ingest',
                    confidence: self::CONFIDENCE_EXACT,
                    meta: array_filter([
                        'matched' => 'relationship_row',
                        'original_type' => $kind === $relationType ? null : $relationType,
                        'weight' => $relationship->weight !== null ? (float) $relationship->weight : null,
                    ], static fn ($v) => $v !== null),
                );
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    // ------------------------------------------------------------------
    // Cross-layer linkers (deterministic, cite-or-omit, DB-state driven)
    // ------------------------------------------------------------------

    /**
     * (a) memory→code 'references': exact path = 1.0; path under module root /
     * label token equal to module slug = 0.7. Cites the matched value in meta.
     */
    private function linkMemoryToCode(): int
    {
        $modules = $this->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules === []) {
            return 0;
        }

        $bySlug = $this->moduleSlugIndex($modules);

        $edges = [];
        foreach ($this->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $edges = array_merge($edges, $this->memoryCodeEdgesFor($memory, $modules, $bySlug));
        }

        return $this->upsertEdges($edges);
    }

    /**
     * The memory→code rungs for ONE memory node — shared by the batch linker and
     * the F4 ingest-on-write accrual so both paths emit identical edges.
     *
     * @param  array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}  $memory
     * @param  list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $modules
     * @param  array<string,array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $bySlug
     * @return list<array<string,mixed>>
     */
    private function memoryCodeEdgesFor(array $memory, array $modules, array $bySlug): array
    {
        $edges = [];
        $emitted = 0;
        $paths = array_values(array_filter((array) ($memory['meta']['paths'] ?? []), 'is_string'));

        foreach ($paths as $path) {
            if ($emitted >= self::MAX_LINKS_PER_NODE) {
                break;
            }
            foreach ($modules as $module) {
                $rootPath = (string) ($module['meta']['root_path'] ?? '');
                if ($rootPath === '') {
                    continue;
                }
                $confidence = null;
                if ($path === $rootPath) {
                    $confidence = self::CONFIDENCE_EXACT;
                } elseif (str_starts_with($path, rtrim($rootPath, '/').'/')) {
                    $confidence = self::CONFIDENCE_DERIVED;
                }
                if ($confidence === null) {
                    continue;
                }
                $edges[] = $this->edge(
                    from: $memory['id'],
                    to: $module['id'],
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'linker_memory_code',
                    confidence: $confidence,
                    meta: ['matched_path' => $path, 'module_root' => $rootPath],
                );
                $emitted++;
                break;
            }
        }

        if ($emitted < self::MAX_LINKS_PER_NODE) {
            foreach ($this->labelTokens((string) $memory['label']) as $token) {
                if ($emitted >= self::MAX_LINKS_PER_NODE) {
                    break;
                }
                $module = $bySlug[$token] ?? null;
                if ($module === null) {
                    continue;
                }
                $edges[] = $this->edge(
                    from: $memory['id'],
                    to: $module['id'],
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'linker_memory_code',
                    confidence: self::CONFIDENCE_DERIVED,
                    meta: ['matched_token' => $token],
                );
                $emitted++;
            }
        }

        return $edges;
    }

    /**
     * (b) memory→domain 'belongs_to': only tags/metadata domain values that the
     * canonical taxonomy actually resolves (exact id resolution → 1.0).
     */
    private function linkMemoryToDomain(): int
    {
        $domainIds = $this->domainIdIndex();
        if ($domainIds === []) {
            return 0;
        }

        $edges = [];
        foreach ($this->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $edges = array_merge($edges, $this->memoryDomainEdgesFor($memory, $domainIds));
        }

        return $this->upsertEdges($edges);
    }

    /**
     * The memory→domain rung for ONE memory node — shared by the batch linker and
     * the F4 ingest-on-write accrual.
     *
     * @param  array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}  $memory
     * @param  array<string,string>  $domainIds  canonical domain id → brain node id
     * @return list<array<string,mixed>>
     */
    private function memoryDomainEdgesFor(array $memory, array $domainIds): array
    {
        $edges = [];
        foreach (array_slice((array) ($memory['meta']['domains'] ?? []), 0, self::MAX_META_DOMAINS) as $canonical) {
            if (! is_string($canonical) || ! isset($domainIds[$canonical])) {
                continue;
            }
            $edges[] = $this->edge(
                from: $memory['id'],
                to: $domainIds[$canonical],
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                source: 'linker_memory_domain',
                confidence: self::CONFIDENCE_EXACT,
                meta: ['matched_domain' => $canonical],
            );
        }

        return $edges;
    }

    /**
     * @param  list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $modules
     * @return array<string,array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>
     */
    private function moduleSlugIndex(array $modules): array
    {
        $bySlug = [];
        foreach ($modules as $module) {
            $slug = strtolower((string) ($module['meta']['slug'] ?? ''));
            if ($slug !== '') {
                $bySlug[$slug] = $module;
            }
        }

        return $bySlug;
    }

    /**
     * @return array<string,string> canonical domain id → brain node id
     */
    private function domainIdIndex(): array
    {
        $domainIds = [];
        foreach ($this->brainNodes('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN) as $domain) {
            $domainIds[$domain['source_id']] = $domain['id'];
        }

        return $domainIds;
    }

    /**
     * (c) evidence→memory/code 'proves': target_id / metadata memory id equal to a
     * memory source_id (1.0); file path matching a module root (exact 1.0 / prefix 0.7).
     */
    private function linkEvidence(): int
    {
        $evidence = $this->brainNodes('evidence', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE);
        if ($evidence === []) {
            return 0;
        }

        $memoryBydSourceId = [];
        foreach ($this->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $memoryBydSourceId[$memory['source_id']] = $memory['id'];
        }
        $modules = $this->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);

        $edges = [];
        foreach ($evidence as $node) {
            $emitted = 0;
            foreach (['target_id', 'memory_ref'] as $field) {
                $ref = $node['meta'][$field] ?? null;
                if (is_string($ref) && isset($memoryBydSourceId[$ref])) {
                    $edges[] = $this->edge(
                        from: $node['id'],
                        to: $memoryBydSourceId[$ref],
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_PROVES,
                        source: 'linker_evidence',
                        confidence: self::CONFIDENCE_EXACT,
                        meta: ['matched' => $field, 'value' => $ref],
                    );
                    $emitted++;
                }
            }

            foreach (array_values(array_filter((array) ($node['meta']['paths'] ?? []), 'is_string')) as $path) {
                if ($emitted >= self::MAX_LINKS_PER_NODE) {
                    break;
                }
                foreach ($modules as $module) {
                    $rootPath = (string) ($module['meta']['root_path'] ?? '');
                    if ($rootPath === '') {
                        continue;
                    }
                    $confidence = null;
                    if ($path === $rootPath) {
                        $confidence = self::CONFIDENCE_EXACT;
                    } elseif (str_starts_with($path, rtrim($rootPath, '/').'/')) {
                        $confidence = self::CONFIDENCE_DERIVED;
                    }
                    if ($confidence === null) {
                        continue;
                    }
                    $edges[] = $this->edge(
                        from: $node['id'],
                        to: $module['id'],
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_PROVES,
                        source: 'linker_evidence',
                        confidence: $confidence,
                        meta: ['matched_path' => $path, 'module_root' => $rootPath],
                    );
                    $emitted++;
                    break;
                }
            }
        }

        return $this->upsertEdges($edges);
    }

    /**
     * (d) code workspace→engineering domain 'belongs_to' (1.0 by construction:
     * a code workspace IS engineering reality).
     */
    private function linkWorkspaceToEngineeringDomain(): int
    {
        $engineering = $this->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, 'engineering');
        if (! AtlasAurgNode::query()->whereKey($engineering)->exists()) {
            return 0;
        }

        $edges = [];
        foreach ($this->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_WORKSPACE) as $workspace) {
            $edges[] = $this->edge(
                from: $workspace['id'],
                to: $engineering,
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                source: 'linker_code_domain',
                confidence: self::CONFIDENCE_EXACT,
                meta: ['matched' => 'workspace_is_code'],
            );
        }

        return $this->upsertEdges($edges);
    }

    // ------------------------------------------------------------------
    // Store primitives (idempotent upsert / prune / bounded reads)
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string,mixed>>  $nodes
     */
    private function upsertNodes(array $nodes): void
    {
        if ($nodes === []) {
            return;
        }

        $now = now();
        $rows = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (isset($seen[$node['id']])) {
                continue;
            }
            $seen[$node['id']] = true;
            $rows[] = [
                'id' => $node['id'],
                'kind' => $node['kind'],
                'source_kind' => $node['source_kind'],
                'source_id' => $node['source_id'],
                'label' => $node['label'],
                'workspace_id' => $node['workspace_id'],
                'provider_safe' => $node['provider_safe'],
                'sensitive' => $node['sensitive'],
                'meta' => json_encode($node['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                'content_hash' => $node['content_hash'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            AtlasAurgNode::query()->upsert(
                $chunk,
                ['id'],
                ['kind', 'source_kind', 'source_id', 'label', 'workspace_id', 'provider_safe', 'sensitive', 'meta', 'content_hash', 'updated_at'],
            );
        }
    }

    /**
     * Cite-or-omit at the store boundary too: an edge is only written when BOTH
     * endpoint nodes exist in the brain.
     *
     * @param  list<array<string,mixed>>  $edges
     * @return int number of edges upserted
     */
    private function upsertEdges(array $edges): int
    {
        if ($edges === []) {
            return 0;
        }

        // Dedup by (from,to,kind) keeping the HIGHEST-confidence citation — an
        // exact match (1.0) is never downgraded by a derived rung (0.7) of the
        // same linker pass. Deterministic regardless of emission order.
        $deduped = [];
        foreach ($edges as $edge) {
            if ($edge['from_node_id'] === $edge['to_node_id']) {
                continue;
            }
            $key = $edge['from_node_id'].'|'.$edge['to_node_id'].'|'.$edge['kind'];
            if (isset($deduped[$key]) && (float) $deduped[$key]['confidence'] >= (float) $edge['confidence']) {
                continue;
            }
            $deduped[$key] = $edge;
        }
        $edges = array_values($deduped);

        $endpointIds = [];
        foreach ($edges as $edge) {
            $endpointIds[$edge['from_node_id']] = true;
            $endpointIds[$edge['to_node_id']] = true;
        }
        $existing = [];
        foreach (array_chunk(array_keys($endpointIds), 500) as $chunk) {
            foreach (AtlasAurgNode::query()->whereIn('id', $chunk)->pluck('id') as $id) {
                $existing[$id] = true;
            }
        }

        $now = now();
        $rows = [];
        foreach ($edges as $edge) {
            if (! isset($existing[$edge['from_node_id']], $existing[$edge['to_node_id']])) {
                continue;
            }
            $rows[] = [
                'from_node_id' => $edge['from_node_id'],
                'to_node_id' => $edge['to_node_id'],
                'kind' => $edge['kind'],
                'source' => $edge['source'],
                'confidence' => $edge['confidence'],
                'meta' => json_encode($edge['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            AtlasAurgEdge::query()->upsert(
                $chunk,
                ['from_node_id', 'to_node_id', 'kind'],
                ['source', 'confidence', 'meta', 'updated_at'],
            );
        }

        return count($rows);
    }

    /**
     * Remove brain nodes of ONE source_kind whose source row vanished, plus edges
     * left dangling by those removals. Never touches other source kinds.
     *
     * @param  list<string>  $keepIds
     * @return array{nodes:int, edges:int}
     */
    private function pruneSource(string $sourceKind, array $keepIds): array
    {
        $staleQuery = AtlasAurgNode::query()->where('source_kind', $sourceKind);
        if ($keepIds !== []) {
            $staleQuery->whereNotIn('id', $keepIds);
        }
        $staleIds = $staleQuery->pluck('id')->all();
        if ($staleIds === []) {
            return ['nodes' => 0, 'edges' => 0];
        }

        $edgesDeleted = 0;
        $nodesDeleted = 0;
        foreach (array_chunk($staleIds, 500) as $chunk) {
            $edgesDeleted += AtlasAurgEdge::query()
                ->whereIn('from_node_id', $chunk)
                ->orWhereIn('to_node_id', $chunk)
                ->delete();
            $nodesDeleted += AtlasAurgNode::query()->whereIn('id', $chunk)->delete();
        }

        return ['nodes' => (int) $nodesDeleted, 'edges' => (int) $edgesDeleted];
    }

    /**
     * Bounded in-memory projection of brain nodes for a (source_kind, kind) pair.
     *
     * @return list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>
     */
    private function brainNodes(string $sourceKind, string $kind): array
    {
        $maxNodes = $this->cap('max_nodes', 5000);

        return AtlasAurgNode::query()
            ->where('source_kind', $sourceKind)
            ->where('kind', $kind)
            ->orderBy('id')
            ->limit($maxNodes)
            ->get(['id', 'source_id', 'label', 'kind', 'meta'])
            ->map(static fn (AtlasAurgNode $node): array => [
                'id' => (string) $node->id,
                'source_id' => (string) $node->source_id,
                'label' => (string) $node->label,
                'kind' => (string) $node->kind,
                'meta' => (array) ($node->meta ?? []),
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // Deterministic helpers (pure)
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function node(
        string $id,
        string $kind,
        string $sourceKind,
        string $sourceId,
        string $label,
        bool $providerSafe,
        bool $sensitive,
        array $meta,
        string $contentHash,
        ?string $workspaceId = null,
    ): array {
        return [
            'id' => $id,
            'kind' => $kind,
            'source_kind' => $sourceKind,
            'source_id' => mb_substr($sourceId, 0, 220),
            'label' => mb_substr(trim($label) !== '' ? trim($label) : $kind, 0, 220),
            'workspace_id' => $workspaceId,
            'provider_safe' => $providerSafe,
            'sensitive' => $sensitive,
            'meta' => $meta,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function edge(string $from, string $to, string $kind, string $source, float $confidence, array $meta): array
    {
        return [
            'from_node_id' => $from,
            'to_node_id' => $to,
            'kind' => $kind,
            'source' => $source,
            'confidence' => $confidence,
            'meta' => $meta,
        ];
    }

    /**
     * Deterministic node key "<source_kind>:<kind>:<source_id>", hard-capped at the
     * column width (300) with a hash suffix when a pathological source_id overflows.
     */
    private function nodeKey(string $sourceKind, string $kind, string $sourceId): string
    {
        $key = $sourceKind.':'.$kind.':'.$sourceId;
        if (strlen($key) <= 300) {
            return $key;
        }

        return substr($key, 0, 283).':'.substr(hash('sha256', $key), 0, 16);
    }

    /**
     * Path-looking strings from a source row's metadata/tags — the citations the
     * memory→code / evidence→code linkers may later match. Deterministic: explicit
     * fields first, then path-looking tags; bounded.
     *
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    private function candidatePaths(array $metadata): array
    {
        $candidates = [];
        foreach (['paths', 'files', 'related_paths'] as $field) {
            foreach ((array) ($metadata[$field] ?? []) as $value) {
                if (is_string($value) && str_contains($value, '/')) {
                    $candidates[] = trim($value);
                }
            }
        }
        foreach ((array) ($metadata['tags'] ?? []) as $tag) {
            if (is_string($tag) && str_contains($tag, '/')) {
                $candidates[] = trim($tag);
            }
        }

        return array_slice(array_values(array_unique(array_filter($candidates))), 0, self::MAX_META_PATHS);
    }

    /**
     * Canonical domain ids cited by a source row (tags + metadata domain fields),
     * resolved STRICTLY through the taxonomy — unknown ids are dropped, never invented.
     *
     * @param  array<int,mixed>  $tags
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    private function candidateDomains(array $tags, array $metadata): array
    {
        $raw = [];
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $raw[] = $tag;
            }
        }
        if (is_string($metadata['domain'] ?? null)) {
            $raw[] = $metadata['domain'];
        }
        foreach ((array) ($metadata['domains'] ?? []) as $value) {
            if (is_string($value)) {
                $raw[] = $value;
            }
        }

        $resolved = [];
        foreach ($raw as $candidate) {
            $canonical = $this->taxonomy->canonical($candidate);
            if ($canonical !== null) {
                $resolved[$canonical] = true;
            }
        }

        return array_slice(array_keys($resolved), 0, self::MAX_META_DOMAINS);
    }

    /**
     * Lowercased label tokens (≥4 chars) for the slug-equality linker rung.
     *
     * @return list<string>
     */
    private function labelTokens(string $label): array
    {
        $tokens = preg_split('/[^a-z0-9_-]+/i', strtolower($label)) ?: [];

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => strlen($t) >= 4)));
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function memoryRefFrom(array $metadata): ?string
    {
        foreach (['memory_entry_id', 'memory_id'] as $field) {
            if (is_string($metadata[$field] ?? null) && trim((string) $metadata[$field]) !== '') {
                return trim((string) $metadata[$field]);
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function sourceKindFor(string $source): string
    {
        return $source === 'domains' ? 'domain' : $source;
    }

    /**
     * Whether the source read-model is readable enough to make prune decisions.
     * Domains are constant-backed (taxonomy) → always available.
     */
    private function sourceAvailable(string $source): bool
    {
        return match ($source) {
            'memory' => $this->tableExists('atlas_memory_entries'),
            'code' => $this->tableExists('atlas_engineering_code_modules'),
            'domains' => true,
            'evidence' => $this->tableExists('atlas_engineering_evidence'),
            'strategic' => $this->tableExists('atlas_reality_entities'),
            default => false,
        };
    }

    private function cap(string $key, int $default): int
    {
        $value = (int) config('atlas.aurg.'.$key, $default);

        return $value > 0 ? $value : $default;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
