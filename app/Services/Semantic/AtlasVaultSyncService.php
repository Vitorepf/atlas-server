<?php

namespace App\Services\Semantic;

use App\Models\AtlasVaultSyncItem;
use App\Models\SemanticCurationProposal;
use App\Models\SemanticNote;
use App\Services\Ai\AtlasMemorySourcePrivacyPolicy;
use App\Services\AuditLogService;
use App\Support\Metadata;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AtlasVaultSyncService
{
    public const RESOLUTION_ACTIONS = ['adopt', 'archive', 'merge', 'regenerate', 'force', 'dismiss'];

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
            $imports[] = $this->importPath($path, $write);
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
    public function queueSummary(): array
    {
        if (! Schema::hasTable('atlas_vault_sync_items')) {
            return [
                'migrated' => false,
                'total' => 0,
                'open' => 0,
                'blocked' => 0,
                'conflicts' => 0,
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
            'conflicts' => AtlasVaultSyncItem::query()
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
    public function conflicts(int $limit = 100): array
    {
        $items = $this->syncItems()
            ->whereIn('status', ['pending', 'candidate', 'blocked', 'conflict'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'ok' => true,
            'count' => $items->count(),
            'items' => $items->map(fn (AtlasVaultSyncItem $item): array => $item->toArray())->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $id, string $action): array
    {
        $action = strtolower(trim($action));
        if (! in_array($action, self::RESOLUTION_ACTIONS, true)) {
            throw new RuntimeException('Unsupported AtlasVault resolution action: '.$action);
        }

        $item = $this->syncItems()->findOrFail($id);
        $item->forceFill([
            'status' => match ($action) {
                'archive' => 'archived',
                'dismiss' => 'dismissed',
                default => 'reviewed',
            },
            'reviewed_at' => now(),
            'resolved_at' => now(),
            'metadata' => Metadata::forStorage([
                ...($item->metadata ?? []),
                'resolution_action' => $action,
                'resolved_by' => 'operator',
                'resolution_policy' => 'phase2_review_queue_no_silent_overwrite',
            ]),
        ])->save();

        $this->audit->record('atlas_vault_sync_resolved', [
            'subject_type' => 'atlas_vault_sync_item',
            'subject_id' => $item->id,
            'actor_type' => 'operator',
            'actor_id' => 'vitor',
            'summary' => "AtlasVault sync item resolved with {$action}.",
            'evidence' => ['id' => $item->id, 'path' => $item->path, 'action' => $action],
            'privacy' => ['sensitivity' => data_get($item->metadata, 'privacy.privacy_class', 'normal')],
            'refs' => ['sync_item_id' => $item->id],
        ]);

        return ['ok' => true, 'item' => $item->refresh()->toArray()];
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
     * @return \Illuminate\Database\Eloquent\Builder<AtlasVaultSyncItem>
     */
    private function syncItems(): \Illuminate\Database\Eloquent\Builder
    {
        if (! Schema::hasTable('atlas_vault_sync_items')) {
            throw new RuntimeException('atlas_vault_sync_items table is not migrated.');
        }

        return AtlasVaultSyncItem::query();
    }
}
