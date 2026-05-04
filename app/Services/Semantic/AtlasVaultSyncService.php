<?php

namespace App\Services\Semantic;

use App\Models\AtlasVaultSyncItem;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Services\Ai\AtlasMemorySourcePrivacyPolicy;
use App\Services\AuditLogService;
use App\Support\Metadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AtlasVaultSyncService
{
    public const RESOLUTION_ACTIONS = ['adopt', 'archive', 'merge', 'regenerate', 'force', 'dismiss'];

    public const REVIEW_STATUSES = ['pending', 'candidate', 'blocked', 'conflict'];

    public const FILTERABLE_STATUSES = ['pending', 'candidate', 'blocked', 'conflict', 'imported', 'exported', 'reviewed', 'regenerated', 'archived', 'dismissed'];

    public const FILTERABLE_DIRECTIONS = ['vault_to_atlas', 'atlas_to_vault'];

    public const FILTERABLE_OPERATIONS = ['import', 'export_semantic_note'];

    public function __construct(
        private readonly VaultFileStore $vault,
        private readonly FrontmatterParser $parser,
        private readonly AtlasVaultFrontmatterService $frontmatter,
        private readonly SemanticNoteIndexer $indexer,
        private readonly AtlasVaultManagedNoteService $managedNotes,
        private readonly AtlasMemorySourcePrivacyPolicy $privacyPolicy,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function importPath(string $path, bool $write = false): array
    {
        $path = $this->safeMarkdownPath($path, ensureRoot: false);
        $markdown = $this->vault->read($path);
        $parsed = $this->parser->parse($markdown);
        $managed = $this->frontmatter->isManaged($parsed['frontmatter']);
        $contentHash = hash('sha256', $markdown);
        $privacy = $this->privacyFor($path, $parsed['frontmatter'], $parsed['body']);
        $blocked = $managed || ! (bool) $privacy['provider_safe'] || $parsed['errors'] !== [];
        $status = $blocked ? 'blocked' : ($write ? 'imported' : 'candidate');
        $conflictType = $managed ? 'atlas_managed_note_not_importable' : ($parsed['errors'][0] ?? null);

        $payload = [
            'ok' => ! $blocked,
            'dry_run' => ! $write,
            'path' => $path,
            'content_hash' => $contentHash,
            'managed' => $managed,
            'status' => $status,
            'conflict_type' => $conflictType,
            'frontmatter' => $parsed['frontmatter'],
            'privacy' => $privacy,
            'semantic_note_id' => null,
            'proposal_id' => null,
        ];

        if (! $write) {
            return $payload;
        }

        $note = $blocked ? null : $this->indexer->indexFile($path)['note'];
        $proposal = $note instanceof SemanticNote ? $this->createProposal($note, $path, $parsed, $privacy, $contentHash) : null;
        $item = $this->recordItem([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => $status,
            'path' => $path,
            'source_type' => 'atlas_vault_note',
            'source_id' => $path,
            'semantic_note_id' => $note?->id,
            'content_hash' => $contentHash,
            'conflict_type' => $conflictType,
            'summary' => $this->summaryFor($path, $parsed['frontmatter'], $parsed['body']),
            'frontmatter_json' => $parsed['frontmatter'],
            'links_json' => [],
            'metadata' => [
                'privacy' => $privacy,
                'parse_errors' => $parsed['errors'],
                'proposal_id' => $proposal?->id,
                'managed' => $managed,
            ],
        ]);

        $this->audit->record('atlas_vault_import', [
            'subject_type' => 'atlas_vault_sync_item',
            'subject_id' => $item?->id,
            'summary' => "AtlasVault import {$status}: {$path}.",
            'evidence' => [
                'path' => $path,
                'content_hash' => $contentHash,
                'semantic_note_id' => $note?->id,
                'proposal_id' => $proposal?->id,
                'conflict_type' => $conflictType,
            ],
            'privacy' => ['sensitivity' => $privacy['privacy_class'] ?? 'normal'],
            'refs' => ['path' => $path],
        ]);

        return [
            ...$payload,
            'semantic_note_id' => $note?->id,
            'proposal_id' => $proposal?->id,
            'sync_item_id' => $item?->id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function exportSemanticNote(string $semanticNoteId, bool $write = false): array
    {
        $note = SemanticNote::query()->findOrFail($semanticNoteId);
        $privacy = $this->privacyPolicy->project('semantic_note', [
            'title' => $note->title,
            'summary' => $note->summary,
            'body_excerpt' => $note->body_excerpt,
            'frontmatter' => $note->frontmatter ?? [],
        ]);

        $vaultNote = $write ? $this->managedNotes->write([
            'type' => 'semantic_note',
            'id' => $note->id,
            'title' => $note->title,
            'summary' => $note->summary ?? '',
            'content' => $note->body_excerpt ?? '',
            'privacy_class' => $privacy['privacy_class'],
            'provider_safe' => $privacy['provider_safe'],
            'redaction_status' => $privacy['redaction_status'],
        ]) : $this->managedNotes->preview([
            'type' => 'semantic_note',
            'id' => $note->id,
            'title' => $note->title,
            'summary' => $note->summary ?? '',
            'content' => $note->body_excerpt ?? '',
            'privacy_class' => $privacy['privacy_class'],
            'provider_safe' => $privacy['provider_safe'],
            'redaction_status' => $privacy['redaction_status'],
        ]);

        $status = $vaultNote->conflicts === [] ? ($write ? 'exported' : 'candidate') : 'conflict';
        $item = $write ? $this->recordItem([
            'direction' => 'atlas_to_vault',
            'operation' => 'export_semantic_note',
            'status' => $status,
            'path' => $vaultNote->path,
            'source_type' => 'semantic_note',
            'source_id' => $note->id,
            'semantic_note_id' => $note->id,
            'content_hash' => $vaultNote->metadata['proposed_content_hash'] ?? null,
            'conflict_type' => $vaultNote->conflicts[0] ?? null,
            'summary' => $note->summary,
            'frontmatter_json' => $vaultNote->frontmatter,
            'links_json' => $vaultNote->links,
            'metadata' => [
                'privacy' => $privacy,
                'note_metadata' => $vaultNote->metadata,
                'conflicts' => $vaultNote->conflicts,
            ],
        ]) : null;

        if ($write) {
            $this->audit->record('atlas_vault_export', [
                'subject_type' => 'atlas_vault_sync_item',
                'subject_id' => $item?->id,
                'summary' => "AtlasVault export {$status}: {$vaultNote->path}.",
                'evidence' => [
                    'path' => $vaultNote->path,
                    'semantic_note_id' => $note->id,
                    'conflicts' => $vaultNote->conflicts,
                ],
                'privacy' => ['sensitivity' => $privacy['privacy_class'] ?? 'normal'],
                'refs' => ['semantic_note_id' => $note->id],
            ]);
        }

        return [
            'ok' => $vaultNote->conflicts === [],
            'dry_run' => ! $write,
            'status' => $status,
            'sync_item_id' => $item?->id,
            'note' => $vaultNote->toArray(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sync(bool $write = false, int $limit = 200): array
    {
        $imports = [];
        foreach ($this->vault->listMarkdownFiles()->take($limit) as $path) {
            try {
                $imports[] = $this->importPath($path, $write);
            } catch (RuntimeException $exception) {
                $imports[] = $this->blockedSyncImport($path, $exception, $write);
            }
        }

        return [
            'ok' => collect($imports)->every(fn (array $item): bool => (bool) ($item['ok'] ?? false) || ($item['status'] ?? null) === 'blocked'),
            'dry_run' => ! $write,
            'inspected' => count($imports),
            'imported' => collect($imports)->where('status', 'imported')->count(),
            'candidates' => collect($imports)->where('status', 'candidate')->count(),
            'blocked' => collect($imports)->where('status', 'blocked')->count(),
            'items' => $imports,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedSyncImport(string $path, RuntimeException $exception, bool $write): array
    {
        $path = ltrim(preg_replace('#/+#', '/', str_replace('\\', '/', trim($path))) ?? $path, '/');
        $payload = [
            'ok' => false,
            'dry_run' => ! $write,
            'path' => $path,
            'content_hash' => null,
            'managed' => null,
            'status' => 'blocked',
            'conflict_type' => 'sync_file_error',
            'frontmatter' => [],
            'privacy' => null,
            'semantic_note_id' => null,
            'proposal_id' => null,
            'error' => $exception->getMessage(),
        ];

        if (! $write) {
            return $payload;
        }

        $item = $this->recordItem([
            'direction' => 'vault_to_atlas',
            'operation' => 'import',
            'status' => 'blocked',
            'path' => $path,
            'source_type' => 'atlas_vault_note',
            'source_id' => $path,
            'semantic_note_id' => null,
            'content_hash' => null,
            'conflict_type' => 'sync_file_error',
            'summary' => "AtlasVault sync blocked: {$path}.",
            'frontmatter_json' => [],
            'links_json' => [],
            'metadata' => [
                'error' => $exception->getMessage(),
                'blocked_by' => 'atlas-vault-sync-v2',
            ],
        ]);

        $this->audit->record('atlas_vault_import', [
            'subject_type' => 'atlas_vault_sync_item',
            'subject_id' => $item?->id,
            'summary' => "AtlasVault import blocked during sync: {$path}.",
            'evidence' => [
                'path' => $path,
                'conflict_type' => 'sync_file_error',
                'error' => $exception->getMessage(),
            ],
            'privacy' => ['sensitivity' => 'normal'],
            'refs' => ['path' => $path],
        ]);

        return [
            ...$payload,
            'sync_item_id' => $item?->id,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function queueSummary(): array
    {
        if (! Schema::hasTable('atlas_vault_sync_items')) {
            return [
                'migrated' => false,
                'total' => 0,
                'open' => 0,
                'blocked' => 0,
                'conflicts' => 0,
                'historical_conflicts' => 0,
                'reviewed' => 0,
                'resolved' => 0,
                'by_status' => [],
                'by_direction' => [],
                'last_activity_at' => null,
            ];
        }

        $statusCounts = AtlasVaultSyncItem::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
        $directionCounts = AtlasVaultSyncItem::query()
            ->selectRaw('direction, count(*) as aggregate')
            ->groupBy('direction')
            ->pluck('aggregate', 'direction')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'migrated' => true,
            'total' => array_sum($statusCounts),
            'open' => AtlasVaultSyncItem::query()->whereIn('status', ['pending', 'candidate', 'blocked', 'conflict'])->count(),
            'blocked' => (int) ($statusCounts['blocked'] ?? 0),
            'conflicts' => $this->openConflictItems()->count(),
            'historical_conflicts' => AtlasVaultSyncItem::query()
                ->where(static function ($query): void {
                    $query->where('status', 'conflict')
                        ->orWhereNotNull('conflict_type');
                })
                ->count(),
            'reviewed' => AtlasVaultSyncItem::query()->whereNotNull('reviewed_at')->count(),
            'resolved' => AtlasVaultSyncItem::query()->whereNotNull('resolved_at')->count(),
            'by_status' => $statusCounts,
            'by_direction' => $directionCounts,
            'last_activity_at' => AtlasVaultSyncItem::query()->max('updated_at'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function conflicts(int $limit = 100, array $filters = []): array
    {
        $filters = $this->normalizeConflictFilters($filters);
        $query = $this->syncItems()
            ->whereIn('status', $filters['statuses'])
            ->orderByDesc('created_at');

        if ($filters['direction'] !== null) {
            $query->where('direction', $filters['direction']);
        }

        if ($filters['operation'] !== null) {
            $query->where('operation', $filters['operation']);
        }

        $items = $query
            ->limit($limit)
            ->get();

        return [
            'ok' => true,
            'count' => $items->count(),
            'filters' => $filters,
            'items' => $items->map(fn (AtlasVaultSyncItem $item): array => $item->toArray())->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function item(string $id): array
    {
        $this->assertSyncItemId($id);
        $item = $this->syncItems()->findOrFail($id);

        return [
            'ok' => true,
            'item' => $item->toArray(),
            'allowed_resolution_actions' => self::RESOLUTION_ACTIONS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $id, string $action, ?string $reason = null): array
    {
        $this->assertSyncItemId($id);
        $action = strtolower(trim($action));
        if (! in_array($action, self::RESOLUTION_ACTIONS, true)) {
            throw new RuntimeException('Unsupported AtlasVault resolution action: '.$action);
        }
        $reason = $this->normalizeResolutionReason($reason);

        $item = $this->syncItems()->findOrFail($id);
        $regeneration = $action === 'regenerate' ? $this->regenerateSyncItem($item) : null;
        $resolved = $regeneration === null || (bool) ($regeneration['ok'] ?? false);
        $item->forceFill([
            'status' => $regeneration !== null
                ? ((bool) ($regeneration['ok'] ?? false) ? 'regenerated' : 'conflict')
                : match ($action) {
                    'archive' => 'archived',
                    'dismiss' => 'dismissed',
                    default => 'reviewed',
                },
            'reviewed_at' => now(),
            'resolved_at' => $resolved ? now() : null,
            'conflict_type' => $regeneration !== null
                ? ((bool) ($regeneration['ok'] ?? false) ? null : (string) ($regeneration['conflict_type'] ?? 'regeneration_blocked'))
                : $item->conflict_type,
            'metadata' => Metadata::forStorage([
                ...($item->metadata ?? []),
                'resolution_action' => $action,
                'resolution_reason' => $reason,
                'resolved_by' => 'operator',
                'resolution_policy' => 'phase2_review_queue_no_silent_overwrite',
                'regeneration' => $regeneration,
            ]),
        ])->save();

        $this->audit->record('atlas_vault_sync_resolved', [
            'subject_type' => 'atlas_vault_sync_item',
            'subject_id' => $item->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "AtlasVault sync item resolved with {$action}.",
            'evidence' => ['id' => $item->id, 'path' => $item->path, 'action' => $action, 'reason' => $reason, 'regeneration' => $regeneration],
            'privacy' => ['sensitivity' => data_get($item->metadata, 'privacy.privacy_class', 'normal')],
            'refs' => ['sync_item_id' => $item->id],
        ]);

        return ['ok' => $resolved, 'item' => $item->refresh()->toArray(), 'regeneration' => $regeneration];
    }

    /**
     * @return Builder<AtlasVaultSyncItem>
     */
    private function openConflictItems(): Builder
    {
        return AtlasVaultSyncItem::query()
            ->whereIn('status', self::REVIEW_STATUSES)
            ->where(static function ($query): void {
                $query->where('status', 'conflict')
                    ->orWhereNotNull('conflict_type');
            });
    }

    private function normalizeResolutionReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);

        return $reason !== '' ? Str::limit($reason, 1000, '') : null;
    }

    private function assertSyncItemId(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(AtlasVaultSyncItem::class, [$id]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function regenerateSyncItem(AtlasVaultSyncItem $item): array
    {
        if ($item->direction !== 'atlas_to_vault' || $item->operation !== 'export_semantic_note') {
            return [
                'ok' => false,
                'status' => 'blocked',
                'conflict_type' => 'regeneration_supported_only_for_atlas_to_vault_semantic_exports',
            ];
        }

        $semanticNoteId = $item->semantic_note_id ?: $item->source_id;
        if (! is_string($semanticNoteId) || trim($semanticNoteId) === '') {
            return [
                'ok' => false,
                'status' => 'blocked',
                'conflict_type' => 'missing_semantic_note_id',
            ];
        }

        try {
            $result = $this->exportSemanticNote($semanticNoteId, write: true);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'blocked',
                'conflict_type' => 'regeneration_exception',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'status' => (string) ($result['status'] ?? 'unknown'),
            'sync_item_id' => $result['sync_item_id'] ?? null,
            'path' => data_get($result, 'note.path'),
            'conflict_type' => data_get($result, 'note.conflicts.0'),
            'content_hash' => data_get($result, 'note.metadata.proposed_content_hash'),
        ];
    }

    /**
     * @return array{statuses: array<int,string>, direction: ?string, operation: ?string}
     */
    private function normalizeConflictFilters(array $filters): array
    {
        $statuses = $filters['statuses'] ?? $filters['status'] ?? self::REVIEW_STATUSES;
        if (is_string($statuses)) {
            $statuses = explode(',', $statuses);
        }
        if (! is_array($statuses)) {
            throw new RuntimeException('AtlasVault conflicts status filter must be a string or array.');
        }

        $statuses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $status): string => strtolower(trim((string) $status)),
            $statuses,
        ), static fn (string $status): bool => $status !== '')));
        if ($statuses === []) {
            $statuses = self::REVIEW_STATUSES;
        }

        foreach ($statuses as $status) {
            if (! in_array($status, self::FILTERABLE_STATUSES, true)) {
                throw new RuntimeException('Unsupported AtlasVault conflicts status filter: '.$status);
            }
        }

        $direction = $this->nullableFilter($filters['direction'] ?? null);
        if ($direction !== null && ! in_array($direction, self::FILTERABLE_DIRECTIONS, true)) {
            throw new RuntimeException('Unsupported AtlasVault conflicts direction filter: '.$direction);
        }

        $operation = $this->nullableFilter($filters['operation'] ?? null);
        if ($operation !== null && ! in_array($operation, self::FILTERABLE_OPERATIONS, true)) {
            throw new RuntimeException('Unsupported AtlasVault conflicts operation filter: '.$operation);
        }

        return ['statuses' => $statuses, 'direction' => $direction, 'operation' => $operation];
    }

    private function nullableFilter(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new RuntimeException('AtlasVault conflicts filter must be a string.');
        }

        $value = strtolower(trim($value));

        return $value !== '' ? $value : null;
    }

    private function createProposal(SemanticNote $note, string $path, array $parsed, array $privacy, string $contentHash): ?SemanticCurationProposal
    {
        $existing = SemanticCurationProposal::query()
            ->where('source_type', 'atlas_vault_note')
            ->where('source_refs->path', $path)
            ->where('source_refs->content_hash', $contentHash)
            ->first();
        if ($existing) {
            return $existing;
        }

        return SemanticCurationProposal::query()->create([
            'source_type' => 'atlas_vault_note',
            'source_refs' => Metadata::forStorage(['path' => $path, 'content_hash' => $contentHash, 'semantic_note_id' => $note->id]),
            'proposed_note_type' => $note->type,
            'proposed_title' => $note->title,
            'proposed_summary' => $note->summary ?? $this->summaryFor($path, $parsed['frontmatter'], $parsed['body']),
            'proposed_path' => $path,
            'proposed_frontmatter' => Metadata::forStorage($parsed['frontmatter']),
            'proposed_body' => $parsed['body'],
            'score' => $privacy['review_required'] ? 0.55 : 0.72,
            'reason' => 'Nota do AtlasVault importada para revisao humana; nao promove memoria automaticamente.',
            'status' => 'pending',
            'metadata' => Metadata::forStorage([
                'created_by' => 'atlas-vault-sync-v2',
                'privacy' => $privacy,
                'ratification_required' => true,
                'promotion_allowed' => false,
            ]),
        ]);
    }

    private function safeMarkdownPath(string $path, bool $ensureRoot): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || ! str_ends_with(strtolower($path), '.md')) {
            throw new RuntimeException('AtlasVault sync path must be a safe vault-relative markdown path.');
        }
        $this->vault->absolutePath($path, ensureRoot: $ensureRoot);

        return ltrim(preg_replace('#/+#', '/', $path) ?? $path, '/');
    }

    private function privacyFor(string $path, array $frontmatter, string $body): array
    {
        return $this->privacyPolicy->project('semantic_note', [
            'title' => $frontmatter['title'] ?? $path,
            'summary' => $frontmatter['summary'] ?? null,
            'body_excerpt' => Str::limit(trim($body), 900, ''),
            'path' => $path,
            'frontmatter' => $frontmatter,
        ]);
    }

    private function summaryFor(string $path, array $frontmatter, string $body): string
    {
        $summary = trim((string) ($frontmatter['summary'] ?? ''));
        if ($summary !== '') {
            return Str::limit($summary, 240, '');
        }

        return Str::limit(trim(preg_replace('/\s+/', ' ', $body) ?? $body) ?: $path, 240, '');
    }

    private function recordItem(array $attributes): ?AtlasVaultSyncItem
    {
        if (! Schema::hasTable('atlas_vault_sync_items')) {
            return null;
        }

        return AtlasVaultSyncItem::query()->updateOrCreate(
            [
                'direction' => $attributes['direction'],
                'operation' => $attributes['operation'],
                'path' => $attributes['path'] ?? null,
                'content_hash' => $attributes['content_hash'] ?? null,
            ],
            $attributes,
        );
    }

    /**
     * @return Builder<AtlasVaultSyncItem>
     */
    private function syncItems(): Builder
    {
        if (! Schema::hasTable('atlas_vault_sync_items')) {
            throw new RuntimeException('atlas_vault_sync_items table is not migrated.');
        }

        return AtlasVaultSyncItem::query();
    }
}
