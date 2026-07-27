<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality\RealityGraphIngestion;

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
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;

/**
 * Source gatherers for {@see AtlasRealityGraphIngestionService} (GOD-DEBULK split).
 * Each gather* reads one REAL read-model into bounded provider-safe nodes/edges.
 * Depends only on {@see RealityGraphIngestionSupport} + injected taxonomy/mesh.
 */
class RealityGraphGatherSection
{
    public function __construct(
        private readonly RealityGraphIngestionSupport $support,
        private readonly CrossDomainTaxonomyMap $taxonomy,
        private readonly ?AtlasCrossDomainMeshService $mesh = null,
    ) {}

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function gatherMemory(): array
    {
        $nodes = [];
        $edges = [];

        if ($this->support->tableExists('atlas_memory_entries')) {
            $limit = $this->support->cap('memory_limit', 500);
            $query = AtlasMemoryEntry::query()->active();
            if (DatabaseTableAvailability::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
                $query->whereNull('superseded_by_id');
            }
            $entries = $query->latest('recorded_at')->limit($limit)->get();

            foreach ($entries as $entry) {
                // AOBG noise guard: skip contentless memory (smoke-test "t" echoes) — a
                // brain node with a <3-char title is pure noise that dominates packs.
                if (mb_strlen(trim((string) $entry->title)) < 3) {
                    continue;
                }
                $nodes[] = $this->support->memoryEntryNode($entry);
            }
        }

        if ($this->support->tableExists('atlas_verbatim_memories')) {
            $limit = $this->support->cap('memory_limit', 500);
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
                $nodes[] = $this->support->node(
                    id: $this->support->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $verbatim->id),
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
                        'paths' => $this->support->candidatePaths(array_merge(
                            (array) ($verbatim->metadata ?? []),
                            ['tags' => (array) ($verbatim->tags ?? [])],
                        )),
                        'domains' => $this->support->candidateDomains((array) ($verbatim->tags ?? []), (array) ($verbatim->metadata ?? [])),
                    ],
                    contentHash: (string) ($verbatim->redacted_hash ?: $verbatim->content_hash ?: hash('sha256', $label)),
                );

