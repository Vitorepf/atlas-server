<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringStringListNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * GOD-DEBULK FASE C - the doc-link sync + documentation status family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class DocumentationSection
{
    /** @var array<string,string|null> */
    private array $docLinkTargetHashCache = [];

    public function __construct(
        private readonly PersistenceSupport $support,
        private readonly SymbolExtractor $symbolExtractor,
    ) {}


    public function syncDocLinks(string $workspace, bool $prune): int
    {
        if (! DatabaseTableAvailability::has('atlas_engineering_knowledge_items')) {
            return 0;
        }

        // AP-815 W-5: the knowledge-item KB belongs to the PRIMARY workspace (atlas-server).
        // External workspaces have no entries in it, so syncing here would replicate
        // atlas-server's docs into EVERY workspace. Only the primary links docs from the KB.
        if ($this->support->workspaceKeyed('atlas_engineering_doc_links')
            && $this->support->workspaceId !== app(CodeGraphWorkspaceIdentity::class)->default()) {
            return 0;
        }

        $knowledgeItems = AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->get([
                'id',
                'canonical_path',
                'content_hash',
                'related_paths_json',
                'capabilities_json',
                'tags_json',
            ]);
        $moduleQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_modules')) {
            $moduleQuery->where('workspace_id', $this->support->workspaceId);
        }
        $modules = $moduleQuery->get(['id', 'slug', 'root_path']);
        $count = 0;
        $docLinkRows = [];
        $this->docLinkTargetHashCache = [];
        $pruneStartedAt = now();

        // AP-815 C6: build a path -> symbols index ONCE (chunked) instead of firing a
        // per-knowledge-item symbol query — the N-query cost the old loop paid per item.
        $allPaths = $knowledgeItems
            ->flatMap(fn ($item) => collect($item->related_paths_json ?? [])->push($item->canonical_path))
            ->filter()
            ->map(fn (mixed $path): string => trim((string) $path))
            ->filter(fn (string $path): bool => $path !== '')
            ->unique()
            ->values();
        $symbolsByPath = [];
        foreach ($allPaths->chunk(1000) as $pathChunk) {
            $symbolQuery = DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereIn('file_path', $pathChunk->all());
            if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
                $symbolQuery->where('workspace_id', $this->support->workspaceId);
            }
            $symbolQuery
                ->select(['id', 'symbol_name', 'symbol_type', 'file_path'])
                ->orderBy('id')
                ->each(function (object $symbol) use (&$symbolsByPath): void {
                    $symbolsByPath[(string) $symbol->file_path][] = $symbol;
                });
        }

        foreach ($knowledgeItems as $item) {
            $paths = collect($item->related_paths_json ?? [])
                ->push($item->canonical_path)
                ->filter()
                ->map(fn (mixed $path): string => trim((string) $path))
                ->unique()
                ->values();
            $capabilities = collect($item->capabilities_json ?? [])
                ->merge($item->tags_json ?? [])
                ->map(fn (mixed $value): string => Str::slug((string) $value, '_'))
                ->filter()
                ->values()
                ->all();

            foreach ($modules as $module) {
                $matchedPath = $paths->first(fn (string $path): bool => $this->pathMatchesModule($path, $module));
                $matchedCapability = $matchedPath ? null : collect($capabilities)->first(
                    fn (string $capability): bool => $capability !== '' && str_contains($module->slug, $capability),
                );
                if (! $matchedPath && ! $matchedCapability) {
                    continue;
                }

                $targetPath = $matchedPath ?: $module->root_path;
                $link = $this->docLinkRow($workspace, $item, [
                    'module_id' => $module->id,
                    'target_path' => $targetPath,
                    'link_type' => $matchedPath ? 'module_path' : 'module_capability',
                    'metadata' => [
                        'module_slug' => $module->slug,
                        'module_root_path' => $module->root_path,
                        'capability' => $matchedCapability,
                    ],
                ]);
                $docLinkRows[] = $link;
                $count++;

                if (count($docLinkRows) >= 1000) {
                    $this->upsertDocLinkRows($docLinkRows);
                    $docLinkRows = [];
                }
            }

            // AP-815 C6: resolve symbols from the pre-built path index (no per-item query).
            foreach ($paths as $path) {
                foreach ($symbolsByPath[$path] ?? [] as $symbol) {
                    $link = $this->docLinkRow($workspace, $item, [
                        'symbol_id' => $symbol->id,
                        'target_path' => $symbol->file_path,
                        'link_type' => 'symbol_path',
                        'metadata' => ['symbol_name' => $symbol->symbol_name, 'symbol_type' => $symbol->symbol_type],
                    ]);
                    $docLinkRows[] = $link;
                    $count++;

                    if (count($docLinkRows) >= 1000) {
                        $this->upsertDocLinkRows($docLinkRows);
                        $docLinkRows = [];
                    }
                }
            }
        }

        if ($docLinkRows !== []) {
            $this->upsertDocLinkRows($docLinkRows);
        }

        if ($prune) {
            $this->archiveStaleDocLinks($pruneStartedAt);
        }

        return $count;
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function upsertDocLinkRows(array $rows): void
    {
        $now = now();

        $keyed = $this->support->workspaceKeyed('atlas_engineering_doc_links');
        $workspaceId = $this->support->workspaceId;
        $prepared = array_map(function (array $row) use ($now, $keyed, $workspaceId): array {
            $row['id'] = (string) Str::uuid();
            $row['metadata'] = $this->support->json((array) ($row['metadata'] ?? []));
            if ($keyed) {
                $row['workspace_id'] = $workspaceId;
            }
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows);

        DB::table('atlas_engineering_doc_links')->upsert(
            $prepared,
            $this->support->workspaceConflictKey('atlas_engineering_doc_links', ['link_hash']),
            [
                'knowledge_item_id',
                'module_id',
                'symbol_id',
                'link_type',
                'status',
                'canonical_path',
                'target_path',
                'doc_hash',
                'target_hash',
                'metadata',
                'indexed_at',
                'archived_at',
                'updated_at',
            ],
        );
    }


    public function archiveStaleDocLinks(Carbon $pruneStartedAt): void
    {
        $archivedAt = now();

        $query = DB::table('atlas_engineering_doc_links')
            ->whereNull('archived_at')
            ->where(function ($query) use ($pruneStartedAt): void {
                $query
                    ->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<', $pruneStartedAt);
            });

        // AP-815 W-1: scope the prune to THIS workspace so a second project's index never
        // archives the primary workspace's doc-links (the leak that emptied atlas-server).
        if ($this->support->workspaceKeyed('atlas_engineering_doc_links')) {
            $query->where('workspace_id', $this->support->workspaceId);
        }

        $query->update(['status' => 'archived', 'archived_at' => $archivedAt]);
    }


    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    public function docLinkRow(string $workspace, AtlasEngineeringKnowledgeItem $item, array $target): array
    {
        $targetPath = is_string($target['target_path'] ?? null) ? $target['target_path'] : null;
        $targetFullPath = $targetPath ? $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $targetPath) : null;
        $isModuleLevelLink = ($target['module_id'] ?? null) !== null
            && ($target['symbol_id'] ?? null) === null
            && in_array($target['link_type'] ?? null, ['module_path', 'module_capability'], true);
        $targetExists = $targetFullPath && File::exists($targetFullPath);
        $targetHash = null;
        if ($targetFullPath && File::isFile($targetFullPath)) {
            if (! array_key_exists($targetFullPath, $this->docLinkTargetHashCache)) {
                $this->docLinkTargetHashCache[$targetFullPath] = hash_file('sha256', $targetFullPath) ?: null;
            }

            $targetHash = $this->docLinkTargetHashCache[$targetFullPath];
        }
        $status = $targetPath === null || $targetExists || $isModuleLevelLink ? 'current' : 'missing_target';
        $linkHash = hash('sha256', implode('|', [
            $item->id,
            $target['module_id'] ?? '',
            $target['symbol_id'] ?? '',
            $target['link_type'] ?? '',
            $targetPath ?? '',
        ]));

        return [
            'knowledge_item_id' => $item->id,
            'module_id' => $target['module_id'] ?? null,
            'symbol_id' => $target['symbol_id'] ?? null,
            'link_type' => $target['link_type'],
            'status' => $status,
            'canonical_path' => $item->canonical_path,
            'target_path' => $targetPath,
            'doc_hash' => $item->content_hash,
            'target_hash' => $targetHash,
            'link_hash' => $linkHash,
            'metadata' => $target['metadata'] ?? [],
            'indexed_at' => now(),
            'archived_at' => null,
        ];
    }


    public function refreshDocumentationStatus(): void
    {
        $moduleDocs = [];

        $docLinkQuery = DB::table('atlas_engineering_doc_links')
            ->whereNotNull('module_id')
            ->whereNull('archived_at')
            ->where('status', 'current');
        if ($this->support->workspaceKeyed('atlas_engineering_doc_links')) {
            $docLinkQuery->where('workspace_id', $this->support->workspaceId);
        }

        foreach ($docLinkQuery
            ->select(['module_id', 'canonical_path', 'doc_hash'])
            ->orderBy('module_id')
            ->cursor() as $link) {
            $moduleId = (string) $link->module_id;

            $moduleDocs[$moduleId]['paths'][] = (string) $link->canonical_path;

            if (is_string($link->doc_hash) && $link->doc_hash !== '') {
                $moduleDocs[$moduleId]['hashes'][] = $link->doc_hash;
            }
        }

        foreach ($moduleDocs as $moduleId => $docs) {
            $paths = EngineeringStringListNormalizer::uniqueStringCasts($docs['paths'] ?? []);
            $hashes = array_values(array_filter($docs['hashes'] ?? [], fn (string $hash): bool => $hash !== ''));
            sort($hashes);

            $moduleDocs[$moduleId] = [
                'paths' => $paths,
                'docs_hash' => $hashes === [] ? null : hash('sha256', implode('|', $hashes)),
            ];
        }

        $documentedModuleIds = [];
        $now = now();

        $moduleQuery = DB::table('atlas_engineering_code_modules')
            ->where('status', 'active')
            ->whereNull('archived_at');
        if ($this->support->workspaceKeyed('atlas_engineering_code_modules')) {
            $moduleQuery->where('workspace_id', $this->support->workspaceId);
        }

        $moduleQuery
            ->select(['id', 'docs_status', 'docs_hash', 'related_docs_json'])
            ->orderBy('id')
            ->chunkById(200, function ($modules) use ($moduleDocs, &$documentedModuleIds, $now): void {
                foreach ($modules as $module) {
                    $moduleId = (string) $module->id;
                    $docs = $moduleDocs[$moduleId] ?? ['paths' => [], 'docs_hash' => null];
                    $relatedDocs = $docs['paths'];

                    if ($relatedDocs !== []) {
                        $documentedModuleIds[$moduleId] = true;
                    }

                    $targetStatus = $relatedDocs === [] ? 'undocumented' : 'documented';
                    $targetRelated = $this->support->json($relatedDocs);
                    $targetHash = $docs['docs_hash'];

                    // AP-815 C4: only write when something actually changed — turns a
                    // per-module UPDATE-every-index into UPDATE-only-when-changed (most
                    // modules' docs are stable across re-indexes).
                    if ((string) ($module->docs_status ?? '') === $targetStatus
                        && (string) ($module->docs_hash ?? '') === (string) ($targetHash ?? '')
                        && (string) ($module->related_docs_json ?? '') === $targetRelated) {
                        continue;
                    }

                    DB::table('atlas_engineering_code_modules')->where('id', $moduleId)->update([
                        'docs_status' => $targetStatus,
                        'related_docs_json' => $targetRelated,
                        'docs_hash' => $targetHash,
                        'updated_at' => $now,
                    ]);
                }
            });

        $this->refreshSymbolDocumentationStatus(array_keys($documentedModuleIds), $now);
    }


    /**
     * @param  array<int,string>  $documentedModuleIds
     */
    public function refreshSymbolDocumentationStatus(array $documentedModuleIds, Carbon $now): void
    {
        $baseQuery = DB::table('atlas_engineering_code_symbols')
            ->where('status', 'active')
            ->whereNull('archived_at');
        if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $baseQuery->where('workspace_id', $this->support->workspaceId);
        }
        $directDocSymbolIds = function () {
            $query = DB::table('atlas_engineering_doc_links')
                ->select('symbol_id')
                ->whereNotNull('symbol_id')
                ->whereNull('archived_at')
                ->where('status', 'current');
            if ($this->support->workspaceKeyed('atlas_engineering_doc_links')) {
                $query->where('workspace_id', $this->support->workspaceId);
            }

            return $query;
        };

        if ($documentedModuleIds !== []) {
            $this->support->withDeadlockRetry(fn () => (clone $baseQuery)
                ->whereIn('module_id', $documentedModuleIds)
                ->whereNotIn('id', $directDocSymbolIds())
                ->where('docs_status', '!=', 'module_documented')
                ->update([
                    'docs_status' => 'module_documented',
                    'related_doc_ids_json' => $this->support->json([]),
                    'updated_at' => $now,
                ]));

            $this->support->withDeadlockRetry(fn () => (clone $baseQuery)
                ->where(function ($query) use ($documentedModuleIds): void {
                    $query
                        ->whereNull('module_id')
                        ->orWhereNotIn('module_id', $documentedModuleIds);
                })
                ->whereNotIn('id', $directDocSymbolIds())
                ->where('docs_status', '!=', 'undocumented')
                ->update([
                    'docs_status' => 'undocumented',
                    'related_doc_ids_json' => $this->support->json([]),
                    'updated_at' => $now,
                ]));
        } else {
            $this->support->withDeadlockRetry(fn () => (clone $baseQuery)
                ->whereNotIn('id', $directDocSymbolIds())
                ->where('docs_status', '!=', 'undocumented')
                ->update([
                    'docs_status' => 'undocumented',
                    'related_doc_ids_json' => $this->support->json([]),
                    'updated_at' => $now,
                ]));
        }

        $rows = [];
        $currentSymbolId = null;
        $currentDocIds = [];

        $docLinkQuery = DB::table('atlas_engineering_doc_links')
            ->whereNotNull('symbol_id')
            ->whereNull('archived_at')
            ->where('status', 'current');
        if ($this->support->workspaceKeyed('atlas_engineering_doc_links')) {
            $docLinkQuery->where('workspace_id', $this->support->workspaceId);
        }

        foreach ($docLinkQuery
            ->select(['symbol_id', 'knowledge_item_id'])
            ->orderBy('symbol_id')
            ->cursor() as $link) {
            $symbolId = (string) $link->symbol_id;
            $knowledgeItemId = (string) $link->knowledge_item_id;

            if ($currentSymbolId !== null && $symbolId !== $currentSymbolId) {
                $rows[] = $this->symbolDocumentationUpdateRow($currentSymbolId, $currentDocIds, $now);
                $currentDocIds = [];

                if (count($rows) >= 1000) {
                    $this->upsertSymbolDocumentationRows($rows);
                    $rows = [];
                }
            }

            $currentSymbolId = $symbolId;
            if ($knowledgeItemId !== '') {
                $currentDocIds[$knowledgeItemId] = true;
            }
        }

        if ($currentSymbolId !== null) {
            $rows[] = $this->symbolDocumentationUpdateRow($currentSymbolId, $currentDocIds, $now);
        }

        if ($rows !== []) {
            $this->upsertSymbolDocumentationRows($rows);
        }
    }


    /**
     * @param  array<string,bool>  $docIds
     * @return array<string,mixed>
     */
    public function symbolDocumentationUpdateRow(string $symbolId, array $docIds, Carbon $now): array
    {
        return $this->symbolExtractor->symbolDocumentationUpdateRow($symbolId, $docIds, $now);
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function upsertSymbolDocumentationRows(array $rows): void
    {
        collect($rows)
            ->groupBy(fn (array $row): string => (string) $row['related_doc_ids_json'])
            ->each(function (Collection $group): void {
                $this->support->withDeadlockRetry(function () use ($group): void {
                    $query = DB::table('atlas_engineering_code_symbols')
                        ->whereIn('id', $group->pluck('id')->all());
                    if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
                        $query->where('workspace_id', $this->support->workspaceId);
                    }

                    $query->update([
                        'docs_status' => 'documented',
                        'related_doc_ids_json' => (string) $group->first()['related_doc_ids_json'],
                        'updated_at' => $group->first()['updated_at'],
                    ]);
                });
            });
    }


    public function pathMatchesModule(string $path, AtlasEngineeringCodeModule $module): bool
    {
        $root = trim((string) $module->root_path, '/');
        $path = trim($path, '/');

        if ($root === '') {
            return false;
        }

        if ($path === $root || str_starts_with($path, $root.'/') || str_starts_with($root, $path.'/')) {
            return true;
        }

        if (! str_starts_with($path, $root)) {
            return false;
        }

        $nextCharacter = substr($path, strlen($root), 1);

        return $nextCharacter === '' || $nextCharacter === '.' || ctype_upper($nextCharacter);
    }
}
