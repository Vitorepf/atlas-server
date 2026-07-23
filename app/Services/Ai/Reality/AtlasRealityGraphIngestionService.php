<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\Services\Ai\Reality\RealityGraphIngestion\RealityGraphGatherSection;
use App\Services\Ai\Reality\RealityGraphIngestion\RealityGraphLinkSection;
use App\Services\Ai\Reality\RealityGraphIngestion\RealityGraphIngestionSupport;

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
 *   docs      — canonical engineering knowledge docs under docs/engineering-knowledge-base
 *               as doc refs only: path/title/hash + cite-or-omit path/memory refs.
 *   domains   — the 21 canonical domains from {@see CrossDomainTaxonomyMap}
 *               (sensitive flag from the map) + mesh allowed-crossing edges from the
 *               EXISTING {@see AtlasCrossDomainMeshService} topology (reuse, not
 *               recreate). Sensitive domains are provider_safe=false.
 *   evidence  — recent N atlas_ledger_events rows (Evidence Ledger, append-only
 *               runtime store) as refs: label = event_type, meta = ids/hashes +
 *               cite-or-omit paths/memory refs ONLY, never payloads. The dead
 *               atlas_engineering_evidence table is NOT read (RAG-09).
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
 *   (c) evidence→memory/code/mission/obra 'proves' — evidence target_id / metadata
 *       memory id equal to a memory source_id (1.0), receipt/trace/correlation ids
 *       equal to mission/obra meta ids (1.0), or an evidence file path matching a
 *       module root_path (exact 1.0 / prefix 0.7);
 *   (d) code workspace→engineering domain 'belongs_to' (1.0, by construction).
 *   (e) doc→code/doc→memory 'references' — canonical doc citations that resolve
 *       to existing repo paths / ingested memory nodes.
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
    public const SOURCES = ['memory', 'code', 'docs', 'domains', 'evidence', 'strategic'];

    public const CONFIDENCE_EXACT = 1.0;

    public const CONFIDENCE_DERIVED = 0.7;

    /**
     * MAXD-03 co-citation rung: an inference from a shared mission witness, not
     * a direct linker match — kept below EXACT/DERIVED on purpose.
     */
    public const CONFIDENCE_CO_CITED = 0.5;

    /** Per-node cap on linker-discovered candidate paths kept in meta. */
    public const MAX_META_PATHS = 10;

    /** Per-node cap on resolved canonical domains kept in meta. */
    public const MAX_META_DOMAINS = 5;

    /** Per-memory/evidence cap on emitted linker edges (bound, deterministic order). */
    public const MAX_LINKS_PER_NODE = 10;

    /** MAXD-03: cap on emitted co-cited edges per memory node (transitive-blow-up guard). */
    public const MAX_CO_CITED_PER_MEMORY = 5;

    private readonly RealityGraphIngestionSupport $support;

    private readonly RealityGraphGatherSection $gatherSection;

    private readonly RealityGraphLinkSection $linkSection;

    public function __construct(
        private readonly CrossDomainTaxonomyMap $taxonomy,
        private readonly AtlasMemoryPrivacyService $memoryPrivacy,
        private readonly ?AtlasCrossDomainMeshService $mesh = null,
    ) {
        $this->support = new RealityGraphIngestionSupport($taxonomy, $memoryPrivacy);
        $this->gatherSection = new RealityGraphGatherSection($this->support, $taxonomy, $mesh);
        $this->linkSection = new RealityGraphLinkSection($this->support);
    }

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
                'memory' => $this->gatherSection->gatherMemory(),
                'code' => $this->gatherSection->gatherCode(),
                'docs' => $this->gatherSection->gatherDocs(),
                'domains' => $this->gatherSection->gatherDomains(),
                'evidence' => $this->gatherSection->gatherEvidence(),
                'strategic' => $this->gatherSection->gatherStrategic(),
            };

            $this->support->upsertNodes($gathered['nodes']);
            $edgeCount = $this->support->upsertEdges($gathered['edges']);

            $stats['sources'][$source] = [
                'nodes' => count($gathered['nodes']),
                'edges' => $edgeCount,
            ] + (array) ($gathered['stats'] ?? []);

            // Prune only when the source read-model is actually readable — a missing
            // source table means "cannot verify vanishing", not "everything vanished"
            // (honest degrade: never wipe a layer on infrastructure absence).
            if ($prune && $this->support->sourceAvailable($source)) {
                $keepIds = (array) ($gathered['keep_ids'] ?? array_column($gathered['nodes'], 'id'));
                $pruned = $this->support->pruneSource($this->support->sourceKindFor($source), $keepIds);
                $stats['pruned']['nodes'] += $pruned['nodes'];
                $stats['pruned']['edges'] += $pruned['edges'];
                foreach ($pruned as $key => $value) {
                    if (! in_array($key, ['nodes', 'edges'], true)) {
                        $stats['pruned'][$key] = (int) ($stats['pruned'][$key] ?? 0) + (int) $value;
                    }
                }
            }
        }

        // Cross-layer linkers run over DB state (not just this run's batch) so a
        // partial --source sync still links against previously ingested layers.
        $stats['linkers'] = [
            'memory_code' => $this->linkSection->linkMemoryToCode(),
            'memory_domain' => $this->linkSection->linkMemoryToDomain(),
            'evidence_links' => $this->linkSection->linkEvidence(),
            'code_domain' => $this->linkSection->linkWorkspaceToEngineeringDomain(),
            'doc_code' => $this->linkSection->linkDocsToCode(),
            'doc_code_index' => $this->linkSection->linkDocsToCodeIndex(),
            'doc_authority' => $this->linkSection->linkDocsToAuthorityGraph(),
            'doc_memory' => $this->linkSection->linkDocsToMemory(),
            // MAXD-03 co-citation: emit memory→module edges when a mission
            // witnessed both. Runs AFTER the direct linkers so the transitive
            // sees the current state of mission→memory / mission→module edges.
            'co_cited' => $this->linkSection->linkCoCitations(),
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
        if (! $this->support->tableExists('atlas_aurg_nodes') || ! $this->support->tableExists('atlas_aurg_edges')) {
            return false;
        }
        if (! $entry->getKey()) {
            return false;
        }
        if ((string) $entry->status !== 'active' || $entry->archived_at !== null) {
            return false;
        }
        if (DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'superseded_by_id') && $entry->superseded_by_id !== null) {
            return false;
        }

        $node = $this->support->memoryEntryNode($entry);
        $this->support->upsertNodes([$node]);

        // Row-scoped linkers: the SAME deterministic rules, THIS node only.
        $memory = [
            'id' => (string) $node['id'],
            'source_id' => (string) $node['source_id'],
            'label' => (string) $node['label'],
            'kind' => (string) $node['kind'],
            'meta' => (array) $node['meta'],
        ];

        $edges = [];
        $modules = $this->support->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules !== []) {
            $edges = $this->support->memoryCodeEdgesFor($memory, $modules, $this->support->moduleSlugIndex($modules));
        }
        $domainIds = $this->support->domainIdIndex();
        if ($domainIds !== []) {
            $edges = array_merge($edges, $this->support->memoryDomainEdgesFor($memory, $domainIds));
        }
        $this->support->upsertEdges($edges);

        return true;
    }

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
     *                                        provider?, receipt?, files?:list<string>, measure?:array{status?,ok?},
     *                                        memory_refs?:list<string>}
     * @return array<string,mixed> {recorded(bool), reason?, mission_node?, evidence_node?, edges?:int}
     */
    public function recordMissionOutcome(array $outcome): array
    {
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['recorded' => false, 'reason' => 'aurg_disabled'];
        }
        if (! $this->support->tableExists('atlas_aurg_nodes') || ! $this->support->tableExists('atlas_aurg_edges')) {
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

        // AOBG noise guard (anti-pollution): a mission node must represent a REAL
        // delivery — not a raw chat/prompt fragment. Skip when there is NO delivered
        // artifact at all (not delivered, no branch, no files) OR the request is
        // contentless. This stops the session-capture path from recording prompt/chat
        // text as fake missions (the "acredito que..." / "Continue from where you left
        // off." pollution) and keeps every context pack high-signal.
        if ((! $delivered && $branch === '' && $files === []) || mb_strlen($request) < 6) {
            return ['recorded' => false, 'reason' => 'no_delivered_artifact_or_contentless'];
        }

        // 1) MISSION node — request label (redacted), branch + ids/hashes only.
        $missionNodeId = $this->support->nodeKey('mission', AtlasRealityGraphSnapshotBuilderService::NODE_MISSION, $id);
        $missionNode = $this->support->node(
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
                'evidence_refs' => ($evidenceRefMeta = array_slice(
                    array_values(array_filter((array) ($outcome['evidence_refs'] ?? []), 'is_string')),
                    0,
                    12,
                )) !== [] ? $evidenceRefMeta : null,
                // Provenance marker: a mission minted from an EXTERNAL session record
                // (AOBG write-back) carries its origin so render surfaces can refuse to
                // present its raw request label as an operator "decision" (session echo).
                'origin' => is_string($outcome['origin'] ?? null) && trim((string) $outcome['origin']) !== ''
                    ? trim((string) $outcome['origin'])
                    : null,
            ], static fn ($v): bool => $v !== null),
            // State fingerprint: request + branch + delivered + receipt — re-recording
            // an unchanged outcome yields the same hash (idempotent, deterministic).
            contentHash: hash('sha256', $id.'|'.$request.'|'.$branch.'|'.($delivered ? '1' : '0').'|'.((string) $receipt)),
        );

        // 2) EVIDENCE node — the test/measure RESULT for this mission (no payloads).
        $measureStatus = is_string($measure['status'] ?? null)
            ? (string) $measure['status']
            : (array_key_exists('ok', $measure) ? ((bool) $measure['ok'] ? 'passed' : 'failed') : ($delivered ? 'delivered' : 'blocked'));
        $evidenceNodeId = $this->support->nodeKey('mission', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE, $id);
        $evidenceNode = $this->support->node(
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

        $this->support->upsertNodes([$missionNode, $evidenceNode]);

        // 3) EDGES — generated (mission→evidence) + cite-or-omit references.
        $edges = [];

        // mission --generated--> evidence (1.0, by construction).
        $edges[] = $this->support->edge(
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
        $modules = $this->support->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules !== [] && $files !== []) {
            $edges = array_merge($edges, $this->missionTouchedModuleEdges($missionNodeId, $files, $modules, $this->support->moduleSlugIndex($modules)));
        }

        // mission --references--> memory_entry (1.0) for cited, existing memory nodes.
        if ($memoryRefs !== []) {
            $edges = array_merge($edges, $this->missionMemoryEdges($missionNodeId, $memoryRefs));
        }

        $edgeCount = $this->support->upsertEdges($edges);

        return [
            'recorded' => true,
            'mission_node' => $missionNodeId,
            'evidence_node' => $evidenceNodeId,
            'edges' => $edgeCount,
        ];
    }

    /**
     * AOBG N3.F3 ("the obra compounds"): after the obra executor walks the whole
     * plan-DAG onto ONE branch and the integrated certification runs, record the
     * OBRA ITSELF back INTO the fused store so the NEXT obra's brain query sees this
     * one. Unlike {@see self::recordMissionOutcome()} (which records ONE node's
     * delivery), this records the obra as a first-class unit raised above its steps:
     * writes, all under the 'obra' source_kind (its own prune scope — never touched
     * by the 5 read-model syncs):
     *
     *   - an OBRA node (kind=obra) labelled with the intent/title, carrying the
     *     branch ref + certified flag + step counts + receipt hash in meta (NEVER
     *     source, NEVER diffs);
     *   - an EVIDENCE node (kind=evidence) for the INTEGRATED certification RESULT
     *     (the whole-branch test — status + branch + receipt hash, no payloads);
     *   - obra --generated--> evidence (1.0, by construction: the obra produced
     *     exactly this integrated certification);
     *   - obra --generated--> mission (1.0) for every STEP node already recorded in
     *     the brain by {@see self::recordMissionOutcome()} whose source_id is in the
     *     supplied step id list — cite-or-omit: an unknown step id emits nothing (so
     *     the obra links only to steps that genuinely recorded their outcome).
     *
     * Contracts (identical floor to recordMissionOutcome):
     *   - PRIVACY: only ids/hashes/labels/branch-ref enter the brain. The intent
     *     label is redacted via AtlasSecurity; provider_safe=true, sensitive=false.
     *   - HONEST: certified=false (a needs_review obra whose integration failed) is
     *     recorded TRUTHFULLY — the evidence node's status is 'failed'/'needs_review'
     *     and the obra meta's certified flag is false. The brain never claims a green
     *     obra that did not integrate.
     *   - NEVER-MERGE: the recorded ref is the BRANCH (atlas/obra/<id>), never a merge.
     *   - IDEMPOTENT: nodes upsert on the deterministic key, edges on (from,to,kind);
     *     re-recording the same obra is a no-op.
     *   - HONEST-SKIP / FAIL-OPEN at the boundary: returns recorded=false without
     *     writing when the brain is disabled or its tables are absent (the caller
     *     wraps this so a brain outage never breaks the obra).
     *
     * @param  array<string,mixed>  $outcome  {id, intent|request, branch, certified(bool),
     *                                        status?, delivered_steps?:int, total_steps?:int, receipt_hash?:string,
     *                                        step_ids?:list<string>, integrated_status?:string}
     * @return array<string,mixed> {recorded(bool), reason?, obra_node?, evidence_node?,
     *                             step_edges?:int, edges?:int}
     */
    public function recordObraOutcome(array $outcome): array
    {
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['recorded' => false, 'reason' => 'aurg_disabled'];
        }
        if (! $this->support->tableExists('atlas_aurg_nodes') || ! $this->support->tableExists('atlas_aurg_edges')) {
            return ['recorded' => false, 'reason' => 'store_missing'];
        }

        $id = trim((string) ($outcome['id'] ?? ''));
        $intent = trim((string) ($outcome['intent'] ?? $outcome['request'] ?? ''));
        if ($id === '' || $intent === '') {
            return ['recorded' => false, 'reason' => 'id_and_intent_required'];
        }

        $branch = trim((string) ($outcome['branch'] ?? ''));
        $certified = (bool) ($outcome['certified'] ?? false);
        $receiptHash = isset($outcome['receipt_hash']) && is_string($outcome['receipt_hash']) ? $outcome['receipt_hash'] : null;
        $deliveredSteps = (int) ($outcome['delivered_steps'] ?? 0);
        $totalSteps = (int) ($outcome['total_steps'] ?? 0);
        // The honest whole-obra status (certified | needs_review | failed) — never
        // forced to a green word; defaults from the certified flag when not given.
        $obraStatus = is_string($outcome['status'] ?? null) && (string) $outcome['status'] !== ''
            ? (string) $outcome['status']
            : ($certified ? 'certified' : 'needs_review');
        $integratedStatus = is_string($outcome['integrated_status'] ?? null) && (string) $outcome['integrated_status'] !== ''
            ? (string) $outcome['integrated_status']
            : ($certified ? 'passed' : 'failed');
        $stepIds = array_values(array_filter((array) ($outcome['step_ids'] ?? []), 'is_string'));

        // 1) OBRA node — intent label (redacted), branch + flags/counts/hash only.
        $obraNodeId = $this->support->nodeKey('obra', AtlasRealityGraphSnapshotBuilderService::NODE_OBRA, $id);
        $obraNode = $this->support->node(
            id: $obraNodeId,
            kind: AtlasRealityGraphSnapshotBuilderService::NODE_OBRA,
            sourceKind: 'obra',
            sourceId: $id,
            label: AtlasSecurity::redactString($intent),
            providerSafe: true,
            sensitive: false,
            meta: array_filter([
                'branch' => $branch !== '' ? $branch : null,
                'certified' => $certified,
                'status' => $obraStatus,
                'delivered_steps' => $deliveredSteps,
                'total_steps' => $totalSteps,
                'receipt_hash' => $receiptHash,
                'never_merged' => true,
            ], static fn ($v): bool => $v !== null),
            // State fingerprint: id + branch + certified + receipt — re-recording an
            // unchanged obra outcome yields the same hash (idempotent, deterministic).
            contentHash: hash('sha256', 'obra|'.$id.'|'.$branch.'|'.($certified ? '1' : '0').'|'.((string) $receiptHash)),
        );

        // 2) EVIDENCE node — the INTEGRATED certification RESULT (no payloads).
        $evidenceNodeId = $this->support->nodeKey('obra', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE, $id);
        $evidenceNode = $this->support->node(
            id: $evidenceNodeId,
            kind: AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE,
            sourceKind: 'obra',
            sourceId: $id,
            label: 'obra_certification',
            providerSafe: true,
            sensitive: false,
            meta: array_filter([
                'obra_id' => $id,
                'status' => $integratedStatus,
                'certified' => $certified,
                'branch' => $branch !== '' ? $branch : null,
                'receipt_hash' => $receiptHash,
            ], static fn ($v): bool => $v !== null),
            contentHash: hash('sha256', 'obra_certification|'.$id.'|'.$integratedStatus.'|'.$branch.'|'.($certified ? '1' : '0')),
        );

        $this->support->upsertNodes([$obraNode, $evidenceNode]);

        // 3) EDGES — obra --generated--> evidence (1.0) + obra --generated--> step
        //    missions (1.0, cite-or-omit: only steps already recorded in the brain).
        $edges = [];
        $edges[] = $this->support->edge(
            from: $obraNodeId,
            to: $evidenceNodeId,
            kind: AtlasRealityGraphSnapshotBuilderService::EDGE_GENERATED,
            source: 'obra_outcome',
            confidence: self::CONFIDENCE_EXACT,
            meta: array_filter([
                'branch' => $branch !== '' ? $branch : null,
                'status' => $integratedStatus,
            ], static fn ($v): bool => $v !== null),
        );

        $stepEdges = $this->obraStepGeneratedEdges($obraNodeId, $stepIds);
        $edges = array_merge($edges, $stepEdges);

        $edgeCount = $this->support->upsertEdges($edges);

        return [
            'recorded' => true,
            'obra_node' => $obraNodeId,
            'evidence_node' => $evidenceNodeId,
            // How many step-mission edges were actually writable (both endpoints exist).
            'step_edges' => count($stepEdges),
            'edges' => $edgeCount,
        ];
    }

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
        if (! $this->support->tableExists('atlas_aurg_nodes') || ! $this->support->tableExists('atlas_aurg_edges')) {
            return ['recorded' => false, 'reason' => 'store_missing'];
        }

        $maxNodes = $this->support->cap('snapshot_max_nodes', 20000);
        $maxEdges = $this->support->cap('snapshot_max_edges', 60000);

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
                $edges[] = $this->support->edge(
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
                foreach ($this->support->labelTokens($path) as $token) {
                    $module = $bySlug[$token] ?? null;
                    if ($module === null || isset($linked[$module['id']])) {
                        continue;
                    }
                    $edges[] = $this->support->edge(
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
        foreach ($this->support->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
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
            $edges[] = $this->support->edge(
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

    /**
     * obra→mission 'generated' (1.0) for each step id whose mission node was already
     * recorded in the brain by {@see self::recordMissionOutcome()}. Cite-or-omit: a
     * step that never recorded its outcome (e.g. it was skipped/failed before the
     * write-back) emits no edge — the obra links only to steps that genuinely exist
     * in the brain.
     *
     * @param  list<string>  $stepIds  the per-node source ids (the executor's node ids)
     * @return list<array<string,mixed>>
     */
    private function obraStepGeneratedEdges(string $obraNodeId, array $stepIds): array
    {
        if ($stepIds === []) {
            return [];
        }

        // The step nodes are mission nodes recorded under the 'mission' source_kind.
        $missionBySourceId = [];
        foreach ($this->support->brainNodes('mission', AtlasRealityGraphSnapshotBuilderService::NODE_MISSION) as $mission) {
            $missionBySourceId[$mission['source_id']] = $mission['id'];
        }
        if ($missionBySourceId === []) {
            return [];
        }

        $edges = [];
        $seen = [];
        foreach ($stepIds as $stepId) {
            $stepId = trim($stepId);
            $target = $missionBySourceId[$stepId] ?? null;
            if ($target === null || isset($seen[$target])) {
                continue;
            }
            $edges[] = $this->support->edge(
                from: $obraNodeId,
                to: $target,
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_GENERATED,
                source: 'obra_outcome',
                confidence: self::CONFIDENCE_EXACT,
                meta: ['matched_step_id' => $stepId],
            );
            $seen[$target] = true;
        }

        return $edges;
    }
}