                // Exact FK → parent memory entry (confidence 1.0, exact id).
                if (is_string($verbatim->memory_entry_id) && $verbatim->memory_entry_id !== '') {
                    $edges[] = $this->support->edge(
                        from: $this->support->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $verbatim->id),
                        to: $this->support->nodeKey('memory', AtlasRealityGraphSnapshotBuilderService::NODE_MEMORY_ENTRY, (string) $verbatim->memory_entry_id),
                        kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                        source: 'memory_ingest',
                        confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                        meta: ['matched' => 'memory_entry_id'],
                    );
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function gatherCode(): array
    {
        $nodes = [];
        $edges = [];

        if (! $this->support->tableExists('atlas_engineering_code_modules')) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        $hasWorkspace = DatabaseTableAvailability::hasColumn('atlas_engineering_code_modules', 'workspace_id');
        $defaultWorkspace = (string) config('atlas.code_graph.default_workspace_id', 'atlas-server');
        $perWorkspace = $this->support->cap('modules_per_workspace', 300);

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

            $workspaceNodeId = $this->support->nodeKey('code', AtlasRealityGraphSnapshotBuilderService::NODE_WORKSPACE, $workspaceId);
            $nodes[] = $this->support->node(
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
                $moduleNodeId = $this->support->nodeKey('code', AtlasRealityGraphSnapshotBuilderService::NODE_MODULE, $sourceId);
                $nodes[] = $this->support->node(
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
                $edges[] = $this->support->edge(
                    from: $moduleNodeId,
                    to: $workspaceNodeId,
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                    source: 'code_ingest',
                    confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                    meta: ['matched' => 'workspace_id'],
                );
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function gatherDocs(): array
    {
        $nodes = [];
        $keepIds = [];
        $skippedUnchanged = 0;
        $root = base_path('docs/engineering-knowledge-base');
        if (! is_dir($root)) {
            return ['nodes' => $nodes, 'edges' => []];
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $absolute = str_replace('\\', '/', $file->getPathname());
            if (! str_starts_with($absolute, $base)) {
                continue;
            }
            $relative = substr($absolute, strlen($base));
            if (! str_starts_with($relative, 'docs/engineering-knowledge-base/')) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files, SORT_STRING);
        $files = array_slice($files, 0, $this->support->cap('docs_limit', 2000));

        $existingById = AtlasAurgNode::query()
            ->where('source_kind', 'doc')
            ->where('kind', AtlasRealityGraphSnapshotBuilderService::NODE_DOC)
            ->get(['id', 'meta'])
            ->keyBy('id');

        foreach ($files as $relative) {
            $absolute = base_path($relative);
            $nodeId = $this->support->nodeKey('doc', AtlasRealityGraphSnapshotBuilderService::NODE_DOC, $relative);
            $keepIds[] = $nodeId;
            $mtime = @filemtime($absolute);
            $size = @filesize($absolute);
            $existing = $existingById->get($nodeId);
            $existingMeta = $existing instanceof AtlasAurgNode ? (array) ($existing->meta ?? []) : [];
            if ($mtime !== false && $size !== false
                && (int) ($existingMeta['doc_mtime'] ?? -1) === (int) $mtime
                && (int) ($existingMeta['doc_size'] ?? -1) === (int) $size) {
                $skippedUnchanged++;

                continue;
            }

            $content = @file_get_contents($absolute);
            if (! is_string($content)) {
                continue;
            }

            $nodes[] = $this->support->node(
                id: $this->support->nodeKey('doc', AtlasRealityGraphSnapshotBuilderService::NODE_DOC, $relative),
                kind: AtlasRealityGraphSnapshotBuilderService::NODE_DOC,
                sourceKind: 'doc',
                sourceId: $this->support->compactSourceId($relative),
                label: $this->support->docTitle($relative, $content),
                providerSafe: true,
                sensitive: false,
                meta: [
                    'path' => $relative,
                    'doc_status' => 'canonical_engineering_knowledge',
                    'paths' => $this->support->existingRepoPathsFromText($content),
                    'memory_refs' => $this->support->memoryRefsFromText($content),
                    'doc_mtime' => $mtime !== false ? (int) $mtime : null,
                    'doc_size' => $size !== false ? (int) $size : null,
                ],
                contentHash: hash('sha256', $relative.'|'.hash('sha256', $content)),
            );
        }

        return [
            'nodes' => $nodes,
            'edges' => [],
            'keep_ids' => $keepIds,
            'stats' => ['skipped_unchanged' => $skippedUnchanged],
        ];
    }

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function gatherDomains(): array
    {
        $nodes = [];
        $edges = [];

        foreach ($this->taxonomy->all() as $canonical => $meta) {
            $nodes[] = $this->support->node(
                id: $this->support->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, (string) $canonical),
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
                $edges[] = $this->support->edge(
                    from: $this->support->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, $from),
                    to: $this->support->nodeKey('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN, $to),
                    kind: AtlasRealityGraphSnapshotBuilderService::EDGE_REFERENCES,
                    source: 'domain_mesh',
                    confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
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

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function gatherEvidence(): array
    {
        $nodes = [];

        if (! $this->support->tableExists('atlas_ledger_events')) {
            return ['nodes' => $nodes, 'edges' => []];
        }

        $limit = $this->support->cap('evidence_limit', 200);
        $query = AtlasLedgerEvent::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('event_id')
            ->limit($limit);

        $columns = ['event_id', 'event_type', 'trace_id', 'correlation_id', 'receipt_id', 'payload', 'payload_hash', 'occurred_at'];
        if (DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_type')) {
            $columns[] = 'scope_type';
        }
        if (DatabaseTableAvailability::hasColumn('atlas_ledger_events', 'scope_id')) {
            $columns[] = 'scope_id';
        }

        foreach ($query->get($columns) as $row) {
            $payload = $this->support->decodeJsonMap($row->payload ?? null);
            $scopeType = is_string($row->scope_type ?? null) ? (string) $row->scope_type : null;
            $scopeId = is_string($row->scope_id ?? null) ? (string) $row->scope_id : null;

            $nodes[] = $this->support->node(
                id: $this->support->nodeKey('evidence', AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE, (string) $row->event_id),
                kind: AtlasRealityGraphSnapshotBuilderService::NODE_EVIDENCE,
                sourceKind: 'evidence',
                sourceId: (string) $row->event_id,
                label: (string) $row->event_type,
                providerSafe: true,
                sensitive: false,
                // ids/hashes + cite-or-omit refs ONLY — raw payload never enters the brain.
                meta: [
                    'ledger_source' => 'atlas_ledger_events',
                    'trace_id' => $row->trace_id !== null ? (string) $row->trace_id : null,
                    'correlation_id' => $row->correlation_id !== null ? (string) $row->correlation_id : null,
                    'receipt_id' => $row->receipt_id !== null ? (string) $row->receipt_id : null,
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'target_id' => $this->support->ledgerTargetIdFrom($payload),
                    'memory_ref' => $this->support->ledgerMemoryRefFrom($payload, $scopeType, $scopeId),
                    'paths' => $this->support->ledgerEvidencePathsFrom($payload),
                    'payload_hash' => (string) $row->payload_hash,
                ],
                contentHash: hash('sha256', (string) $row->event_id.'|'.(string) $row->event_type.'|'.(string) $row->payload_hash),
            );
        }

        return ['nodes' => $nodes, 'edges' => []];
    }

    /**
     * @return array{nodes:list<array<string,mixed>>, edges:list<array<string,mixed>>}
     */
    public function gatherStrategic(): array
    {
        $nodes = [];
        $edges = [];

        if (! $this->support->tableExists('atlas_reality_entities')) {
            return ['nodes' => $nodes, 'edges' => $edges];
        }

        $limit = $this->support->cap('strategic_limit', 500);
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

            $nodes[] = $this->support->node(
                id: $this->support->nodeKey('strategic', $kind, (string) $entity->id),
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

        if ($this->support->tableExists('atlas_reality_relationships')) {
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

                $edges[] = $this->support->edge(
                    from: $this->support->nodeKey('strategic', $kindByEntity[$sourceId], $sourceId),
                    to: $this->support->nodeKey('strategic', $kindByEntity[$targetId], $targetId),
                    kind: $kind,
                    source: 'strategic_ingest',
                    confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
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
}
