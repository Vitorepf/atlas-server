<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality\RealityGraphIngestion;

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
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphSnapshotBuilderService;

/**
 * Leaf helpers for {@see AtlasRealityGraphIngestionService} (GOD-DEBULK split).
 * Pure node/edge builders, persistence (upsert/prune), shared cross-layer edge
 * builders and metadata/JSON/path parsing. Depends only on the injected read-model
 * collaborators and AtlasRealityGraphIngestionService public constants.
 */
class RealityGraphIngestionSupport
{
    public function __construct(
        private readonly CrossDomainTaxonomyMap $taxonomy,
        private readonly AtlasMemoryPrivacyService $memoryPrivacy,
    ) {}

    /**
     * The canonical brain projection of ONE memory entry — shared verbatim by the
     * full sync (gatherMemory) and the F4 ingest-on-write accrual so the per-row
     * path can never drift from the batch path. Label ONLY from the redacted
     * provider projection — never raw title.
     *
     * @return array<string,mixed>
     */
    public function memoryEntryNode(AtlasMemoryEntry $entry): array
    {
        $decision = $this->memoryPrivacy->providerDecision($entry);
        $privacyClass = (string) $decision['privacy_class'];
        $label = $this->memoryPrivacy->providerTitle($entry)
            ?? $this->memoryPrivacy->providerSummary($entry)
            ?? ('memory:'.$entry->memory_type);
        $metadata = (array) ($entry->metadata ?? []);
        $paths = $this->candidatePaths(array_merge(
            $metadata,
            ['tags' => (array) ($entry->tags ?? [])],
        ));
        if ((bool) $decision['allowed']) {
            $paths = array_slice(array_values(array_unique(array_merge(
                $paths,
                $this->providerProjectionPaths($entry),
            ))), 0, AtlasRealityGraphIngestionService::MAX_META_PATHS);
        }

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
                'paths' => $paths,
                'domains' => $this->candidateDomains((array) ($entry->tags ?? []), $metadata),
            ],
            contentHash: is_string($entry->content_hash) && $entry->content_hash !== ''
                ? $entry->content_hash
                : hash('sha256', $label.'|'.$entry->memory_type.'|'.$entry->scope_type),
        );
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
    public function memoryCodeEdgesFor(array $memory, array $modules, array $bySlug): array
    {
        $edges = [];
        $emitted = 0;
        $paths = array_values(array_filter((array) ($memory['meta']['paths'] ?? []), 'is_string'));

        foreach ($paths as $path) {
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

        if ($emitted < AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
            foreach ($this->labelTokens((string) $memory['label']) as $token) {
                if ($emitted >= AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE) {
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
                    confidence: AtlasRealityGraphIngestionService::CONFIDENCE_DERIVED,
                    meta: ['matched_token' => $token],
                );
                $emitted++;
            }
        }

        return $edges;
    }

    /**
     * The memory→domain rung for ONE memory node — shared by the batch linker and
     * the F4 ingest-on-write accrual.
     *
     * @param  array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}  $memory
     * @param  array<string,string>  $domainIds  canonical domain id → brain node id
     * @return list<array<string,mixed>>
     */
    public function memoryDomainEdgesFor(array $memory, array $domainIds): array
    {
        $edges = [];
        foreach (array_slice((array) ($memory['meta']['domains'] ?? []), 0, AtlasRealityGraphIngestionService::MAX_META_DOMAINS) as $canonical) {
            if (! is_string($canonical) || ! isset($domainIds[$canonical])) {
                continue;
            }
            $edges[] = $this->edge(
                from: $memory['id'],
                to: $domainIds[$canonical],
                kind: AtlasRealityGraphSnapshotBuilderService::EDGE_BELONGS_TO,
                source: 'linker_memory_domain',
                confidence: AtlasRealityGraphIngestionService::CONFIDENCE_EXACT,
                meta: ['matched_domain' => $canonical],
            );
        }

        return $edges;
    }

    /**
     * @param  list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>  $modules
     * @return array<string,array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>
     */
    public function moduleSlugIndex(array $modules): array
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
    public function domainIdIndex(): array
    {
        $domainIds = [];
        foreach ($this->brainNodes('domain', AtlasRealityGraphSnapshotBuilderService::NODE_DOMAIN) as $domain) {
            $domainIds[$domain['source_id']] = $domain['id'];
        }

        return $domainIds;
    }

    /**
     * @param  list<array<string,mixed>>  $nodes
     */
    public function upsertNodes(array $nodes): void
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
    public function upsertEdges(array $edges): int
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
    public function pruneSource(string $sourceKind, array $keepIds): array
    {
        $staleQuery = AtlasAurgNode::query()->where('source_kind', $sourceKind);
        if ($keepIds !== []) {
            $staleQuery->whereNotIn('id', $keepIds);
        }
        $staleIds = $staleQuery->pluck('id')->all();
        $keptLinked = 0;
        if ($sourceKind === 'evidence' && $staleIds !== []) {
            $linked = [];
            foreach (array_chunk($staleIds, 500) as $chunk) {
                $rows = AtlasAurgEdge::query()
                    ->whereIn('from_node_id', $chunk)
                    ->orWhereIn('to_node_id', $chunk)
                    ->get(['from_node_id', 'to_node_id']);
                foreach ($rows as $edge) {
                    foreach ([(string) $edge->from_node_id, (string) $edge->to_node_id] as $id) {
                        if (in_array($id, $chunk, true)) {
                            $linked[$id] = true;
                        }
                    }
                }
            }
            if ($linked !== []) {
                $staleIds = array_values(array_filter($staleIds, static fn (string $id): bool => ! isset($linked[$id])));
                $keptLinked = count($linked);
            }
        }
        if ($staleIds === []) {
            return ['nodes' => 0, 'edges' => 0, 'evidence_kept_linked' => $keptLinked];
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

        return ['nodes' => (int) $nodesDeleted, 'edges' => (int) $edgesDeleted, 'evidence_kept_linked' => $keptLinked];
    }

    /**
     * Bounded in-memory projection of brain nodes for a (source_kind, kind) pair.
     *
     * @return list<array{id:string, source_id:string, label:string, kind:string, meta:array<string,mixed>}>
     */
    public function brainNodes(string $sourceKind, string $kind): array
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

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    public function node(
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
    public function edge(string $from, string $to, string $kind, string $source, float $confidence, array $meta): array
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
    public function nodeKey(string $sourceKind, string $kind, string $sourceId): string
    {
        $key = $sourceKind.':'.$kind.':'.$sourceId;
        if (strlen($key) <= 300) {
            return $key;
        }

        return substr($key, 0, 283).':'.substr(hash('sha256', $key), 0, 16);
    }

    public function compactSourceId(string $sourceId): string
    {
        if (strlen($sourceId) <= 220) {
            return $sourceId;
        }

        return substr($sourceId, 0, 203).':'.substr(hash('sha256', $sourceId), 0, 16);
    }

    /**
     * Path-looking strings from a source row's metadata/tags — the citations the
     * memory→code / evidence→code linkers may later match. Deterministic: explicit
     * fields first, then path-looking tags; bounded.
     *
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    public function candidatePaths(array $metadata): array
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

        return array_slice(array_values(array_unique(array_filter($candidates))), 0, AtlasRealityGraphIngestionService::MAX_META_PATHS);
    }

    /**
     * Explicit repository paths cited by the already-redacted provider
     * projection. This is deterministic cite-or-omit extraction, not semantic
     * inference: only concrete paths rooted in a known repository directory
     * can create a memory→code edge.
     *
     * @return list<string>
     */
    public function providerProjectionPaths(AtlasMemoryEntry $entry): array
    {
        $text = implode("\n", array_filter([
            $this->memoryPrivacy->providerTitle($entry),
            $this->memoryPrivacy->providerSummary($entry),
            $this->memoryPrivacy->providerBody($entry),
        ]));
        if ($text === '') {
            return [];
        }

        preg_match_all(
            '~(?<![\pL\pN_])(?:app|tests|docs|config|routes|database|resources|scripts)/[A-Za-z0-9_./-]+~u',
            $text,
            $matches,
        );

        return array_slice(array_values(array_unique(array_filter(array_map(
            static fn (string $path): string => rtrim($path, ".,;:!?)]}'\"`"),
            $matches[0] ?? [],
        )))), 0, AtlasRealityGraphIngestionService::MAX_META_PATHS);
    }

    public function docTitle(string $path, string $content): string
    {
        if (preg_match('/^\s*#\s+(.+)$/m', $content, $matches) === 1) {
            return AtlasSecurity::redactString(trim((string) $matches[1]));
        }

        return basename($path, '.md');
    }

    /**
     * Existing repo-relative paths cited by a canonical doc (cite-or-omit).
     *
     * @return list<string>
     */
    public function existingRepoPathsFromText(string $text): array
    {
        preg_match_all(
            '~(?<![\pL\pN_])(?:app|tests|docs|config|routes|database|resources|scripts)/[A-Za-z0-9_./-]+~u',
            $text,
            $matches,
        );

        $paths = [];
        foreach ($matches[0] ?? [] as $match) {
            $path = rtrim($match, ".,;:!?)]}'\"`");
            if ($path === '' || str_contains($path, '..')) {
                continue;
            }
            if (file_exists(base_path($path))) {
                $paths[$path] = true;
            }
        }

        return array_slice(array_keys($paths), 0, AtlasRealityGraphIngestionService::MAX_META_PATHS);
    }

    /**
     * Memory entry ids explicitly cited by docs. Linkers still cite-or-omit by
     * requiring a matching ingested memory node before an edge is written.
     *
     * @return list<string>
     */
    public function memoryRefsFromText(string $text): array
    {
        preg_match_all(
            '/\b(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9A-HJKMNP-TV-Z]{26})\b/i',
            $text,
            $matches,
        );

        return array_slice(array_values(array_unique(array_map(
            static fn (string $ref): string => strtolower($ref),
            $matches[0] ?? [],
        ))), 0, AtlasRealityGraphIngestionService::MAX_LINKS_PER_NODE);
    }

    /**
     * Canonical domain ids cited by a source row (tags + metadata domain fields),
     * resolved STRICTLY through the taxonomy — unknown ids are dropped, never invented.
     *
     * @param  array<int,mixed>  $tags
     * @param  array<string,mixed>  $metadata
     * @return list<string>
     */
    public function candidateDomains(array $tags, array $metadata): array
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

        return array_slice(array_keys($resolved), 0, AtlasRealityGraphIngestionService::MAX_META_DOMAINS);
    }

    /**
     * Lowercased label tokens (≥4 chars) for the slug-equality linker rung.
     *
     * @return list<string>
     */
    public function labelTokens(string $label): array
    {
        $tokens = preg_split('/[^a-z0-9_-]+/i', strtolower($label)) ?: [];

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => strlen($t) >= 4)));
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    public function memoryRefFrom(array $metadata): ?string
    {
        foreach (['memory_entry_id', 'memory_id'] as $field) {
            if (is_string($metadata[$field] ?? null) && trim((string) $metadata[$field]) !== '') {
                return trim((string) $metadata[$field]);
            }
        }

        return null;
    }

    /**
     * Explicit target id cited in a ledger payload (cite-or-omit).
     *
     * @param  array<string,mixed>  $payload
     */
    public function ledgerTargetIdFrom(array $payload): ?string
    {
        foreach (['target_id', 'memory_entry_id', 'memory_id'] as $field) {
            if (is_string($payload[$field] ?? null) && trim((string) $payload[$field]) !== '') {
                return trim((string) $payload[$field]);
            }
        }

        return null;
    }

    /**
     * Memory ref cited in a ledger payload or scope (cite-or-omit).
     *
     * @param  array<string,mixed>  $payload
     */
    public function ledgerMemoryRefFrom(array $payload, ?string $scopeType, ?string $scopeId): ?string
    {
        $ref = $this->memoryRefFrom($payload);
        if ($ref !== null) {
            return $ref;
        }

        foreach ($this->normalizeStringList($payload['memory_refs'] ?? null) as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if ($scopeType !== null && in_array($scopeType, ['memory_entry', 'memory'], true)
            && is_string($scopeId) && trim($scopeId) !== '') {
            return trim($scopeId);
        }

        return null;
    }

    /**
     * Repo-relative paths explicitly cited in a ledger payload (cite-or-omit).
     *
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    public function ledgerEvidencePathsFrom(array $payload): array
    {
        $paths = [];

        foreach (['files', 'paths', 'touched_files'] as $key) {
            foreach ($this->normalizeStringList($payload[$key] ?? null) as $path) {
                if ($this->isCitedRepoPath($path)) {
                    $paths[] = $path;
                }
            }
        }

        foreach (['path', 'file_path'] as $key) {
            $path = $payload[$key] ?? null;
            if (is_string($path) && $this->isCitedRepoPath($path)) {
                $paths[] = $path;
            }
        }

        foreach ((array) data_get($payload, 'result.payload.diff_refs', []) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $path = $ref['path'] ?? null;
            if (is_string($path) && $this->isCitedRepoPath($path)) {
                $paths[] = $path;
            }
        }

        foreach ($this->normalizeStringList(data_get($payload, 'local_rag.files')) as $path) {
            if ($this->isCitedRepoPath($path)) {
                $paths[] = $path;
            }
        }

        return array_slice(array_values(array_unique($paths)), 0, AtlasRealityGraphIngestionService::MAX_META_PATHS);
    }

    public function isCitedRepoPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        return (bool) preg_match('~^(?:app|tests|docs|config|routes|database|resources|scripts)/~', $path);
    }

    /**
     * @return list<string>
     */
    public function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return list<string>
     */
    public function exactMetaStrings(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        return $this->normalizeStringList($value);
    }

    /**
     * @return list<mixed>
     */
    public function decodeJsonList(mixed $value): array
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
    public function decodeJsonMap(mixed $value): array
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

    public function sourceKindFor(string $source): string
    {
        return match ($source) {
            'domains' => 'domain',
            'docs' => 'doc',
            default => $source,
        };
    }

    /**
     * Whether the source read-model is readable enough to make prune decisions.
     * Domains are constant-backed (taxonomy) → always available.
     */
    public function sourceAvailable(string $source): bool
    {
        return match ($source) {
            'memory' => $this->tableExists('atlas_memory_entries'),
            'code' => $this->tableExists('atlas_engineering_code_modules'),
            'docs' => is_dir(base_path('docs/engineering-knowledge-base')),
            'domains' => true,
            'evidence' => $this->tableExists('atlas_ledger_events'),
            'strategic' => $this->tableExists('atlas_reality_entities'),
            default => false,
        };
    }

    public function cap(string $key, int $default): int
    {
        $value = (int) config('atlas.aurg.'.$key, $default);

        return $value > 0 ? $value : $default;
    }

    public function tableExists(string $table): bool
    {
        return DatabaseTableAvailability::has($table);
    }
}
