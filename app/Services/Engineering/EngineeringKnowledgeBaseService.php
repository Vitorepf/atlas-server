<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Semantic\FrontmatterParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class EngineeringKnowledgeBaseService
{
    public function __construct(
        private readonly FrontmatterParser $frontmatter,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function sync(array $options = []): array
    {
        $this->ensureTable();

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $prune = (bool) ($options['prune'] ?? false);
        $docs = $this->canonicalDocs();
        $seenSlugs = [];
        $items = [];
        $summary = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'archived' => 0,
            'failed' => 0,
        ];

        foreach ($docs as $path) {
            try {
                $document = $this->parseDocument($path);
                $seenSlugs[] = $document['slug'];
                $existing = AtlasEngineeringKnowledgeItem::query()
                    ->where('slug', $document['slug'])
                    ->first();
                $action = $existing === null
                    ? 'created'
                    : ($this->documentChanged($existing, $document) ? 'updated' : 'unchanged');

                if (! $dryRun) {
                    AtlasEngineeringKnowledgeItem::query()->updateOrCreate(
                        ['slug' => $document['slug']],
                        $document,
                    );
                }

                $summary[$action]++;
                $items[] = array_merge($document, [
                    'action' => $action,
                    'dry_run' => $dryRun,
                ]);
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $items[] = [
                    'path' => $this->relativePath($path),
                    'action' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        if ($prune && $seenSlugs !== []) {
            $query = AtlasEngineeringKnowledgeItem::query()
                ->where('source_type', 'canonical_doc')
                ->whereNotIn('slug', $seenSlugs)
                ->where('status', '!=', 'archived');
            $archiveCount = (int) $query->count();
            if (! $dryRun && $archiveCount > 0) {
                $query->update([
                    'status' => 'archived',
                    'archived_at' => now(),
                ]);
            }
            $summary['archived'] = $archiveCount;
        }

        return [
            'ok' => $summary['failed'] === 0,
            'dry_run' => $dryRun,
            'docs_root' => $this->relativePath($this->docsRoot()),
            'summary' => $summary,
            'items' => array_values($items),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function catalog(array $filters = [], int $limit = 50): array
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return [
                'summary' => $this->summary(),
                'items' => [],
            ];
        }

        $items = $this->applyFilters(AtlasEngineeringKnowledgeItem::query(), $filters)
            ->limit($this->limit($limit))
            ->get()
            ->map(fn (AtlasEngineeringKnowledgeItem $item): array => $this->itemPayload($item))
            ->values()
            ->all();

        return [
            'summary' => $this->summary(),
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $tableExists = Schema::hasTable('atlas_engineering_knowledge_items');
        $docsRoot = $this->docsRoot();
        if (! $tableExists) {
            return [
                'status' => 'not_migrated',
                'table_exists' => false,
                'docs_root' => $this->relativePath($docsRoot),
                'docs_root_exists' => File::isDirectory($docsRoot),
                'total' => 0,
                'active' => 0,
                'categories' => [],
                'last_indexed_at' => null,
            ];
        }

        $items = AtlasEngineeringKnowledgeItem::query()->get();
        $lastIndexedAt = $items
            ->pluck('indexed_at')
            ->filter()
            ->sort()
            ->last();

        return [
            'status' => $items->where('status', 'active')->isNotEmpty() ? 'ready' : 'empty',
            'table_exists' => true,
            'docs_root' => $this->relativePath($docsRoot),
            'docs_root_exists' => File::isDirectory($docsRoot),
            'canonical_doc_count' => count($this->canonicalDocs()),
            'total' => $items->count(),
            'active' => $items->where('status', 'active')->count(),
            'archived' => $items->where('status', 'archived')->count(),
            'categories' => $items
                ->where('status', 'active')
                ->groupBy('category')
                ->map->count()
                ->sortKeys()
                ->all(),
            'last_indexed_at' => $lastIndexedAt ? $lastIndexedAt->toJSON() : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $idOrSlug): ?array
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return null;
        }

        $item = AtlasEngineeringKnowledgeItem::query()
            ->where(function (Builder $query) use ($idOrSlug): void {
                $query->where('slug', $idOrSlug);

                if (Str::isUuid($idOrSlug)) {
                    $query->orWhere('id', $idOrSlug);
                }
            })
            ->first();

        return $item ? $this->itemPayload($item, true) : null;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function contextRefs(array $context = [], int $limit = 8): array
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return [];
        }

        $preferredCategories = $this->preferredCategories($context);
        $query = AtlasEngineeringKnowledgeItem::query()->active();
        if ($preferredCategories !== []) {
            $caseSql = collect($preferredCategories)
                ->values()
                ->map(fn (string $category, int $index): string => "when category = ? then {$index}")
                ->implode(' ');
            $query->orderByRaw(
                'case '.$caseSql.' else '.count($preferredCategories).' end',
                $preferredCategories,
            );
        }

        return $query
            ->orderByDesc('priority')
            ->latest('indexed_at')
            ->limit($this->limit($limit))
            ->get()
            ->map(fn (AtlasEngineeringKnowledgeItem $item): array => [
                'type' => 'atlas_engineering_knowledge_item',
                'id' => $item->id,
                'slug' => $item->slug,
                'title' => $item->title,
                'category' => $item->category,
                'priority' => $item->priority,
                'canonical_path' => $item->canonical_path,
                'content_hash' => $item->content_hash,
                'summary' => $item->summary,
                'reason' => in_array($item->category, $preferredCategories, true)
                    ? 'matched_engineering_context'
                    : 'canonical_engineering_knowledge',
            ])
            ->values()
            ->all();
    }

    private function ensureTable(): void
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            throw new RuntimeException('Tabela atlas_engineering_knowledge_items ainda nao existe. Rode migrations.');
        }
    }

    /**
     * @return array<int,string>
     */
    private function canonicalDocs(): array
    {
        $root = $this->docsRoot();
        if (! File::isDirectory($root)) {
            return [];
        }

        return collect(File::allFiles($root))
            ->filter(fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'md')
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    private function docsRoot(): string
    {
        return base_path('docs/engineering-knowledge-base');
    }

    /**
     * @return array<string,mixed>
     */
    private function parseDocument(string $path): array
    {
        $markdown = File::get($path);
        $parsed = $this->frontmatter->parse($markdown);
        $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
        $body = trim((string) ($parsed['body'] ?? $markdown));
        $relativePath = $this->relativePath($path);
        $slug = $this->slug((string) ($frontmatter['id'] ?? $frontmatter['slug'] ?? $relativePath));
        $status = $this->status((string) ($frontmatter['status'] ?? 'active'));
        $contentHash = hash('sha256', $markdown);

        return [
            'slug' => $slug,
            'title' => Str::limit($this->title($frontmatter, $body, $slug), 220, ''),
            'category' => $this->category($frontmatter, $relativePath),
            'status' => $status,
            'priority' => $this->priority($frontmatter),
            'source_type' => 'canonical_doc',
            'canonical_path' => $relativePath,
            'source_hash' => hash('sha256', $relativePath),
            'content_hash' => $contentHash,
            'summary' => $this->summaryText($frontmatter, $body),
            'body_excerpt' => Str::limit($this->plainText($body), 2600, ''),
            'tags_json' => $this->stringList($frontmatter['tags'] ?? []),
            'related_paths_json' => $this->stringList($frontmatter['related_paths'] ?? []),
            'capabilities_json' => $this->stringList($frontmatter['capabilities'] ?? []),
            'decisions_json' => $this->stringList($frontmatter['decisions'] ?? []),
            'maintenance_json' => $this->stringList($frontmatter['maintenance'] ?? []),
            'metadata' => [
                'frontmatter_errors' => array_values((array) ($parsed['errors'] ?? [])),
                'type' => $frontmatter['type'] ?? 'engineering_knowledge',
                'body_bytes' => strlen($body),
                'doc_hash' => $contentHash,
            ],
            'indexed_at' => now(),
            'last_verified_at' => now(),
            'archived_at' => $status === 'archived' ? now() : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function title(array $frontmatter, string $body, string $slug): string
    {
        $title = trim((string) ($frontmatter['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        if (preg_match('/^#\s+(.+)$/m', $body, $match)) {
            return trim($match[1]);
        }

        return Str::of($slug)->replace('-', ' ')->title()->toString();
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function category(array $frontmatter, string $relativePath): string
    {
        $category = trim((string) ($frontmatter['category'] ?? ''));
        if ($category !== '') {
            return Str::slug($category, '_');
        }
        if (str_contains($relativePath, '/adr/')) {
            return 'architecture_decision';
        }

        return 'engineering_knowledge';
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function priority(array $frontmatter): int
    {
        return max(0, min(100, (int) ($frontmatter['priority'] ?? 50)));
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    private function summaryText(array $frontmatter, string $body): string
    {
        $summary = trim((string) ($frontmatter['summary'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        return Str::limit($this->plainText($body), 420, '');
    }

    private function plainText(string $markdown): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/[`*_#>\[\]\(\)]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return collect((array) $value)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->all();
    }

    private function status(string $status): string
    {
        return in_array($status, AtlasEngineeringKnowledgeItem::STATUSES, true) ? $status : 'active';
    }

    private function slug(string $value): string
    {
        $value = preg_replace('/\.md$/', '', $value) ?? $value;
        $value = str_replace(['/', '\\', '_'], '-', $value);

        return Str::limit(Str::slug($value), 160, '');
    }

    private function documentChanged(AtlasEngineeringKnowledgeItem $existing, array $document): bool
    {
        foreach (['title', 'category', 'status', 'priority', 'canonical_path', 'content_hash', 'summary'] as $field) {
            if ($existing->{$field} !== $document[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Builder<AtlasEngineeringKnowledgeItem>  $query
     * @param  array<string,mixed>  $filters
     * @return Builder<AtlasEngineeringKnowledgeItem>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->active();
        }
        if (is_string($filters['status'] ?? null) && trim((string) $filters['status']) !== '') {
            $query->where('status', trim((string) $filters['status']));
        }
        if (is_string($filters['category'] ?? null) && trim((string) $filters['category']) !== '') {
            $query->where('category', Str::slug(trim((string) $filters['category']), '_'));
        }
        if (is_string($filters['q'] ?? null) && trim((string) $filters['q']) !== '') {
            $q = trim((string) $filters['q']);
            $query->where(function (Builder $query) use ($q): void {
                $query->where('slug', 'like', "%{$q}%")
                    ->orWhere('title', 'like', "%{$q}%")
                    ->orWhere('summary', 'like', "%{$q}%")
                    ->orWhere('canonical_path', 'like', "%{$q}%");
            });
        }

        return $query->orderByDesc('priority')->latest('indexed_at')->orderBy('slug');
    }

    /**
     * @return array<int,string>
     */
    private function preferredCategories(array $context): array
    {
        $categories = collect((array) ($context['categories'] ?? []))
            ->push($context['category'] ?? null)
            ->filter(fn (mixed $category): bool => is_scalar($category) && trim((string) $category) !== '')
            ->map(fn (mixed $category): string => Str::slug(trim((string) $category), '_'))
            ->values()
            ->all();
        $categories = array_merge($categories, ['architecture', 'maintenance', 'capability_matrix']);
        $contract = is_array($context['contract'] ?? null) ? $context['contract'] : [];
        $tags = collect((array) ($contract['tags'] ?? []))
            ->merge((array) ($context['tags'] ?? []))
            ->map(fn (mixed $tag): string => strtolower((string) $tag))
            ->all();

        if (in_array('docker', $tags, true)) {
            $categories[] = 'docker_harness';
        }
        if (in_array('visual', $tags, true) || in_array('frontend', $tags, true)) {
            $categories[] = 'visual_harness';
        }
        if (in_array('quality', $tags, true) || in_array('security', $tags, true)) {
            $categories[] = 'quality_scan';
        }

        return array_values(array_unique($categories));
    }

    /**
     * @return array<string,mixed>
     */
    private function itemPayload(AtlasEngineeringKnowledgeItem $item, bool $includeBody = false): array
    {
        $payload = [
            'id' => $item->id,
            'slug' => $item->slug,
            'title' => $item->title,
            'category' => $item->category,
            'status' => $item->status,
            'priority' => $item->priority,
            'source_type' => $item->source_type,
            'canonical_path' => $item->canonical_path,
            'source_hash' => $item->source_hash,
            'content_hash' => $item->content_hash,
            'summary' => $item->summary,
            'tags' => $item->tags_json ?? [],
            'related_paths' => $item->related_paths_json ?? [],
            'capabilities' => $item->capabilities_json ?? [],
            'decisions' => $item->decisions_json ?? [],
            'maintenance' => $item->maintenance_json ?? [],
            'metadata' => $item->metadata ?? [],
            'indexed_at' => $item->indexed_at?->toJSON(),
            'last_verified_at' => $item->last_verified_at?->toJSON(),
            'archived_at' => $item->archived_at?->toJSON(),
            'created_at' => $item->created_at?->toJSON(),
            'updated_at' => $item->updated_at?->toJSON(),
        ];

        if ($includeBody) {
            $payload['body_excerpt'] = $item->body_excerpt;
        }

        return $payload;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function limit(int $limit): int
    {
        return max(1, min(200, $limit));
    }
}
