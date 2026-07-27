<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality\RealityGraphIngestion;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;

/**
 * Cross-layer linkers for {@see AtlasRealityGraphIngestionService} (GOD-DEBULK split).
 * Deterministic, cite-or-omit edge emitters run over DB state after gathering.
 * Depends only on {@see RealityGraphIngestionSupport}.
 */
class RealityGraphLinkSection
{
    public function __construct(
        private readonly RealityGraphIngestionSupport $support,
    ) {}

    /**
     * (a) memory→code 'references': exact path = 1.0; path under module root /
     * label token equal to module slug = 0.7. Cites the matched value in meta.
     */
    public function linkMemoryToCode(): int
    {
        $modules = $this->support->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules === []) {
            return 0;
        }

        $bySlug = $this->support->moduleSlugIndex($modules);

        $edges = [];
        foreach ($this->support->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $edges = array_merge($edges, $this->support->memoryCodeEdgesFor($memory, $modules, $bySlug));
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * (b) memory→domain 'belongs_to': only tags/metadata domain values that the
     * canonical taxonomy actually resolves (exact id resolution → 1.0).
     */
    public function linkMemoryToDomain(): int
    {
        $domainIds = $this->support->domainIdIndex();
        if ($domainIds === []) {
            return 0;
        }

        $edges = [];
        foreach ($this->support->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $edges = array_merge($edges, $this->support->memoryDomainEdgesFor($memory, $domainIds));
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * (c) evidence→memory/code/mission/obra 'proves': target_id / metadata memory id
     * equal to a memory source_id (1.0); receipt/trace/correlation ids equal to
     * mission/obra meta ids (1.0); file path matching a module root (exact 1.0 /
     * prefix 0.7).
     */
    public function linkEvidence(): int
    {
        $evidence = $this->support->brainNodes('evidence', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE);
        if ($evidence === []) {
            return 0;
        }

        $memoryBydSourceId = [];
        foreach ($this->support->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $memoryBydSourceId[$memory['source_id']] = $memory['id'];
        }
        $modules = $this->support->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        $governedTargets = $this->evidenceGovernedTargetIndex();

        $edges = [];
        foreach ($evidence as $node) {
            $emitted = 0;
            $seenTargets = [];
            foreach (['target_id', 'memory_ref'] as $field) {
                $ref = $node['meta'][$field] ?? null;
                if (is_string($ref) && isset($memoryBydSourceId[$ref])) {
                    $edges[] = $this->support->edge(
                        from: $node['id'],
                        to: $memoryBydSourceId[$ref],
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_PROVES,
                        source: 'linker_evidence',
                        confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                        meta: ['matched' => $field, 'value' => $ref],
                    );
                    $emitted++;
                    $seenTargets[$memoryBydSourceId[$ref]] = true;
                }
            }

            foreach (['receipt_id', 'trace_id', 'correlation_id'] as $field) {
                if ($emitted >= AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
                    break;
                }
                $value = $node['meta'][$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                foreach ($governedTargets[$field][$value] ?? [] as $target) {
                    if ($emitted >= AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
                        break;
                    }
                    if (isset($seenTargets[$target['id']])) {
                        continue;
                    }
                    $edges[] = $this->support->edge(
                        from: $node['id'],
                        to: $target['id'],
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_PROVES,
                        source: 'linker_evidence',
                        confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                        meta: [
                            'matched' => $field,
                            'value' => $value,
                            'target_source_kind' => $target['source_kind'],
                        ],
                    );
                    $seenTargets[$target['id']] = true;
                    $emitted++;
                }
            }

            foreach (array_values(array_filter((array) ($node['meta']['paths'] ?? []), 'is_string')) as $path) {
                if ($emitted >= AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
                    break;
                }
                foreach ($modules as $module) {
                    $rootPath = (string) ($module['meta']['root_path'] ?? '');
                    if ($rootPath === '') {
                        continue;
                    }
                    $confidence = null;
                    if ($path === $rootPath) {
                        $confidence = AtlasRealityGraphIngestionService::CONFIDENCE_EXACT;
                    } elseif (str_starts_with($path, rtrim($rootPath, '/').'/')) {
                        $confidence = AtlasRealityGraphIngestionService::CONFIDENCE_DERIVED;
                    }
                    if ($confidence === null) {
                        continue;
                    }
                    $edges[] = $this->support->edge(
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

        return $this->support->upsertEdges($edges);
    }

    /**
     * MAXD-03 — Co-citation memory↔code via missions.
     *
     * For every mission that references BOTH a memory_entry AND a code module,
     * emit a memory→module `co_cited` edge (source=linker_co_cited, confidence
     * 0.5, meta={witness_mission_id}). Deterministic, cite-or-omit, bounded by
     * MAX_CO_CITED_PER_MEMORY per memory node (transitive-blow-up guard: a
     * mission that touched 30 paths and cited 5 memories would otherwise emit
     * a 150-edge cartesian product).
     *
     * Uses AtlasAurgEdge as the ground truth so it reflects EVERY producer of
     * mission→memory / mission→module edges — including cite-or-omit ones the
     * current run wrote seconds ago.
     */
    public function linkCoCitations(): int
    {
        if (! $this->support->tableExists('atlas_aurg_edges') || ! $this->support->tableExists('atlas_aurg_nodes')) {
            return 0;
        }

        // 1) Collect mission node ids.
        $missions = $this->support->brainNodes('mission', AtlasRealityGraphSnapshotBuilderService::NODE_MISSION);
        if ($missions === []) {
            return 0;
        }
        $missionNodeIds = array_column($missions, 'id');

        // 2) Deterministic index: mission -> memory endpoints and mission -> module endpoints,
        //    drawn from AURG edges. references+proves are both meaningful witnesses.
        $memoryByMission = [];
        $moduleByMission = [];

        $rows = DB::table('atlas_aurg_edges as edge')
            ->join('atlas_aurg_nodes as from_node', 'from_node.id', '=', 'edge.from_node_id')
            ->join('atlas_aurg_nodes as to_node', 'to_node.id', '=', 'edge.to_node_id')
            ->whereIn('from_node.id', $missionNodeIds)
            ->whereIn('to_node.source_kind', ['memory', 'code'])
            ->whereIn('edge.kind', [
                AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                AtlasRealityGraphSnapshotBuilderService::EDGE_PROVES,
            ])
            ->orderBy('edge.from_node_id')
            ->orderBy('edge.to_node_id')
            ->get([
                'from_node.id as mission_id',
                'to_node.id as endpoint_id',
                'to_node.source_kind as endpoint_source_kind',
                'to_node.kind as endpoint_kind',
            ]);

        foreach ($rows as $row) {
            $missionId = (string) $row->mission_id;
            $endpointId = (string) $row->endpoint_id;
            $kind = (string) $row->endpoint_kind;
            if ($row->endpoint_source_kind === 'memory'
                && $kind === AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) {
                $memoryByMission[$missionId][$endpointId] = true;
            } elseif ($row->endpoint_source_kind === 'code'
                && $kind === AtlasRealityGraphSnapshotBuilderService::NODE_MODULE) {
                $moduleByMission[$missionId][$endpointId] = true;
            }
        }

        // 3) Emit deterministic edges (memory→module) capped per memory.
        $emittedPerMemory = [];
        $seenPair = [];
        $edges = [];
        ksort($memoryByMission);
        foreach ($memoryByMission as $missionId => $memoryIds) {
            $moduleIds = $moduleByMission[$missionId] ?? [];
            if ($moduleIds === []) {
                continue;
            }
            $memoryIdsList = array_keys($memoryIds);
            $moduleIdsList = array_keys($moduleIds);
            sort($memoryIdsList);
            sort($moduleIdsList);
            foreach ($memoryIdsList as $memoryId) {
                if (($emittedPerMemory[$memoryId] ?? 0) >= AtlasRealityGraphIngestionService::MAX_CO_CITED_PER_MEMORY) {
                    continue;
                }
                foreach ($moduleIdsList as $moduleId) {
                    if (($emittedPerMemory[$memoryId] ?? 0) >= AtlasRealityGraphIngestionService::MAX_CO_CITED_PER_MEMORY) {
                        break;
                    }
                    $pairKey = $memoryId.'->'.$moduleId;
                    if (isset($seenPair[$pairKey])) {
                        continue;
                    }
                    $seenPair[$pairKey] = true;
                    $emittedPerMemory[$memoryId] = ($emittedPerMemory[$memoryId] ?? 0) + 1;
                    $edges[] = $this->support->edge(
                        from: $memoryId,
                        to: $moduleId,
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_CO_CITED,
                        source: 'linker_co_cited',
                        confidence: AtlasRealityGraphIngestionService::CONFIDENCE_CO_CITED,
                        meta: ['witness_mission_id' => $missionId],
                    );
                }
            }
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * (d) code workspace→engineering domain 'belongs_to' (1.0 by construction:
     * a code workspace IS engineering reality).
     */
    public function linkWorkspaceToEngineeringDomain(): int
    {
        $engineering = $this->support->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, 'engineering');
        if (! AtlasAurgNode::query()->whereKey($engineering)->exists()) {
            return 0;
        }

        $edges = [];
        foreach ($this->support->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_WORKSPACE) as $workspace) {
            $edges[] = $this->support->edge(
                from: $workspace['id'],
                to: $engineering,
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                source: 'linker_code_domain',
                confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                meta: ['matched' => 'workspace_is_code'],
            );
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * (e1) doc→code 'references': exact path = 1.0; path under module root = 0.7.
     */
    public function linkDocsToCode(): int
    {
        $modules = $this->support->brainNodes('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE);
        if ($modules === []) {
            return 0;
        }

        $edges = [];
        foreach ($this->support->brainNodes('doc', AtlasRealityGraphSnapshotBuilderService::NODE_DOC) as $doc) {
            $edges = array_merge($edges, $this->docCodeEdgesFor($doc, $modules));
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * MAXD-01: import audited Code Intelligence doc→code links as aggregated
     * doc→module edges. The symbols stay in Code Intelligence; AURG only stores the
     * bounded module-level bridge and cites the indexed link hash.
     */
    public function linkDocsToCodeIndex(): int
    {
        if (! $this->support->tableExists('atlas_engineering_doc_links') || ! $this->support->tableExists('atlas_engineering_code_modules')) {
            return 0;
        }

        $docNodeByPath = [];
        foreach ($this->support->brainNodes('doc', AtlasRealityGraphSnapshotBuilderService::NODE_DOC) as $doc) {
            $path = (string) ($doc['meta']['path'] ?? $doc['source_id']);
            if (str_starts_with($path, 'docs/engineering-knowledge-base/')) {
                $docNodeByPath[$path] = $doc['id'];
            }
        }
        if ($docNodeByPath === []) {
            return 0;
        }

        $defaultWorkspace = (string) config('atlas.code_graph.default_workspace_id', 'atlas-server');
        $moduleHasWorkspace = DatabaseTableAvailability::hasColumn('atlas_engineering_code_modules', 'workspace_id');
        $modulesById = [];
        $moduleQuery = DB::table('atlas_engineering_code_modules')
            ->where('status', 'active')
            ->whereNull('archived_at');
        if ($moduleHasWorkspace) {
            $moduleQuery->where('workspace_id', $defaultWorkspace);
        }
        $moduleColumns = $moduleHasWorkspace ? ['id', 'workspace_id', 'slug'] : ['id', 'slug'];
        foreach ($moduleQuery->get($moduleColumns) as $module) {
            $workspaceId = is_string($module->workspace_id ?? null) && (string) $module->workspace_id !== ''
                ? (string) $module->workspace_id
                : $defaultWorkspace;
            $modulesById[(string) $module->id] = $this->support->nodeKey(
                'code',
                AtlasRealityGraphSnapshotBuilderService::NODE_MODULE,
                $workspaceId.'/'.(string) $module->slug,
            );
        }
        if ($modulesById === []) {
            return 0;
        }

        $symbolModuleById = [];
        if ($this->support->tableExists('atlas_engineering_code_symbols')) {
            $symbolQuery = DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->whereNull('archived_at');
            if (DatabaseTableAvailability::hasColumn('atlas_engineering_code_symbols', 'workspace_id')) {
                $symbolQuery->where('workspace_id', $defaultWorkspace);
            }
            foreach ($symbolQuery->get(['id', 'module_id']) as $symbol) {
                if ($symbol->module_id !== null) {
                    $symbolModuleById[(string) $symbol->id] = (string) $symbol->module_id;
                }
            }
        }

        $linkQuery = DB::table('atlas_engineering_doc_links')
            ->where('status', 'current')
            ->whereNull('archived_at')
            ->where('canonical_path', 'like', 'docs/engineering-knowledge-base/%')
            ->orderBy('canonical_path')
            ->orderBy('link_hash');
        if (DatabaseTableAvailability::hasColumn('atlas_engineering_doc_links', 'workspace_id')) {
            $linkQuery->where('workspace_id', $defaultWorkspace);
        }

        $groups = [];
        foreach ($linkQuery->get(['canonical_path', 'module_id', 'symbol_id', 'link_type', 'link_hash']) as $link) {
            $docPath = (string) $link->canonical_path;
            $docNodeId = $docNodeByPath[$docPath] ?? null;
            if ($docNodeId === null) {
                continue;
            }

            $moduleId = is_string($link->module_id ?? null) && (string) $link->module_id !== ''
                ? (string) $link->module_id
                : ($symbolModuleById[(string) ($link->symbol_id ?? '')] ?? null);
            if ($moduleId === null) {
                continue;
            }
            $moduleNodeId = $modulesById[$moduleId] ?? null;
            if ($moduleNodeId === null) {
                continue;
            }

            $key = $docNodeId.'|'.$moduleNodeId;
            $groups[$key] ??= [
                'from' => $docNodeId,
                'to' => $moduleNodeId,
                'link_hashes' => [],
                'link_types' => [],
            ];
            $groups[$key]['link_hashes'][] = (string) $link->link_hash;
            $groups[$key]['link_types'][(string) $link->link_type] = true;
        }

        $edges = [];
        foreach ($groups as $group) {
            $linkHashes = array_values(array_unique(array_filter($group['link_hashes'], 'is_string')));
            sort($linkHashes, SORT_STRING);
            $linkTypes = array_keys($group['link_types']);
            sort($linkTypes, SORT_STRING);

            $edges[] = $this->support->edge(
                from: $group['from'],
                to: $group['to'],
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                source: 'linker_doc_code_index',
                confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                meta: [
                    'link_count' => count($linkHashes),
                    'sample_link_hash' => $linkHashes[0] ?? null,
                    'link_types' => $linkTypes,
                ],
            );
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * MAXD-09: import the docs authority read-model as bounded doc→doc edges.
     * A row only links when its needle is an explicit canonical doc path and both
     * endpoints are already AURG doc nodes; capability/doc-id needles remain in the
     * authority table for locate(), not guessed into graph edges.
     */
    public function linkDocsToAuthorityGraph(): int
    {
        if (! $this->support->tableExists('atlas_docs_authority_graph')) {
            return 0;
        }

        $docNodeByPath = [];
        foreach ($this->support->brainNodes('doc', AtlasRealityGraphSnapshotBuilderService::NODE_DOC) as $doc) {
            $path = (string) ($doc['meta']['path'] ?? $doc['source_id']);
            if (str_starts_with($path, 'docs/engineering-knowledge-base/')) {
                $docNodeByPath[$path] = $doc['id'];
            }
        }
        if ($docNodeByPath === []) {
            return 0;
        }

        $rows = DB::table('atlas_docs_authority_graph')
            ->whereIn('needle_kind', ['doc_path', 'doc'])
            ->where('needle', 'like', 'docs/engineering-knowledge-base/%')
            ->where('owner_doc_path', 'like', 'docs/engineering-knowledge-base/%')
            ->orderBy('needle')
            ->orderByDesc('confidence')
            ->orderBy('owner_doc_path')
            ->limit($this->support->cap('docs_authority_links_limit', 10000))
            ->get(['needle_kind', 'needle', 'owner_doc_path', 'owner_basis', 'confidence', 'owner_doc_id']);

        $edges = [];
        foreach ($rows as $row) {
            $from = $docNodeByPath[(string) $row->needle] ?? null;
            $to = $docNodeByPath[(string) $row->owner_doc_path] ?? null;
            if ($from === null || $to === null || $from === $to) {
                continue;
            }
            $edges[] = $this->support->edge(
                from: $from,
                to: $to,
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                source: 'linker_doc_authority',
                confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                meta: array_filter([
                    'needle_kind' => (string) $row->needle_kind,
                    'needle' => (string) $row->needle,
                    'owner_basis' => (string) $row->owner_basis,
                    'authority_confidence' => (int) $row->confidence,
                    'owner_doc_id' => is_string($row->owner_doc_id ?? null) ? (string) $row->owner_doc_id : null,
                ], static fn ($value): bool => $value !== null),
            );
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * (e2) doc→memory 'references': exact memory id match only.
     */
    public function linkDocsToMemory(): int
    {
        $docs = $this->support->brainNodes('doc', AtlasRealityGraphSnapshotBuilderService::NODE_DOC);
        if ($docs === []) {
            return 0;
        }

        $memoryBySourceId = [];
        foreach ($this->support->brainNodes('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY) as $memory) {
            $memoryBySourceId[$memory['source_id']] = $memory['id'];
        }
        if ($memoryBySourceId === []) {
            return 0;
        }

        $edges = [];
        foreach ($docs as $doc) {
            $emitted = 0;
            $seen = [];
            foreach (array_values(array_filter((array) ($doc['meta']['memory_refs'] ?? []), 'is_string')) as $ref) {
                if ($emitted >= AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
                    break;
                }
                $target = $memoryBySourceId[$ref] ?? null;
                if ($target === null || isset($seen[$target])) {
                    continue;
                }
                $edges[] = $this->support->edge(
                    from: $doc['id'],
                    to: $target,
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'linker_doc_memory',
                    confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                    meta: ['matched_memory_id' => $ref],
                );
                $seen[$target] = true;
                $emitted++;
            }
        }

        return $this->support->upsertEdges($edges);
    }

    /**
     * Mission/obra targets whose ids are already present in the brain. This is
     * strict cite-or-omit: only explicit receipt/trace/correlation fields create
     * an index entry, and only exact event values can link.
     *
     * @return array<string,array<string,list<array{id:string,source_kind:string}>>>
     */
    public function evidenceGovernedTargetIndex(): array
    {
        $index = [
            'receipt_id' => [],
            'trace_id' => [],
            'correlation_id' => [],
        ];

        foreach ([
            ['source_kind' => 'mission', 'kind' => AtlasRealityGraphSnapshotBuilderService::NODE_MISSION],
            ['source_kind' => 'obra', 'kind' => AtlasRealityGraphSnapshotBuilderService::NODE_OBRA],
        ] as $targetSpec) {
            foreach ($this->support->brainNodes($targetSpec['source_kind'], $targetSpec['kind']) as $node) {
                $target = ['id' => $node['id'], 'source_kind' => $targetSpec['source_kind']];
                foreach (['receipt', 'receipt_hash', 'receipt_id'] as $metaField) {
                    foreach ($this->support->exactMetaStrings($node['meta'][$metaField] ?? null) as $value) {
                        $index['receipt_id'][$value][] = $target;
                    }
                }
                foreach (['trace_id', 'trace_ids', 'trace', 'traces'] as $metaField) {
                    foreach ($this->support->exactMetaStrings($node['meta'][$metaField] ?? null) as $value) {
                        $index['trace_id'][$value][] = $target;
                    }
                }
                foreach (['correlation_id', 'correlation_ids', 'correlation', 'correlations'] as $metaField) {
                    foreach ($this->support->exactMetaStrings($node['meta'][$metaField] ?? null) as $value) {
                        $index['correlation_id'][$value][] = $target;
                    }
                }
            }
        }

        return $index;
    }

    /**
     * @param  array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}  $doc
     * @param  list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $modules
     * @return list<array<string,mixed>>
     */
    public function docCodeEdgesFor(array $doc, array $modules): array
    {
        $edges = [];
        $emitted = 0;
        $linked = [];
        $paths = array_values(array_filter((array) ($doc['meta']['paths'] ?? []), 'is_string'));

        foreach ($paths as $path) {
            if ($emitted >= AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
                break;
            }
            foreach ($modules as $module) {
                $rootPath = (string) ($module['meta']['root_path'] ?? '');
                if ($rootPath === '' || isset($linked[$module['id']])) {
                    continue;
                }
                $confidence = null;
                if ($path === $rootPath) {
                    $confidence = AtlasRealityGraphIngestionService::CONFIDENCE_EXACT;
                } elseif (str_starts_with($path, rtrim($rootPath, '/').'/')) {
                    $confidence = AtlasRealityGraphIngestionService::CONFIDENCE_DERIVED;
                }
                if ($confidence === null) {
                    continue;
                }
                $edges[] = $this->support->edge(
                    from: $doc['id'],
                    to: $module['id'],
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'linker_doc_code',
                    confidence: $confidence,
                    meta: ['matched_path' => $path, 'module_root' => $rootPath],
                );
                $linked[$module['id']] = true;
                $emitted++;
                break;
            }
        }

        return $edges;
    }
}
