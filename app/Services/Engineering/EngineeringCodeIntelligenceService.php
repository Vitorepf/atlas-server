<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Tools\AtlasToolEvidenceStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class EngineeringCodeIntelligenceService
{
    private const EXTENSIONS = ['php', 'ts', 'tsx', 'js', 'jsx', 'md'];

    public function __construct(
        private readonly AtlasToolEvidenceStore $toolEvidence,
        private readonly ?EngineeringContextIntelligenceInput $input = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function index(array $options = []): array
    {
        $startedAt = microtime(true);
        $this->ensureIndexMemoryBudget();
        $this->ensureTables();

        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $prune = (bool) ($options['prune'] ?? false);
        $context = $this->toolRuntimeContext($options);
        $scan = $this->scanWorkspace($workspace);
        $moduleRows = $scan['modules'];
        $symbolRows = $scan['symbols'];

        if ($dryRun) {
            $payload = [
                'ok' => true,
                'dry_run' => true,
                'workspace' => $workspace,
                'summary' => $scan['summary'],
                'modules' => $moduleRows,
                'symbols_preview' => array_slice($symbolRows, 0, 80),
                'duration_ms' => $this->elapsedMs($startedAt),
                'generated_at' => now()->toJSON(),
            ];
            $this->recordToolRuntimeEvidence('index', $workspace, $payload, $context);

            return $payload;
        }

        $moduleIds = $this->persistModules($moduleRows, $prune);
        $symbolCount = $this->persistSymbols($symbolRows, $moduleIds, $prune);
        $docLinkCount = $this->syncDocLinks($workspace, $prune);
        $summary = $this->scanSummary($moduleRows, $symbolRows, $docLinkCount);
        unset($scan, $moduleRows, $symbolRows, $moduleIds);
        $this->refreshDocumentationStatus();

        $payload = [
            'ok' => true,
            'dry_run' => false,
            'workspace' => $workspace,
            'summary' => $summary,
            'modules' => $this->catalog([], 30)['modules'],
            'symbol_count' => $symbolCount,
            'duration_ms' => $this->elapsedMs($startedAt),
            'generated_at' => now()->toJSON(),
        ];
        $this->recordToolRuntimeEvidence('index', $workspace, $payload, $context);

        return $payload;
    }

    private function ensureIndexMemoryBudget(): void
    {
        $current = ini_get('memory_limit');
        if ($current === false || $current === '-1') {
            return;
        }

        if ($this->memoryLimitToBytes($current) < 512 * 1024 * 1024) {
            ini_set('memory_limit', '512M');
        }
    }

    private function memoryLimitToBytes(string $value): int
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 0;
        }

        $unit = strtolower(substr($normalized, -1));
        $number = (int) $normalized;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $normalized,
        };
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $startedAt = microtime(true);
        $this->ensureTables();

        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $limit = $this->contextInput()->codeLimit($options['limit'] ?? null);
        $context = $this->toolRuntimeContext($options);
        $scan = $this->scanWorkspace($workspace);
        $modules = $this->moduleDrift($scan['modules'], $limit);
        $symbols = $this->symbolDrift($scan['symbols'], $limit);
        $docLinks = $this->docLinkHealth($workspace, $limit);
        $moduleDrift = array_sum($modules['counts']);
        $symbolDrift = array_sum($symbols['counts']);
        $docLinkDrift = (int) ($docLinks['counts']['missing_targets'] ?? 0)
            + (int) ($docLinks['counts']['stale_target_hashes'] ?? 0);
        $totalDrift = $moduleDrift + $symbolDrift + $docLinkDrift;
        $persisted = $this->summary();

        $payload = [
            'ok' => true,
            'dry_run' => true,
            'writes' => false,
            'status' => ((int) ($persisted['module_count'] ?? 0)) === 0
                ? 'empty_index'
                : ($totalDrift > 0 ? 'drift_detected' : 'fresh'),
            'workspace' => $workspace,
            'summary' => [
                'scanned' => $scan['summary'],
                'persisted' => $persisted,
                'drift' => [
                    'total' => $totalDrift,
                    'modules' => $modules['counts'],
                    'symbols' => $symbols['counts'],
                    'doc_links' => $docLinks['counts'],
                ],
            ],
            'drift' => [
                'modules' => $modules,
                'symbols' => $symbols,
                'doc_links' => $docLinks,
            ],
            'duration_ms' => $this->elapsedMs($startedAt),
            'generated_at' => now()->toJSON(),
        ];
        $this->recordToolRuntimeEvidence('audit', $workspace, $payload, $context);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function catalog(array $filters = [], int $limit = 50): array
    {
        if (! $this->tablesExist()) {
            return [
                'summary' => $this->summary(),
                'modules' => [],
            ];
        }

        $query = AtlasEngineeringCodeModule::query()->withCount('symbols');
        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->active();
        }
        if (is_string($filters['layer'] ?? null) && trim((string) $filters['layer']) !== '') {
            $query->where('layer', Str::slug(trim((string) $filters['layer']), '_'));
        }
        if (is_string($filters['docs_status'] ?? null) && trim((string) $filters['docs_status']) !== '') {
            $query->where('docs_status', trim((string) $filters['docs_status']));
        }
        if (is_string($filters['q'] ?? null) && trim((string) $filters['q']) !== '') {
            $q = trim((string) $filters['q']);
            $query->where(function (Builder $query) use ($q): void {
                $query->where('slug', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('root_path', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $modules = $query
            ->orderByDesc('symbol_count')
            ->orderBy('slug')
            ->limit($this->contextInput()->codeLimit($limit))
            ->get()
            ->map(fn (AtlasEngineeringCodeModule $module): array => $this->modulePayload($module))
            ->values()
            ->all();

        return [
            'summary' => $this->summary(),
            'modules' => $modules,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function symbols(array $filters = [], int $limit = 100): array
    {
        if (! $this->tablesExist()) {
            return [
                'summary' => $this->summary(),
                'symbols' => [],
            ];
        }

        $query = AtlasEngineeringCodeSymbol::query()->with('module');
        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->active();
        }
        foreach (['symbol_type', 'language', 'docs_status'] as $field) {
            if (is_string($filters[$field] ?? null) && trim((string) $filters[$field]) !== '') {
                $query->where($field, trim((string) $filters[$field]));
            }
        }
        if (is_string($filters['module'] ?? null) && trim((string) $filters['module']) !== '') {
            $module = trim((string) $filters['module']);
            $query->whereHas('module', function (Builder $query) use ($module): void {
                $query->where('slug', $module);
                if (Str::isUuid($module)) {
                    $query->orWhere('id', $module);
                }
            });
        }
        if (is_string($filters['q'] ?? null) && trim((string) $filters['q']) !== '') {
            $q = trim((string) $filters['q']);
            $query->where(function (Builder $query) use ($q): void {
                $query->where('symbol_name', 'like', "%{$q}%")
                    ->orWhere('file_path', 'like', "%{$q}%")
                    ->orWhere('signature', 'like', "%{$q}%");
            });
        }

        return [
            'summary' => $this->summary(),
            'symbols' => $query
                ->orderBy('file_path')
                ->orderBy('line_start')
                ->limit($this->contextInput()->codeLimit($limit))
                ->get()
                ->map(fn (AtlasEngineeringCodeSymbol $symbol): array => $this->symbolPayload($symbol))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function module(string $idOrSlug): ?array
    {
        if (! $this->tablesExist()) {
            return null;
        }

        $module = AtlasEngineeringCodeModule::query()
            ->with(['symbols' => fn ($query) => $query->active()->orderBy('symbol_type')->orderBy('file_path')->limit(120), 'docLinks'])
            ->where('slug', $idOrSlug)
            ->when(Str::isUuid($idOrSlug), fn (Builder $query) => $query->orWhere('id', $idOrSlug))
            ->first();

        if (! $module) {
            return null;
        }

        return [
            'module' => $this->modulePayload($module),
            'symbols' => $module->symbols->map(fn (AtlasEngineeringCodeSymbol $symbol): array => $this->symbolPayload($symbol))->values()->all(),
            'doc_links' => $module->docLinks->map(fn (AtlasEngineeringDocLink $link): array => $this->docLinkPayload($link))->values()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    public function contextRefs(array $context = [], int $limit = 12): array
    {
        if (! $this->tablesExist()) {
            return [];
        }

        $tags = collect((array) data_get($context, 'contract.tags', []))
            ->merge((array) ($context['tags'] ?? []))
            ->map(fn (mixed $tag): string => Str::slug((string) $tag, '_'))
            ->filter()
            ->values()
            ->all();

        $query = AtlasEngineeringCodeModule::query()->active();
        if ($tags !== []) {
            $query->orderByRaw(
                'case when slug like ? or layer like ? then 0 else 1 end',
                ['%'.$tags[0].'%', '%'.$tags[0].'%'],
            );
        }

        return $query
            ->orderByDesc('symbol_count')
            ->orderByRaw("case when docs_status = 'documented' then 0 when docs_status = 'inferred' then 1 else 2 end")
            ->limit($this->contextInput()->codeLimit($limit))
            ->get()
            ->map(fn (AtlasEngineeringCodeModule $module): array => [
                'type' => 'atlas_engineering_code_module',
                'id' => $module->id,
                'slug' => $module->slug,
                'name' => $module->name,
                'layer' => $module->layer,
                'root_path' => $module->root_path,
                'docs_status' => $module->docs_status,
                'file_count' => $module->file_count,
                'symbol_count' => $module->symbol_count,
                'route_count' => $module->route_count,
                'command_count' => $module->command_count,
                'migration_count' => $module->migration_count,
                'test_count' => $module->test_count,
                'related_docs' => $module->related_docs_json ?? [],
                'related_tests' => $module->related_tests_json ?? [],
                'reason' => $tags !== [] && str_contains($module->slug, $tags[0])
                    ? 'matched_code_context'
                    : 'important_code_module',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        if (! $this->tablesExist()) {
            return [
                'status' => 'not_migrated',
                'table_exists' => false,
                'module_count' => 0,
                'symbol_count' => 0,
                'doc_link_count' => 0,
            ];
        }

        $moduleCount = AtlasEngineeringCodeModule::query()->active()->count();
        $symbolCount = AtlasEngineeringCodeSymbol::query()->active()->count();
        $lastIndexedAt = collect([
            AtlasEngineeringCodeModule::query()->active()->max('indexed_at'),
            AtlasEngineeringCodeSymbol::query()->active()->max('indexed_at'),
        ])->filter()->sort()->last();

        return [
            'status' => $moduleCount > 0 ? 'ready' : 'empty',
            'table_exists' => true,
            'module_count' => $moduleCount,
            'symbol_count' => $symbolCount,
            'doc_link_count' => AtlasEngineeringDocLink::query()->whereNull('archived_at')->count(),
            'route_count' => AtlasEngineeringCodeSymbol::query()->active()->whereIn('symbol_type', ['route', 'api_resource'])->count(),
            'command_count' => AtlasEngineeringCodeSymbol::query()->active()->where('symbol_type', 'cli_command')->count(),
            'migration_count' => AtlasEngineeringCodeSymbol::query()->active()->where('symbol_type', 'migration_table')->count(),
            'test_count' => AtlasEngineeringCodeSymbol::query()->active()->where('symbol_type', 'test_method')->count(),
            'docs_status' => $this->groupedModuleCounts('docs_status'),
            'layers' => $this->groupedModuleCounts('layer'),
            'last_indexed_at' => $lastIndexedAt ? Carbon::parse($lastIndexedAt)->toJSON() : null,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function groupedModuleCounts(string $column): array
    {
        return AtlasEngineeringCodeModule::query()
            ->active()
            ->select($column)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordToolRuntimeEvidence(string $operation, string $workspace, array $payload, array $context = []): void
    {
        try {
            $summary = (array) ($payload['summary'] ?? []);
            $drift = (array) ($summary['drift'] ?? []);
            $status = match ($operation) {
                'audit' => (($payload['status'] ?? null) === 'fresh' ? 'passed' : 'failed'),
                default => ($payload['ok'] ?? false) ? ((bool) ($payload['dry_run'] ?? false) ? 'skipped' : 'passed') : 'failed',
            };

            $this->toolEvidence->recordExternalToolResult('atlas_code_intelligence', $workspace, [
                'status' => $status,
                'required' => false,
                'failure_policy' => 'advisory',
                'policy_decision' => 'allowed',
                'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                'exit_code' => ($payload['ok'] ?? false) ? 0 : 1,
                'category' => 'code_intelligence',
                'metrics' => [
                    'operation' => $operation,
                    'module_count' => data_get($summary, 'module_count', data_get($summary, 'scanned.module_count')),
                    'symbol_count' => data_get($summary, 'symbol_count', data_get($summary, 'scanned.symbol_count')),
                    'route_count' => data_get($summary, 'route_count', data_get($summary, 'scanned.route_count')),
                    'command_count' => data_get($summary, 'command_count', data_get($summary, 'scanned.command_count')),
                    'migration_count' => data_get($summary, 'migration_count', data_get($summary, 'scanned.migration_count')),
                    'test_count' => data_get($summary, 'test_count', data_get($summary, 'scanned.test_count')),
                    'doc_link_count' => data_get($summary, 'doc_link_count', data_get($summary, 'scanned.doc_link_count')),
                    'drift_total' => data_get($drift, 'total'),
                ],
                'findings' => $operation === 'audit' ? $this->toolRuntimeAuditFindings($payload) : [],
                'recommendations' => $operation === 'audit' && ($payload['status'] ?? null) !== 'fresh'
                    ? ['Run code intelligence indexing with --prune after reviewing drift, then update canonical docs if module ownership changed.']
                    : [],
            ], [
                'surface' => 'engineering_code_intelligence',
                'source' => 'engineering_code_intelligence_service',
                'run_context_type' => $context['run_context_type'] ?? null,
                'run_context_id' => $context['run_context_id'] ?? null,
                'metadata' => [
                    'operation' => $operation,
                    'dry_run' => (bool) ($payload['dry_run'] ?? false),
                    'writes' => (bool) ($payload['writes'] ?? ! (bool) ($payload['dry_run'] ?? false)),
                    'status' => $payload['status'] ?? null,
                    'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                    'generated_at' => $payload['generated_at'] ?? null,
                ],
            ]);
        } catch (\Throwable) {
            // Code intelligence must remain usable even when the generic runtime tables are absent.
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{run_context_type:?string,run_context_id:?string}
     */
    private function toolRuntimeContext(array $options): array
    {
        return [
            'run_context_type' => $this->nullableString($options['run_context_type'] ?? null),
            'run_context_id' => $this->nullableString($options['run_context_id'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,array<string,mixed>>
     */
    private function toolRuntimeAuditFindings(array $payload): array
    {
        $findings = [];

        foreach (['missing_in_index', 'removed_from_workspace', 'changed'] as $bucket) {
            foreach ((array) data_get($payload, 'drift.modules.'.$bucket, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $findings[] = [
                    'rule_id' => 'atlas_code_intelligence.module_'.$this->safeDriftType($item['drift_type'] ?? $bucket),
                    'title' => 'Code intelligence module drift: '.(string) ($item['slug'] ?? 'unknown'),
                    'message' => (string) ($item['drift_type'] ?? $bucket),
                    'severity' => 'medium',
                    'file_path' => $item['root_path'] ?? null,
                    'blocks_resolved' => false,
                    'metadata' => $item,
                ];
            }
        }

        foreach (['added', 'removed'] as $bucket) {
            foreach ((array) data_get($payload, 'drift.symbols.'.$bucket, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $findings[] = [
                    'rule_id' => 'atlas_code_intelligence.symbol_'.$this->safeDriftType($item['drift_type'] ?? $bucket),
                    'title' => 'Code intelligence symbol drift: '.(string) ($item['symbol_name'] ?? 'unknown'),
                    'message' => (string) ($item['drift_type'] ?? $bucket),
                    'severity' => 'low',
                    'file_path' => $item['file_path'] ?? null,
                    'line' => $item['line_start'] ?? null,
                    'blocks_resolved' => false,
                    'metadata' => $item,
                ];
            }
        }

        foreach (['missing_targets', 'stale_target_hashes'] as $bucket) {
            foreach ((array) data_get($payload, 'drift.doc_links.'.$bucket, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $findings[] = [
                    'rule_id' => 'atlas_code_intelligence.doc_link_'.$this->safeDriftType($item['status'] ?? $bucket),
                    'title' => 'Code intelligence doc link drift',
                    'message' => (string) ($item['status'] ?? $bucket),
                    'severity' => 'medium',
                    'file_path' => $item['canonical_path'] ?? null,
                    'blocks_resolved' => false,
                    'metadata' => $item,
                ];
            }
        }

        return array_slice($findings, 0, 100);
    }

    private function safeDriftType(mixed $value): string
    {
        return Str::slug((string) $value, '_') ?: 'drift';
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @return array{modules:array<int,array<string,mixed>>,symbols:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    private function scanWorkspace(string $workspace): array
    {
        $files = $this->discoverFiles($workspace);
        $modules = [];
        $symbols = [];
        $fileHashes = [];

        foreach ($files as $path) {
            $relativePath = $this->relativePath($path, $workspace);
            $module = $this->moduleForPath($relativePath);
            $modules[$module['slug']] ??= $this->emptyModule($module);
            $content = File::get($path);
            $fileHash = hash('sha256', $content);
            $fileHashes[$relativePath] = $fileHash;
            $language = $this->languageForPath($relativePath);

            $modules[$module['slug']]['files'][$relativePath] = [
                'path' => $relativePath,
                'hash' => $fileHash,
                'language' => $language,
            ];
            $modules[$module['slug']]['languages'][$language] = ($modules[$module['slug']]['languages'][$language] ?? 0) + 1;

            $symbols[] = $this->symbol([
                'module_slug' => $module['slug'],
                'symbol_type' => 'file',
                'symbol_name' => $relativePath,
                'file_path' => $relativePath,
                'line_start' => 1,
                'language' => $language,
                'signature' => $relativePath,
                'metadata' => [
                    'file_hash' => $fileHash,
                    'extension' => pathinfo($relativePath, PATHINFO_EXTENSION),
                ],
            ]);

            foreach ($this->parseFileSymbols($relativePath, $content, $module['slug']) as $symbol) {
                $symbols[] = $symbol;
            }
        }

        foreach ($symbols as $symbol) {
            $moduleSlug = (string) ($symbol['module_slug'] ?? '');
            if (isset($modules[$moduleSlug]) && $symbol['symbol_type'] !== 'file') {
                $modules[$moduleSlug]['symbol_count']++;
                match ($symbol['symbol_type']) {
                    'route', 'api_resource' => $modules[$moduleSlug]['route_count']++,
                    'cli_command' => $modules[$moduleSlug]['command_count']++,
                    'migration_table' => $modules[$moduleSlug]['migration_count']++,
                    'test_method' => $modules[$moduleSlug]['test_count']++,
                    default => null,
                };
            }
        }

        $testPaths = collect($symbols)
            ->where('symbol_type', 'test_method')
            ->pluck('file_path')
            ->unique()
            ->values()
            ->all();

        $moduleRows = collect($modules)
            ->map(fn (array $module): array => $this->finalizeModule($module, $fileHashes, $testPaths))
            ->values()
            ->all();
        $symbolRows = collect($symbols)
            ->map(fn (array $symbol): array => $this->finalizeSymbol($symbol))
            ->values()
            ->all();

        return [
            'modules' => $moduleRows,
            'symbols' => $symbolRows,
            'summary' => $this->scanSummary($moduleRows, $symbolRows, 0),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @return array<string,mixed>
     */
    private function moduleDrift(array $moduleRows, int $limit): array
    {
        $scanned = collect($moduleRows)->keyBy('slug');
        $persisted = AtlasEngineeringCodeModule::query()
            ->active()
            ->get([
                'slug',
                'name',
                'layer',
                'root_path',
                'docs_status',
                'file_count',
                'symbol_count',
                'route_count',
                'command_count',
                'migration_count',
                'test_count',
                'source_hash',
                'indexed_at',
            ])
            ->keyBy('slug');
        $missingKeys = $scanned->keys()->diff($persisted->keys())->values();
        $removedKeys = $persisted->keys()->diff($scanned->keys())->values();
        $changed = $scanned
            ->keys()
            ->intersect($persisted->keys())
            ->map(fn (string $slug): ?array => $this->changedModulePayload($scanned->get($slug), $persisted->get($slug)))
            ->filter()
            ->values();

        return [
            'counts' => [
                'missing_in_index' => $missingKeys->count(),
                'removed_from_workspace' => $removedKeys->count(),
                'changed' => $changed->count(),
            ],
            'missing_in_index' => $missingKeys
                ->take($limit)
                ->map(fn (string $slug): array => $this->moduleAuditPayload($scanned->get($slug), 'missing_in_index'))
                ->values()
                ->all(),
            'removed_from_workspace' => $removedKeys
                ->take($limit)
                ->map(fn (string $slug): array => $this->persistedModuleAuditPayload($persisted->get($slug), 'removed_from_workspace'))
                ->values()
                ->all(),
            'changed' => $changed->take($limit)->values()->all(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @return array<string,mixed>
     */
    private function symbolDrift(array $symbolRows, int $limit): array
    {
        $scanned = collect($symbolRows)->keyBy(fn (array $symbol): string => $this->symbolSourceKey($symbol));
        $persisted = AtlasEngineeringCodeSymbol::query()
            ->with('module:id,slug')
            ->active()
            ->get([
                'id',
                'module_id',
                'symbol_type',
                'symbol_name',
                'file_path',
                'line_start',
                'language',
                'source_hash',
                'indexed_at',
            ])
            ->keyBy(fn (AtlasEngineeringCodeSymbol $symbol): string => $this->symbolSourceKey([
                'symbol_type' => $symbol->symbol_type,
                'source_hash' => $symbol->source_hash,
            ]));
        $addedKeys = $scanned->keys()->diff($persisted->keys())->values();
        $removedKeys = $persisted->keys()->diff($scanned->keys())->values();

        return [
            'counts' => [
                'added' => $addedKeys->count(),
                'removed' => $removedKeys->count(),
            ],
            'by_type' => [
                'added' => $this->symbolDriftTypeCounts($addedKeys, $scanned),
                'removed' => $this->symbolDriftTypeCounts($removedKeys, $persisted),
            ],
            'added' => $addedKeys
                ->take($limit)
                ->map(fn (string $key): array => $this->symbolAuditPayload($scanned->get($key), 'added'))
                ->values()
                ->all(),
            'removed' => $removedKeys
                ->take($limit)
                ->map(fn (string $key): array => $this->persistedSymbolAuditPayload($persisted->get($key), 'removed'))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docLinkHealth(string $workspace, int $limit): array
    {
        $links = AtlasEngineeringDocLink::query()
            ->whereNull('archived_at')
            ->get(['id', 'status', 'canonical_path', 'target_path', 'target_hash', 'link_type', 'indexed_at']);
        $missingTargets = [];
        $staleHashes = [];

        foreach ($links as $link) {
            $targetPath = is_string($link->target_path) && trim($link->target_path) !== ''
                ? trim($link->target_path)
                : null;
            if ($targetPath === null) {
                continue;
            }

            $fullPath = $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $targetPath);
            if (! File::exists($fullPath)) {
                if ($link->status !== 'missing_target') {
                    $missingTargets[] = $this->docLinkAuditPayload($link, 'missing_target_detected');
                }

                continue;
            }

            if (File::isFile($fullPath) && $link->target_hash && hash_file('sha256', $fullPath) !== $link->target_hash) {
                $staleHashes[] = $this->docLinkAuditPayload($link, 'stale_target_hash');
            }
        }

        return [
            'counts' => [
                'current' => $links->where('status', 'current')->count(),
                'persisted_missing_target_status' => $links->where('status', 'missing_target')->count(),
                'missing_targets' => count($missingTargets),
                'stale_target_hashes' => count($staleHashes),
            ],
            'missing_targets' => array_slice($missingTargets, 0, $limit),
            'stale_target_hashes' => array_slice($staleHashes, 0, $limit),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $scanned
     * @return array<string,mixed>|null
     */
    private function changedModulePayload(?array $scanned, ?AtlasEngineeringCodeModule $persisted): ?array
    {
        if ($scanned === null || $persisted === null) {
            return null;
        }

        $fields = ['source_hash', 'file_count', 'symbol_count', 'route_count', 'command_count', 'migration_count', 'test_count'];
        $differences = [];
        foreach ($fields as $field) {
            $scannedValue = $scanned[$field] ?? null;
            $persistedValue = $persisted->{$field};
            if ((string) $scannedValue !== (string) $persistedValue) {
                $differences[$field] = [
                    'scanned' => $scannedValue,
                    'persisted' => $persistedValue,
                ];
            }
        }

        if ($differences === []) {
            return null;
        }

        return array_merge($this->moduleAuditPayload($scanned, 'changed'), [
            'persisted' => [
                'source_hash' => $persisted->source_hash,
                'file_count' => $persisted->file_count,
                'symbol_count' => $persisted->symbol_count,
                'route_count' => $persisted->route_count,
                'command_count' => $persisted->command_count,
                'migration_count' => $persisted->migration_count,
                'test_count' => $persisted->test_count,
                'indexed_at' => $persisted->indexed_at?->toJSON(),
            ],
            'differences' => $differences,
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $module
     * @return array<string,mixed>
     */
    private function moduleAuditPayload(?array $module, string $reason): array
    {
        return [
            'reason' => $reason,
            'slug' => (string) ($module['slug'] ?? ''),
            'name' => (string) ($module['name'] ?? ''),
            'layer' => (string) ($module['layer'] ?? ''),
            'root_path' => $module['root_path'] ?? null,
            'source_hash' => (string) ($module['source_hash'] ?? ''),
            'file_count' => (int) ($module['file_count'] ?? 0),
            'symbol_count' => (int) ($module['symbol_count'] ?? 0),
            'route_count' => (int) ($module['route_count'] ?? 0),
            'command_count' => (int) ($module['command_count'] ?? 0),
            'migration_count' => (int) ($module['migration_count'] ?? 0),
            'test_count' => (int) ($module['test_count'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistedModuleAuditPayload(?AtlasEngineeringCodeModule $module, string $reason): array
    {
        return [
            'reason' => $reason,
            'slug' => (string) ($module?->slug ?? ''),
            'name' => (string) ($module?->name ?? ''),
            'layer' => (string) ($module?->layer ?? ''),
            'root_path' => $module?->root_path,
            'docs_status' => (string) ($module?->docs_status ?? ''),
            'source_hash' => (string) ($module?->source_hash ?? ''),
            'file_count' => (int) ($module?->file_count ?? 0),
            'symbol_count' => (int) ($module?->symbol_count ?? 0),
            'route_count' => (int) ($module?->route_count ?? 0),
            'command_count' => (int) ($module?->command_count ?? 0),
            'migration_count' => (int) ($module?->migration_count ?? 0),
            'test_count' => (int) ($module?->test_count ?? 0),
            'indexed_at' => $module?->indexed_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $symbol
     */
    private function symbolSourceKey(array $symbol): string
    {
        return (string) ($symbol['symbol_type'] ?? '').'|'.(string) ($symbol['source_hash'] ?? '');
    }

    /**
     * @param  Collection<int,string>  $keys
     * @param  Collection<string,mixed>  $symbols
     * @return array<string,int>
     */
    private function symbolDriftTypeCounts(Collection $keys, Collection $symbols): array
    {
        return $keys
            ->map(fn (string $key): string => (string) data_get($symbols->get($key), 'symbol_type', 'unknown'))
            ->countBy()
            ->sortKeys()
            ->map(fn (int $count): int => $count)
            ->all();
    }

    /**
     * @param  array<string,mixed>|null  $symbol
     * @return array<string,mixed>
     */
    private function symbolAuditPayload(?array $symbol, string $reason): array
    {
        return [
            'reason' => $reason,
            'module_slug' => $symbol['module_slug'] ?? null,
            'symbol_type' => (string) ($symbol['symbol_type'] ?? ''),
            'symbol_name' => (string) ($symbol['symbol_name'] ?? ''),
            'file_path' => (string) ($symbol['file_path'] ?? ''),
            'line_start' => $symbol['line_start'] ?? null,
            'language' => $symbol['language'] ?? null,
            'source_hash' => (string) ($symbol['source_hash'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistedSymbolAuditPayload(?AtlasEngineeringCodeSymbol $symbol, string $reason): array
    {
        return [
            'reason' => $reason,
            'module_slug' => $symbol?->module?->slug,
            'symbol_type' => (string) ($symbol?->symbol_type ?? ''),
            'symbol_name' => (string) ($symbol?->symbol_name ?? ''),
            'file_path' => (string) ($symbol?->file_path ?? ''),
            'line_start' => $symbol?->line_start,
            'language' => $symbol?->language,
            'source_hash' => (string) ($symbol?->source_hash ?? ''),
            'indexed_at' => $symbol?->indexed_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docLinkAuditPayload(AtlasEngineeringDocLink $link, string $reason): array
    {
        return [
            'reason' => $reason,
            'id' => $link->id,
            'status' => $link->status,
            'link_type' => $link->link_type,
            'canonical_path' => $link->canonical_path,
            'target_path' => $link->target_path,
            'target_hash' => $link->target_hash,
            'indexed_at' => $link->indexed_at?->toJSON(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function discoverFiles(string $workspace): array
    {
        $roots = [
            'app',
            'routes',
            'database/migrations',
            'tests',
            'docs/engineering-knowledge-base',
            'config',
            'lib',
            'components',
            'scripts',
        ];

        return collect($roots)
            ->map(fn (string $root): string => $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $root))
            ->filter(fn (string $root): bool => File::isDirectory($root))
            ->flatMap(fn (string $root): array => File::allFiles($root))
            ->filter(fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), self::EXTENSIONS, true))
            ->reject(fn (SplFileInfo $file): bool => str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
                || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR))
            ->map(fn (SplFileInfo $file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseFileSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => $this->parsePhpSymbols($relativePath, $content, $moduleSlug),
            'ts', 'tsx', 'js', 'jsx' => $this->parseJavascriptSymbols($relativePath, $content, $moduleSlug),
            'md' => $this->parseMarkdownSymbols($relativePath, $content, $moduleSlug),
            default => [],
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parsePhpSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        $namespace = preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)
            ? trim($namespaceMatch[1])
            : null;
        $className = null;
        if (preg_match('/\b(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $content, $classMatch, PREG_OFFSET_CAPTURE)) {
            $className = $namespace ? $namespace.'\\'.$classMatch[2][0] : $classMatch[2][0];
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => $classMatch[1][0],
                'symbol_name' => $className,
                'file_path' => $relativePath,
                'line_start' => $this->lineForOffset($content, $classMatch[0][1]),
                'language' => 'php',
                'signature' => trim($classMatch[0][0]),
                'namespace' => $namespace,
                'metadata' => [
                    'short_name' => $classMatch[2][0],
                    'classification' => $this->classClassification($relativePath, $classMatch[2][0]),
                ],
            ]);
        }

        if (preg_match('/protected\s+\$signature\s*=\s*([\'"])(.*?)\1/s', $content, $signatureMatch, PREG_OFFSET_CAPTURE)) {
            $signature = trim(preg_replace('/\s+/', ' ', $signatureMatch[2][0]) ?? $signatureMatch[2][0]);
            $command = strtok($signature, ' ') ?: $signature;
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'cli_command',
                'symbol_name' => $command,
                'file_path' => $relativePath,
                'line_start' => $this->lineForOffset($content, $signatureMatch[0][1]),
                'language' => 'php',
                'signature' => $signature,
                'parent_symbol' => $className,
                'metadata' => [
                    'command_signature' => $signature,
                ],
            ]);
        }

        foreach ($this->lineMatches($content, '/\b(public|protected|private)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)$/') as $match) {
            $methodName = $className ? $className.'::'.$match['matches'][2] : $match['matches'][2];
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => str_starts_with($relativePath, 'tests/') && str_starts_with($match['matches'][2], 'test_') ? 'test_method' : 'method',
                'symbol_name' => $methodName,
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'php',
                'signature' => trim($match['text']),
                'namespace' => $namespace,
                'parent_symbol' => $className,
                'visibility' => $match['matches'][1],
                'metadata' => [
                    'method' => $match['matches'][2],
                ],
            ]);
        }

        foreach ($this->parseRouteSymbols($relativePath, $content, $moduleSlug) as $routeSymbol) {
            $symbols[] = $routeSymbol;
        }

        foreach ($this->lineMatches($content, '/Schema::(create|table)\(\s*[\'"]([^\'"]+)[\'"]/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'migration_table',
                'symbol_name' => $match['matches'][1].':'.$match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'php',
                'signature' => trim($match['text']),
                'metadata' => [
                    'operation' => $match['matches'][1],
                    'table' => $match['matches'][2],
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseRouteSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        if (! str_starts_with($relativePath, 'routes/')) {
            return [];
        }

        $symbols = [];
        $prefixStack = [];
        $depth = 0;
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $prefixStack = array_values(array_filter(
                $prefixStack,
                fn (array $prefix): bool => $depth >= (int) $prefix['depth'],
            ));
            if (preg_match('/Route::prefix\(\s*[\'"]([^\'"]+)[\'"]\s*\)->group/', $line, $prefixMatch)) {
                $prefixStack[] = [
                    'prefix' => trim($prefixMatch[1], '/'),
                    'depth' => $depth + max(1, substr_count($line, '{')),
                ];
            }

            $prefix = trim(implode('/', array_column($prefixStack, 'prefix')), '/');
            if (preg_match('/Route::(get|post|put|patch|delete|options|any)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(.+)\);?/', $line, $routeMatch)) {
                $uri = $this->joinRoute($prefix, $routeMatch[2]);
                $verb = strtoupper($routeMatch[1]);
                $target = trim($routeMatch[3]);
                $symbols[] = $this->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => 'route',
                    'symbol_name' => $verb.' '.$uri,
                    'file_path' => $relativePath,
                    'line_start' => $lineNumber,
                    'language' => 'php',
                    'signature' => trim($line),
                    'metadata' => array_merge([
                        'http_method' => $verb,
                        'uri' => $uri,
                        'target' => $target,
                    ], $this->routeTarget($target)),
                ]);
            }
            if (preg_match('/Route::apiResource\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^,\)]+)/', $line, $resourceMatch)) {
                $uri = $this->joinRoute($prefix, $resourceMatch[1]);
                $symbols[] = $this->symbol([
                    'module_slug' => $moduleSlug,
                    'symbol_type' => 'api_resource',
                    'symbol_name' => 'RESOURCE '.$uri,
                    'file_path' => $relativePath,
                    'line_start' => $lineNumber,
                    'language' => 'php',
                    'signature' => trim($line),
                    'metadata' => [
                        'uri' => $uri,
                        'controller' => trim($resourceMatch[2]),
                    ],
                ]);
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseJavascriptSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        foreach ($this->lineMatches($content, '/\b(export\s+)?(default\s+)?(async\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'function',
                'symbol_name' => $match['matches'][4],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => $this->languageForPath($relativePath),
                'signature' => trim($match['text']),
                'metadata' => [
                    'exported' => trim((string) $match['matches'][1]) !== '',
                    'default' => trim((string) $match['matches'][2]) !== '',
                ],
            ]);
        }
        foreach ($this->lineMatches($content, '/\bexport\s+(interface|type|class|const)\s+([A-Za-z_][A-Za-z0-9_]*)/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => $match['matches'][1],
                'symbol_name' => $match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => $this->languageForPath($relativePath),
                'signature' => trim($match['text']),
                'metadata' => [
                    'exported' => true,
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseMarkdownSymbols(string $relativePath, string $content, string $moduleSlug): array
    {
        $symbols = [];
        foreach ($this->lineMatches($content, '/^(#{1,3})\s+(.+)$/') as $match) {
            $symbols[] = $this->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => 'doc_heading',
                'symbol_name' => $match['matches'][2],
                'file_path' => $relativePath,
                'line_start' => $match['line'],
                'language' => 'markdown',
                'signature' => trim($match['text']),
                'metadata' => [
                    'level' => strlen($match['matches'][1]),
                ],
            ]);
        }

        return $symbols;
    }

    /**
     * @param  array<string,mixed>  $module
     * @return array<string,mixed>
     */
    private function emptyModule(array $module): array
    {
        return array_merge($module, [
            'files' => [],
            'languages' => [],
            'symbol_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
        ]);
    }

    /**
     * @param  array<string,mixed>  $module
     * @param  array<string,string>  $fileHashes
     * @param  array<int,string>  $testPaths
     * @return array<string,mixed>
     */
    private function finalizeModule(array $module, array $fileHashes, array $testPaths): array
    {
        $files = array_values($module['files']);
        $hashParts = collect($files)
            ->map(fn (array $file): string => $file['path'].':'.($fileHashes[$file['path']] ?? $file['hash']))
            ->sort()
            ->values()
            ->all();
        $primaryLanguage = collect($module['languages'])->sortDesc()->keys()->first();
        $relatedTests = $this->relatedTestsForModule((string) $module['slug'], $testPaths);

        return [
            'slug' => $module['slug'],
            'name' => $module['name'],
            'layer' => $module['layer'],
            'root_path' => $module['root_path'],
            'primary_language' => $primaryLanguage,
            'status' => 'active',
            'owner' => $module['owner'] ?? 'atlas',
            'description' => $module['description'] ?? null,
            'docs_status' => 'undocumented',
            'file_count' => count($files),
            'symbol_count' => (int) $module['symbol_count'],
            'route_count' => (int) $module['route_count'],
            'command_count' => (int) $module['command_count'],
            'migration_count' => (int) $module['migration_count'],
            'test_count' => max((int) $module['test_count'], count($relatedTests)),
            'source_hash' => hash('sha256', implode('|', $hashParts)),
            'docs_hash' => null,
            'tags_json' => $module['tags'] ?? [],
            'related_docs_json' => [],
            'related_tests_json' => $relatedTests,
            'metadata' => [
                'files' => array_slice(array_column($files, 'path'), 0, 160),
                'language_counts' => $module['languages'],
            ],
            'indexed_at' => now(),
            'archived_at' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $symbol
     * @return array<string,mixed>
     */
    private function finalizeSymbol(array $symbol): array
    {
        $source = [
            $symbol['symbol_type'] ?? '',
            $symbol['symbol_name'] ?? '',
            $symbol['file_path'] ?? '',
            $symbol['line_start'] ?? '',
            $symbol['signature'] ?? '',
        ];
        $metadata = is_array($symbol['metadata'] ?? null) ? $symbol['metadata'] : [];
        $symbol = $this->fitSymbolStorageLimits($symbol, $metadata);

        return array_merge($symbol, [
            'status' => 'active',
            'docs_status' => 'undocumented',
            'source_hash' => hash('sha256', implode('|', array_map('strval', $source))),
            'related_doc_ids_json' => [],
            'indexed_at' => now(),
            'archived_at' => null,
        ]);
    }

    /**
     * Keep source_hash based on the full extracted symbol, but persist only
     * values that fit the schema. Full values are retained in metadata so
     * generated long test names remain inspectable without breaking indexing.
     *
     * @param  array<string,mixed>  $symbol
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function fitSymbolStorageLimits(array $symbol, array $metadata): array
    {
        foreach ([
            'symbol_type' => 60,
            'symbol_name' => 300,
            'file_path' => 500,
            'language' => 40,
            'namespace' => 220,
            'parent_symbol' => 300,
            'visibility' => 40,
        ] as $field => $limit) {
            $value = $symbol[$field] ?? null;
            if (! is_string($value) || strlen($value) <= $limit) {
                continue;
            }

            $metadata['full_'.$field] = $value;
            $symbol[$field] = substr($value, 0, $limit);
        }

        $symbol['metadata'] = $metadata;

        return $symbol;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function symbol(array $attributes): array
    {
        return array_merge([
            'module_slug' => null,
            'symbol_type' => 'symbol',
            'symbol_name' => null,
            'file_path' => null,
            'line_start' => null,
            'line_end' => null,
            'language' => null,
            'signature' => null,
            'namespace' => null,
            'parent_symbol' => null,
            'visibility' => null,
            'metadata' => [],
        ], $attributes);
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @return array<string,string>
     */
    private function persistModules(array $moduleRows, bool $prune): array
    {
        $ids = [];
        $seen = [];
        foreach ($moduleRows as $row) {
            $seen[] = $row['slug'];
            $module = AtlasEngineeringCodeModule::query()->updateOrCreate(
                ['slug' => $row['slug']],
                $row,
            );
            $ids[$row['slug']] = $module->id;
        }

        if ($prune && $seen !== []) {
            AtlasEngineeringCodeModule::query()
                ->whereNotIn('slug', $seen)
                ->where('status', '!=', 'archived')
                ->update(['status' => 'archived', 'archived_at' => now()]);
        }

        return $ids;
    }

    /**
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @param  array<string,string>  $moduleIds
     * @return int
     */
    private function persistSymbols(array $symbolRows, array $moduleIds, bool $prune): int
    {
        $count = 0;
        $indexedAt = now()->startOfSecond();
        $now = now();
        $rows = [];
        foreach ($symbolRows as $row) {
            $moduleSlug = (string) ($row['module_slug'] ?? '');
            unset($row['module_slug']);
            $row['module_id'] = $moduleIds[$moduleSlug] ?? null;
            $row['status'] = 'active';
            $row['archived_at'] = null;
            $row['indexed_at'] = $indexedAt;
            $row['id'] = (string) Str::uuid();
            $row['metadata'] = $this->json((array) ($row['metadata'] ?? []));
            $row['related_doc_ids_json'] = $this->json((array) ($row['related_doc_ids_json'] ?? []));
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
            $count++;

            if (count($rows) >= 1000) {
                $this->upsertSymbolRows($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsertSymbolRows($rows);
        }

        if ($prune && $symbolRows !== []) {
            $this->archiveStaleSymbols($indexedAt);
        }

        return $count;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function upsertSymbolRows(array $rows): void
    {
        DB::table('atlas_engineering_code_symbols')->upsert(
            $rows,
            ['symbol_type', 'source_hash'],
            [
                'module_id',
                'symbol_name',
                'file_path',
                'line_start',
                'line_end',
                'language',
                'signature',
                'namespace',
                'parent_symbol',
                'visibility',
                'status',
                'docs_status',
                'related_doc_ids_json',
                'metadata',
                'indexed_at',
                'archived_at',
                'updated_at',
            ],
        );
    }

    private function archiveStaleSymbols(Carbon $indexedAt): int
    {
        $archivedAt = now();
        $count = 0;

        AtlasEngineeringCodeSymbol::query()
            ->where('status', '!=', 'archived')
            ->where(function (Builder $query) use ($indexedAt): void {
                $query
                    ->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<', $indexedAt);
            })
            ->select('id')
            ->chunkById(1000, function (Collection $symbols) use ($archivedAt, &$count): void {
                $ids = $symbols->pluck('id')->all();
                if ($ids === []) {
                    return;
                }

                AtlasEngineeringCodeSymbol::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'status' => 'archived',
                        'archived_at' => $archivedAt,
                    ]);

                $count += count($ids);
            });

        return $count;
    }

    private function syncDocLinks(string $workspace, bool $prune): int
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
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
        $modules = AtlasEngineeringCodeModule::query()
            ->active()
            ->get(['id', 'slug', 'root_path']);
        $count = 0;
        $pruneStartedAt = now();

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
                    'metadata' => ['module_slug' => $module->slug, 'capability' => $matchedCapability],
                ]);
                AtlasEngineeringDocLink::query()->updateOrCreate(['link_hash' => $link['link_hash']], $link);
                $count++;
            }

            DB::table('atlas_engineering_code_symbols')
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->whereIn('file_path', $paths->all())
                ->select(['id', 'symbol_name', 'symbol_type', 'file_path'])
                ->orderBy('id')
                ->chunkById(200, function (Collection $symbols) use ($workspace, $item, &$count): void {
                    foreach ($symbols as $symbol) {
                        $link = $this->docLinkRow($workspace, $item, [
                            'symbol_id' => $symbol->id,
                            'target_path' => $symbol->file_path,
                            'link_type' => 'symbol_path',
                            'metadata' => ['symbol_name' => $symbol->symbol_name, 'symbol_type' => $symbol->symbol_type],
                        ]);
                        AtlasEngineeringDocLink::query()->updateOrCreate(['link_hash' => $link['link_hash']], $link);
                        $count++;
                    }
                });
        }

        if ($prune) {
            $this->archiveStaleDocLinks($pruneStartedAt);
        }

        return $count;
    }

    private function archiveStaleDocLinks(Carbon $pruneStartedAt): void
    {
        $archivedAt = now();

        DB::table('atlas_engineering_doc_links')
            ->whereNull('archived_at')
            ->where(function ($query) use ($pruneStartedAt): void {
                $query
                    ->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<', $pruneStartedAt);
            })
            ->update(['status' => 'archived', 'archived_at' => $archivedAt]);
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    private function docLinkRow(string $workspace, AtlasEngineeringKnowledgeItem $item, array $target): array
    {
        $targetPath = is_string($target['target_path'] ?? null) ? $target['target_path'] : null;
        $targetFullPath = $targetPath ? $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $targetPath) : null;
        $targetExists = $targetFullPath && File::exists($targetFullPath);
        $targetHash = $targetFullPath && File::isFile($targetFullPath) ? hash_file('sha256', $targetFullPath) : null;
        $status = $targetPath === null || $targetExists ? 'current' : 'missing_target';
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

    private function refreshDocumentationStatus(): void
    {
        $moduleDocs = [];

        foreach (DB::table('atlas_engineering_doc_links')
            ->select(['module_id', 'canonical_path', 'doc_hash'])
            ->whereNotNull('module_id')
            ->whereNull('archived_at')
            ->where('status', 'current')
            ->orderBy('module_id')
            ->cursor() as $link) {
            $moduleId = (string) $link->module_id;

            $moduleDocs[$moduleId]['paths'][] = (string) $link->canonical_path;

            if (is_string($link->doc_hash) && $link->doc_hash !== '') {
                $moduleDocs[$moduleId]['hashes'][] = $link->doc_hash;
            }
        }

        foreach ($moduleDocs as $moduleId => $docs) {
            $paths = array_values(array_unique($docs['paths'] ?? []));
            $hashes = array_values(array_filter($docs['hashes'] ?? [], fn (string $hash): bool => $hash !== ''));
            sort($hashes);

            $moduleDocs[$moduleId] = [
                'paths' => $paths,
                'docs_hash' => $hashes === [] ? null : hash('sha256', implode('|', $hashes)),
            ];
        }

        $documentedModuleIds = [];
        $now = now();

        DB::table('atlas_engineering_code_modules')
            ->select(['id'])
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->chunkById(200, function ($modules) use ($moduleDocs, &$documentedModuleIds, $now): void {
                foreach ($modules as $module) {
                    $moduleId = (string) $module->id;
                    $docs = $moduleDocs[$moduleId] ?? ['paths' => [], 'docs_hash' => null];
                    $relatedDocs = $docs['paths'];

                    if ($relatedDocs !== []) {
                        $documentedModuleIds[$moduleId] = true;
                    }

                    DB::table('atlas_engineering_code_modules')->where('id', $moduleId)->update([
                        'docs_status' => $relatedDocs === [] ? 'undocumented' : 'documented',
                        'related_docs_json' => $this->json($relatedDocs),
                        'docs_hash' => $docs['docs_hash'],
                        'updated_at' => $now,
                    ]);
                }
            });

        $this->refreshSymbolDocumentationStatus(array_keys($documentedModuleIds), $now);
    }

    /**
     * @param  array<int,string>  $documentedModuleIds
     */
    private function refreshSymbolDocumentationStatus(array $documentedModuleIds, Carbon $now): void
    {
        $baseQuery = DB::table('atlas_engineering_code_symbols')
            ->where('status', 'active')
            ->whereNull('archived_at');

        if ($documentedModuleIds !== []) {
            (clone $baseQuery)
                ->whereIn('module_id', $documentedModuleIds)
                ->update([
                    'docs_status' => 'module_documented',
                    'related_doc_ids_json' => $this->json([]),
                    'updated_at' => $now,
                ]);

            (clone $baseQuery)
                ->where(function ($query) use ($documentedModuleIds): void {
                    $query
                        ->whereNull('module_id')
                        ->orWhereNotIn('module_id', $documentedModuleIds);
                })
                ->update([
                    'docs_status' => 'undocumented',
                    'related_doc_ids_json' => $this->json([]),
                    'updated_at' => $now,
                ]);
        } else {
            (clone $baseQuery)->update([
                'docs_status' => 'undocumented',
                'related_doc_ids_json' => $this->json([]),
                'updated_at' => $now,
            ]);
        }

        $rows = [];
        $currentSymbolId = null;
        $currentDocIds = [];

        foreach (DB::table('atlas_engineering_doc_links')
            ->select(['symbol_id', 'knowledge_item_id'])
            ->whereNotNull('symbol_id')
            ->whereNull('archived_at')
            ->where('status', 'current')
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
    private function symbolDocumentationUpdateRow(string $symbolId, array $docIds, Carbon $now): array
    {
        return [
            'id' => $symbolId,
            'docs_status' => 'documented',
            'related_doc_ids_json' => $this->json(array_keys($docIds)),
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function upsertSymbolDocumentationRows(array $rows): void
    {
        collect($rows)
            ->groupBy(fn (array $row): string => (string) $row['related_doc_ids_json'])
            ->each(function (Collection $group): void {
                DB::table('atlas_engineering_code_symbols')
                    ->whereIn('id', $group->pluck('id')->all())
                    ->update([
                        'docs_status' => 'documented',
                        'related_doc_ids_json' => (string) $group->first()['related_doc_ids_json'],
                        'updated_at' => $group->first()['updated_at'],
                    ]);
            });
    }

    /**
     * @param  array<mixed>  $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @return array<string,mixed>
     */
    private function scanSummary(array $moduleRows, array $symbolRows, int $docLinkCount): array
    {
        $symbols = collect($symbolRows);

        return [
            'module_count' => count($moduleRows),
            'symbol_count' => count($symbolRows),
            'doc_link_count' => $docLinkCount,
            'route_count' => $symbols->whereIn('symbol_type', ['route', 'api_resource'])->count(),
            'command_count' => $symbols->where('symbol_type', 'cli_command')->count(),
            'migration_count' => $symbols->where('symbol_type', 'migration_table')->count(),
            'test_count' => $symbols->where('symbol_type', 'test_method')->count(),
            'file_count' => $symbols->where('symbol_type', 'file')->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleForPath(string $path): array
    {
        $rules = [
            ['prefix' => 'docs/engineering-knowledge-base', 'slug' => 'engineering_knowledge_docs', 'name' => 'Engineering Knowledge Docs', 'layer' => 'documentation', 'tags' => ['docs', 'knowledge']],
            ['prefix' => 'routes', 'slug' => 'api_routes', 'name' => 'API Routes', 'layer' => 'api', 'tags' => ['routes', 'api']],
            ['prefix' => 'app/Services/Engineering', 'slug' => 'engineering_harness_services', 'name' => 'Engineering Harness Services', 'layer' => 'service', 'tags' => ['engineering', 'harness']],
            ['prefix' => 'app/Console/Commands/AtlasEngineering', 'slug' => 'engineering_harness_cli', 'name' => 'Engineering Harness CLI', 'layer' => 'cli', 'tags' => ['engineering', 'cli']],
            ['prefix' => 'app/Http/Controllers/Engineering', 'slug' => 'engineering_harness_api', 'name' => 'Engineering Harness API', 'layer' => 'api', 'tags' => ['engineering', 'api']],
            ['prefix' => 'app/Models/AtlasEngineering', 'slug' => 'engineering_harness_models', 'name' => 'Engineering Harness Models', 'layer' => 'model', 'tags' => ['engineering', 'database']],
            ['contains' => 'atlas_engineering', 'prefix' => 'database/migrations', 'slug' => 'engineering_harness_schema', 'name' => 'Engineering Harness Schema', 'layer' => 'database', 'tags' => ['engineering', 'migrations']],
            ['prefix' => 'tests', 'contains' => 'Engineering', 'slug' => 'engineering_harness_tests', 'name' => 'Engineering Harness Tests', 'layer' => 'test', 'tags' => ['engineering', 'tests']],
            ['prefix' => 'app/Services/Ai', 'slug' => 'atlas_ai_services', 'name' => 'Atlas AI Services', 'layer' => 'service', 'tags' => ['ai']],
            ['prefix' => 'app/Console/Commands/AtlasCli', 'slug' => 'atlas_cli', 'name' => 'Atlas CLI', 'layer' => 'cli', 'tags' => ['cli']],
            ['prefix' => 'app/Console/Commands/AtlasMemory', 'slug' => 'atlas_memory_cli', 'name' => 'Atlas Memory CLI', 'layer' => 'cli', 'tags' => ['memory']],
            ['prefix' => 'app/Models/AtlasMemory', 'slug' => 'atlas_memory_models', 'name' => 'Atlas Memory Models', 'layer' => 'model', 'tags' => ['memory']],
            ['prefix' => 'app/Models/Ai', 'slug' => 'atlas_ai_models', 'name' => 'Atlas AI Models', 'layer' => 'model', 'tags' => ['ai']],
            ['prefix' => 'app/Http/Controllers/Mobile', 'slug' => 'mobile_gateway_api', 'name' => 'Mobile Gateway API', 'layer' => 'api', 'tags' => ['mobile']],
            ['prefix' => 'database/migrations', 'slug' => 'database_schema', 'name' => 'Database Schema', 'layer' => 'database', 'tags' => ['database']],
            ['prefix' => 'tests', 'slug' => 'test_suite', 'name' => 'Test Suite', 'layer' => 'test', 'tags' => ['tests']],
            ['prefix' => 'app/Console/Commands', 'slug' => 'console_commands', 'name' => 'Console Commands', 'layer' => 'cli', 'tags' => ['cli']],
            ['prefix' => 'app/Http/Controllers', 'slug' => 'http_controllers', 'name' => 'HTTP Controllers', 'layer' => 'api', 'tags' => ['api']],
            ['prefix' => 'app/Models', 'slug' => 'eloquent_models', 'name' => 'Eloquent Models', 'layer' => 'model', 'tags' => ['models']],
            ['prefix' => 'app/Services', 'slug' => 'application_services', 'name' => 'Application Services', 'layer' => 'service', 'tags' => ['services']],
        ];

        foreach ($rules as $rule) {
            if (isset($rule['prefix']) && ! str_starts_with($path, (string) $rule['prefix'])) {
                continue;
            }
            if (isset($rule['contains']) && ! str_contains($path, (string) $rule['contains'])) {
                continue;
            }

            return [
                'slug' => $rule['slug'],
                'name' => $rule['name'],
                'layer' => $rule['layer'],
                'root_path' => $rule['prefix'] ?? dirname($path),
                'description' => null,
                'tags' => $rule['tags'] ?? [],
            ];
        }

        $root = explode('/', $path)[0] ?? 'workspace';

        return [
            'slug' => Str::slug($root.'_misc', '_'),
            'name' => Str::of($root)->replace('_', ' ')->title().' Misc',
            'layer' => 'misc',
            'root_path' => $root,
            'description' => null,
            'tags' => [$root],
        ];
    }

    /**
     * @return array<int,array{line:int,text:string,matches:array<int,string>}>
     */
    private function lineMatches(string $content, string $pattern): array
    {
        $matches = [];
        foreach (preg_split('/\r?\n/', $content) ?: [] as $index => $line) {
            if (preg_match($pattern, $line, $match)) {
                $matches[] = [
                    'line' => $index + 1,
                    'text' => $line,
                    'matches' => $match,
                ];
            }
        }

        return $matches;
    }

    private function lineForOffset(string $content, int $offset): int
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    private function routeTarget(string $target): array
    {
        if (preg_match('/\[([A-Za-z0-9_\\\\]+)::class,\s*[\'"]([^\'"]+)[\'"]\]/', $target, $match)) {
            return [
                'controller' => $match[1],
                'action' => $match[2],
            ];
        }

        return [];
    }

    private function joinRoute(string $prefix, string $uri): string
    {
        $uri = trim($uri, '/');
        $path = trim($prefix.'/'.$uri, '/');

        return '/'.$path;
    }

    /**
     * @return array<int,string>
     */
    private function relatedTestsForModule(string $moduleSlug, array $testPaths): array
    {
        $needles = match (true) {
            str_contains($moduleSlug, 'engineering') => ['Engineering', 'AtlasEngineering'],
            str_contains($moduleSlug, 'atlas_ai') => ['Ai'],
            str_contains($moduleSlug, 'memory') => ['Memory'],
            str_contains($moduleSlug, 'cli') => ['AtlasCli'],
            default => [Str::studly($moduleSlug)],
        };

        return collect($testPaths)
            ->filter(fn (string $path): bool => collect($needles)->contains(fn (string $needle): bool => str_contains($path, $needle)))
            ->values()
            ->all();
    }

    private function pathMatchesModule(string $path, AtlasEngineeringCodeModule $module): bool
    {
        $root = trim((string) $module->root_path, '/');
        $path = trim($path, '/');

        return $root !== '' && ($path === $root || str_starts_with($path, $root.'/') || str_starts_with($root, $path.'/'));
    }

    private function languageForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'php' => 'php',
            'ts' => 'typescript',
            'tsx' => 'typescript_react',
            'js' => 'javascript',
            'jsx' => 'javascript_react',
            'md' => 'markdown',
            default => 'text',
        };
    }

    private function classClassification(string $path, string $shortName): string
    {
        return match (true) {
            str_ends_with($shortName, 'Command') => 'command',
            str_ends_with($shortName, 'Controller') => 'controller',
            str_ends_with($shortName, 'Service') => 'service',
            str_starts_with($path, 'app/Models/') => 'model',
            str_starts_with($path, 'tests/') => 'test',
            default => 'class',
        };
    }

    private function modulePayload(AtlasEngineeringCodeModule $module): array
    {
        return [
            'id' => $module->id,
            'slug' => $module->slug,
            'name' => $module->name,
            'layer' => $module->layer,
            'root_path' => $module->root_path,
            'primary_language' => $module->primary_language,
            'status' => $module->status,
            'owner' => $module->owner,
            'description' => $module->description,
            'docs_status' => $module->docs_status,
            'file_count' => $module->file_count,
            'symbol_count' => $module->symbol_count,
            'route_count' => $module->route_count,
            'command_count' => $module->command_count,
            'migration_count' => $module->migration_count,
            'test_count' => $module->test_count,
            'source_hash' => $module->source_hash,
            'docs_hash' => $module->docs_hash,
            'tags' => $module->tags_json ?? [],
            'related_docs' => $module->related_docs_json ?? [],
            'related_tests' => $module->related_tests_json ?? [],
            'metadata' => $module->metadata ?? [],
            'indexed_at' => $module->indexed_at?->toJSON(),
            'archived_at' => $module->archived_at?->toJSON(),
            'created_at' => $module->created_at?->toJSON(),
            'updated_at' => $module->updated_at?->toJSON(),
        ];
    }

    private function symbolPayload(AtlasEngineeringCodeSymbol $symbol): array
    {
        return [
            'id' => $symbol->id,
            'module_id' => $symbol->module_id,
            'module_slug' => $symbol->module?->slug,
            'symbol_type' => $symbol->symbol_type,
            'symbol_name' => $symbol->symbol_name,
            'file_path' => $symbol->file_path,
            'line_start' => $symbol->line_start,
            'line_end' => $symbol->line_end,
            'language' => $symbol->language,
            'signature' => $symbol->signature,
            'namespace' => $symbol->namespace,
            'parent_symbol' => $symbol->parent_symbol,
            'visibility' => $symbol->visibility,
            'status' => $symbol->status,
            'docs_status' => $symbol->docs_status,
            'source_hash' => $symbol->source_hash,
            'related_doc_ids' => $symbol->related_doc_ids_json ?? [],
            'metadata' => $symbol->metadata ?? [],
            'indexed_at' => $symbol->indexed_at?->toJSON(),
        ];
    }

    private function docLinkPayload(AtlasEngineeringDocLink $link): array
    {
        return [
            'id' => $link->id,
            'knowledge_item_id' => $link->knowledge_item_id,
            'module_id' => $link->module_id,
            'symbol_id' => $link->symbol_id,
            'link_type' => $link->link_type,
            'status' => $link->status,
            'canonical_path' => $link->canonical_path,
            'target_path' => $link->target_path,
            'doc_hash' => $link->doc_hash,
            'target_hash' => $link->target_hash,
            'metadata' => $link->metadata ?? [],
            'indexed_at' => $link->indexed_at?->toJSON(),
        ];
    }

    private function ensureTables(): void
    {
        if (! $this->tablesExist()) {
            throw new RuntimeException('Tabelas de code intelligence ainda nao existem. Rode migrations.');
        }
    }

    private function tablesExist(): bool
    {
        return Schema::hasTable('atlas_engineering_code_modules')
            && Schema::hasTable('atlas_engineering_code_symbols')
            && Schema::hasTable('atlas_engineering_doc_links');
    }

    private function workspace(mixed $workspace): string
    {
        $workspace = is_scalar($workspace) && trim((string) $workspace) !== '' ? trim((string) $workspace) : base_path();
        $real = realpath($workspace);

        return $real !== false ? $real : $workspace;
    }

    private function relativePath(string $path, string $workspace): string
    {
        $base = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function contextInput(): EngineeringContextIntelligenceInput
    {
        return $this->input ?? app(EngineeringContextIntelligenceInput::class);
    }
}
