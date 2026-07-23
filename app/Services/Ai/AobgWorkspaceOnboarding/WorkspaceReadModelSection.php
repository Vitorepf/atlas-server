<?php

declare(strict_types=1);

namespace App\Services\Ai\AobgWorkspaceOnboarding;

use App\Services\Ai\AtlasAobgWorkspaceOnboardingService as Facade;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-model family for the AOBG workspace-onboarding façade: everything that reads
 * the W-1 code-intelligence tables (inventory, counts, samples, path regions) plus the
 * bounded snapshot freshness check. Leaf section — depends only on the façade static
 * constants; never on another section.
 */
class WorkspaceReadModelSection
{
    /**
     * @return array<string,mixed>
     */
    public function workspaceInventory(string $workspaceId): array
    {
        $symbolTypes = $this->groupedCount(Facade::SYMBOLS_TABLE, 'symbol_type', $workspaceId);
        $modules = $this->countRows(Facade::MODULES_TABLE, $workspaceId);
        $symbols = array_sum($symbolTypes);
        $docLinks = $this->countRows(Facade::DOC_LINKS_TABLE, $workspaceId, ['status' => 'current']);
        $files = $this->countRows(Facade::FILE_SNAPSHOTS_TABLE, $workspaceId);

        return [
            'status' => $symbols > 0 ? 'ready' : 'empty',
            'tables' => [
                'modules' => Schema::hasTable(Facade::MODULES_TABLE),
                'symbols' => Schema::hasTable(Facade::SYMBOLS_TABLE),
                'doc_links' => Schema::hasTable(Facade::DOC_LINKS_TABLE),
                'file_snapshots' => Schema::hasTable(Facade::FILE_SNAPSHOTS_TABLE),
            ],
            'module_count' => $modules,
            'symbol_count' => $symbols,
            'file_count' => $files,
            'doc_link_count' => $docLinks,
            'route_count' => (int) (($symbolTypes['route'] ?? 0) + ($symbolTypes['api_resource'] ?? 0)),
            'command_count' => (int) ($symbolTypes['cli_command'] ?? 0),
            'migration_count' => (int) ($symbolTypes['migration_table'] ?? 0),
            'test_count' => (int) ($symbolTypes['test_method'] ?? 0),
            'symbol_types' => $symbolTypes,
            'languages' => $this->groupedCount(Facade::SYMBOLS_TABLE, 'language', $workspaceId),
            'layers' => $this->groupedCount(Facade::MODULES_TABLE, 'layer', $workspaceId),
            'docs_status' => $this->groupedCount(Facade::MODULES_TABLE, 'docs_status', $workspaceId),
            'doc_link_types' => $this->groupedCount(Facade::DOC_LINKS_TABLE, 'link_type', $workspaceId),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function topModules(string $workspaceId, int $limit): array
    {
        try {
            if (! Schema::hasTable(Facade::MODULES_TABLE)) {
                return [];
            }

            $query = $this->activeQuery(Facade::MODULES_TABLE, $workspaceId);
            $select = [];
            foreach (['slug', 'name', 'layer', 'root_path', 'primary_language', 'docs_status', 'file_count', 'symbol_count', 'route_count', 'command_count', 'migration_count', 'test_count', 'related_docs_json', 'related_tests_json', 'indexed_at'] as $column) {
                if (Schema::hasColumn(Facade::MODULES_TABLE, $column)) {
                    $select[] = $column;
                }
            }
            if ($select === []) {
                return [];
            }

            if (Schema::hasColumn(Facade::MODULES_TABLE, 'symbol_count')) {
                $query->orderByDesc('symbol_count');
            }
            if (Schema::hasColumn(Facade::MODULES_TABLE, 'slug')) {
                $query->orderBy('slug');
            }

            return $query
                ->select($select)
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => [
                    'slug' => $row->slug ?? null,
                    'name' => $row->name ?? null,
                    'layer' => $row->layer ?? null,
                    'root_path' => $row->root_path ?? null,
                    'primary_language' => $row->primary_language ?? null,
                    'docs_status' => $row->docs_status ?? null,
                    'file_count' => (int) ($row->file_count ?? 0),
                    'symbol_count' => (int) ($row->symbol_count ?? 0),
                    'route_count' => (int) ($row->route_count ?? 0),
                    'command_count' => (int) ($row->command_count ?? 0),
                    'migration_count' => (int) ($row->migration_count ?? 0),
                    'test_count' => (int) ($row->test_count ?? 0),
                    'related_docs' => array_slice($this->jsonList($row->related_docs_json ?? []), 0, 12),
                    'related_tests' => array_slice($this->jsonList($row->related_tests_json ?? []), 0, 12),
                    'indexed_at' => $row->indexed_at ?? null,
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int,string>  $types
     * @return array<int,array<string,mixed>>
     */
    public function sampleSymbols(string $workspaceId, array $types, int $limit): array
    {
        try {
            if (! Schema::hasTable(Facade::SYMBOLS_TABLE)) {
                return [];
            }

            $query = $this->activeQuery(Facade::SYMBOLS_TABLE, $workspaceId, 'symbols')
                ->whereIn('symbols.symbol_type', $types);
            $select = [];
            foreach (['symbol_type', 'symbol_name', 'file_path', 'line_start', 'language', 'signature', 'metadata'] as $column) {
                if (Schema::hasColumn(Facade::SYMBOLS_TABLE, $column)) {
                    $select[] = 'symbols.'.$column;
                }
            }
            if ($select === []) {
                return [];
            }

            if (Schema::hasTable(Facade::MODULES_TABLE) && Schema::hasColumn(Facade::SYMBOLS_TABLE, 'module_id') && Schema::hasColumn(Facade::MODULES_TABLE, 'id')) {
                $query->leftJoin(Facade::MODULES_TABLE.' as modules', 'modules.id', '=', 'symbols.module_id');
                if (Schema::hasColumn(Facade::MODULES_TABLE, 'slug')) {
                    $select[] = 'modules.slug as module_slug';
                }
            }

            $query->orderBy('symbols.file_path');
            if (Schema::hasColumn(Facade::SYMBOLS_TABLE, 'line_start')) {
                $query->orderBy('symbols.line_start');
            }

            return $query
                ->select($select)
                ->limit($limit)
                ->get()
                ->map(fn (object $row): array => $this->symbolSamplePayload($row))
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pathRegions(string $workspaceId, int $limit): array
    {
        try {
            if (! Schema::hasTable(Facade::SYMBOLS_TABLE) || ! Schema::hasColumn(Facade::SYMBOLS_TABLE, 'file_path')) {
                return [];
            }

            $rows = $this->activeQuery(Facade::SYMBOLS_TABLE, $workspaceId)
                ->select('file_path')
                ->selectRaw('count(*) as aggregate')
                ->groupBy('file_path')
                ->orderByDesc('aggregate')
                ->limit(1000)
                ->get();

            $regions = [];
            foreach ($rows as $row) {
                $region = $this->pathRegion((string) ($row->file_path ?? ''));
                if ($region === '') {
                    continue;
                }
                $regions[$region] ??= ['region' => $region, 'file_count' => 0, 'symbol_count' => 0, 'sample_files' => []];
                $regions[$region]['file_count']++;
                $regions[$region]['symbol_count'] += (int) ($row->aggregate ?? 0);
                if (count($regions[$region]['sample_files']) < 4) {
                    $regions[$region]['sample_files'][] = (string) $row->file_path;
                }
            }

            usort($regions, static fn (array $a, array $b): int => ($b['symbol_count'] <=> $a['symbol_count']) ?: strcmp($a['region'], $b['region']));

            return array_slice(array_values($regions), 0, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,string>  $equals
     */
    public function countRows(string $table, string $workspaceId, array $equals = []): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            $query = $this->activeQuery($table, $workspaceId);
            foreach ($equals as $column => $value) {
                if (Schema::hasColumn($table, $column)) {
                    $query->where($column, $value);
                }
            }

            return (int) $query->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,int>
     */
    public function groupedCount(string $table, string $column, string $workspaceId): array
    {
        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return [];
            }

            return $this->activeQuery($table, $workspaceId)
                ->select($column)
                ->selectRaw('count(*) as aggregate')
                ->groupBy($column)
                ->orderBy($column)
                ->get()
                ->mapWithKeys(fn (object $row): array => [
                    ((string) ($row->{$column} ?? 'unknown')) ?: 'unknown' => (int) ($row->aggregate ?? 0),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return Builder
     */
    public function activeQuery(string $table, string $workspaceId, ?string $alias = null)
    {
        $query = DB::table($alias === null ? $table : $table.' as '.$alias);
        $prefix = $alias === null ? '' : $alias.'.';

        if (Schema::hasColumn($table, 'status')) {
            $query->where($prefix.'status', $table === Facade::DOC_LINKS_TABLE ? 'current' : 'active');
        }
        if (Schema::hasColumn($table, 'archived_at')) {
            $query->whereNull($prefix.'archived_at');
        }
        if (Schema::hasColumn($table, 'workspace_id')) {
            $query->where($prefix.'workspace_id', $workspaceId);
        }

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    public function symbolSamplePayload(object $row): array
    {
        $metadata = $this->jsonMap($row->metadata ?? []);

        return [
            'type' => $row->symbol_type ?? null,
            'name' => $row->symbol_name ?? null,
            'module' => $row->module_slug ?? null,
            'path' => $row->file_path ?? null,
            'line' => isset($row->line_start) ? (int) $row->line_start : null,
            'language' => $row->language ?? null,
            'signature' => $this->shortText($row->signature ?? null, 180),
            'metadata' => $this->compactMetadata(array_intersect_key($metadata, array_flip([
                'http_method',
                'uri',
                'target',
                'controller',
                'method',
                'command_signature',
                'operation',
                'table',
                'classification',
            ]))),
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function compactMetadata(array $metadata): array
    {
        $compact = [];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $compact[$key] = $this->shortText($value, 180);
            } elseif (is_array($value)) {
                $compact[$key] = $this->shortText(json_encode(array_slice($value, 0, 6), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 180);
            }
        }

        return $compact;
    }

    /**
     * Symbol count + last index timestamp scoped to the resolved workspace_id ONLY.
     * Fail-safe: a missing table / missing workspace_id column / transient fault → an
     * honest zero (never a fabricated count, never a throw).
     *
     * @return array{symbols:int, last_index:?string}
     */
    public function symbolCounts(string $workspaceId): array
    {
        $empty = ['symbols' => 0, 'last_index' => null];

        try {
            if (! Schema::hasTable(Facade::SYMBOLS_TABLE)) {
                return $empty;
            }

            $query = DB::table(Facade::SYMBOLS_TABLE)->where('status', 'active');

            // Scope to THIS workspace when the read-model is W-1-keyed (post-migration).
            // Without the column the read-model predates multi-workspace — degrade to an
            // honest unscoped count rather than leaking or fabricating.
            if (Schema::hasColumn(Facade::SYMBOLS_TABLE, 'workspace_id')) {
                $query->where('workspace_id', $workspaceId);
            }

            $symbols = (int) (clone $query)->count();
            if ($symbols === 0) {
                return $empty;
            }

            $lastIndex = $this->lastIndexTimestamp(clone $query);

            return ['symbols' => $symbols, 'last_index' => $lastIndex];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * The most recent index time for the scoped symbols — prefers `indexed_at` (the real
     * index event) and falls back to `updated_at`. Returns null when neither is present.
     *
     * @param  Builder  $scoped
     */
    public function lastIndexTimestamp($scoped): ?string
    {
        foreach (['indexed_at', 'updated_at'] as $column) {
            if (! Schema::hasColumn(Facade::SYMBOLS_TABLE, $column)) {
                continue;
            }
            $value = (clone $scoped)->max($column);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Provider-safe, bounded freshness check for the already-known file snapshot set.
     *
     * It does not crawl the whole repo. It samples the scoped snapshot read-model and
     * compares current file mtimes with the mtime captured at index time.
     *
     * @return array<string,mixed>
     */
    public function freshnessStatus(?string $workspacePath, string $workspaceId, ?string $lastIndex): array
    {
        $base = [
            'schema' => 'atlas.aobg.workspace_freshness.v1',
            'status' => 'unknown',
            'reason' => null,
            'last_index' => $lastIndex,
            'checked_files' => 0,
            'changed_files' => [],
            'missing_files' => [],
            'sample_limit' => 300,
            'source_policy' => [
                'file_content_read' => false,
                'raw_diff_returned' => false,
                'bounded_snapshot_sample' => true,
            ],
        ];

        if (! is_string($workspacePath) || $workspacePath === '' || ! is_dir($workspacePath)) {
            $base['reason'] = 'workspace_path_unavailable';

            return $base;
        }
        if ($lastIndex === null) {
            $base['reason'] = 'last_index_missing';

            return $base;
        }
        if (! Schema::hasTable(Facade::FILE_SNAPSHOTS_TABLE) || ! Schema::hasColumn(Facade::FILE_SNAPSHOTS_TABLE, 'file_path')) {
            $base['reason'] = 'file_snapshot_table_unavailable';

            return $base;
        }

        try {
            $query = $this->activeQuery(Facade::FILE_SNAPSHOTS_TABLE, $workspaceId)
                ->select(array_values(array_filter([
                    'file_path',
                    Schema::hasColumn(Facade::FILE_SNAPSHOTS_TABLE, 'mtime') ? 'mtime' : null,
                    Schema::hasColumn(Facade::FILE_SNAPSHOTS_TABLE, 'indexed_at') ? 'indexed_at' : null,
                ])))
                ->limit((int) $base['sample_limit']);

            if (Schema::hasColumn(Facade::FILE_SNAPSHOTS_TABLE, 'indexed_at')) {
                $query->orderByDesc('indexed_at');
            }

            $rows = $query->get();
            if ($rows->isEmpty()) {
                $base['reason'] = 'file_snapshots_empty';

                return $base;
            }

            $lastIndexTs = strtotime($lastIndex) ?: null;
            foreach ($rows as $row) {
                $relative = trim((string) ($row->file_path ?? ''));
                if ($relative === '') {
                    continue;
                }

                $path = $this->snapshotAbsolutePath($workspacePath, $relative);
                $base['checked_files']++;
                if (! is_file($path)) {
                    if (count($base['missing_files']) < 8) {
                        $base['missing_files'][] = $relative;
                    }

                    continue;
                }

                clearstatcache(false, $path);
                $currentMtime = filemtime($path);
                if (! is_int($currentMtime)) {
                    continue;
                }

                $storedMtime = $this->intFromMixed($row->mtime ?? null);
                $indexedTs = isset($row->indexed_at) && $row->indexed_at !== null
                    ? (strtotime((string) $row->indexed_at) ?: $lastIndexTs)
                    : $lastIndexTs;
                $baseline = $storedMtime !== null && $storedMtime > 0 ? $storedMtime : $indexedTs;
                if ($baseline !== null && $currentMtime > $baseline) {
                    if (count($base['changed_files']) < 8) {
                        $base['changed_files'][] = [
                            'path' => $relative,
                            'mtime' => $currentMtime,
                            'indexed_mtime' => $baseline,
                        ];
                    }
                }
            }

            if ($base['changed_files'] !== [] || $base['missing_files'] !== []) {
                $base['status'] = 'stale';
                $base['reason'] = $base['changed_files'] !== [] ? 'known_snapshot_changed' : 'known_snapshot_missing';

                return $base;
            }

            $base['status'] = 'fresh';
            $base['reason'] = 'known_snapshots_match';

            return $base;
        } catch (Throwable $e) {
            $base['reason'] = 'freshness_check_failed';
            $base['exception'] = class_basename($e);

            return $base;
        }
    }

    public function snapshotAbsolutePath(string $workspacePath, string $filePath): string
    {
        if (str_starts_with($filePath, DIRECTORY_SEPARATOR)) {
            return $filePath;
        }

        return rtrim($workspacePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filePath;
    }

    public function intFromMixed(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function pathRegion(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) <= 1) {
            return $segments[0] ?? '';
        }

        if (in_array($segments[0], ['routes', 'config', 'tests'], true)) {
            return $segments[0];
        }
        if ($segments[0] === 'database' && ($segments[1] ?? '') === 'migrations') {
            return 'database/migrations';
        }

        return $segments[0].'/'.$segments[1];
    }

    /**
     * @return array<int,mixed>
     */
    private function jsonList(mixed $value): array
    {
        $decoded = $this->jsonValue($value);

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonMap(mixed $value): array
    {
        $decoded = $this->jsonValue($value);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /**
     * @return array<mixed>|mixed
     */
    private function jsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function shortText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, max(1, $limit - 3)).'...' : $text;
    }
}
