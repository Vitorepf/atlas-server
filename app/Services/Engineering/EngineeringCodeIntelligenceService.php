<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use App\Models\AtlasEngineeringKnowledgeItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileInfo;

class EngineeringCodeIntelligenceService
{
    private const EXTENSIONS = ['php', 'ts', 'tsx', 'js', 'jsx', 'md'];

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function index(array $options = []): array
    {
        $this->ensureTables();

        $workspace = $this->workspace($options['workspace'] ?? base_path());
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $prune = (bool) ($options['prune'] ?? false);
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

        if ($dryRun) {
            return [
                'ok' => true,
                'dry_run' => true,
                'workspace' => $workspace,
                'summary' => $this->scanSummary($moduleRows, $symbolRows, 0),
                'modules' => $moduleRows,
                'symbols_preview' => array_slice($symbolRows, 0, 80),
                'generated_at' => now()->toJSON(),
            ];
        }

        $moduleIds = $this->persistModules($moduleRows, $prune);
        $symbolIds = $this->persistSymbols($symbolRows, $moduleIds, $prune);
        $docLinkCount = $this->syncDocLinks($workspace, $prune);
        $this->refreshDocumentationStatus();

        return [
            'ok' => true,
            'dry_run' => false,
            'workspace' => $workspace,
            'summary' => $this->scanSummary($moduleRows, $symbolRows, $docLinkCount),
            'modules' => $this->catalog([], 30)['modules'],
            'symbol_count' => count($symbolIds),
            'generated_at' => now()->toJSON(),
        ];
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
            ->limit($this->limit($limit))
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
                ->limit($this->limit($limit))
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
            ->limit($this->limit($limit))
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
     * @return array<string,string>
     */
    private function persistSymbols(array $symbolRows, array $moduleIds, bool $prune): array
    {
        $ids = [];
        $seenHashes = [];
        foreach ($symbolRows as $row) {
            $moduleSlug = (string) ($row['module_slug'] ?? '');
            unset($row['module_slug']);
            $row['module_id'] = $moduleIds[$moduleSlug] ?? null;
            $seenHashes[] = $row['source_hash'];
            $symbol = AtlasEngineeringCodeSymbol::query()->updateOrCreate(
                ['symbol_type' => $row['symbol_type'], 'source_hash' => $row['source_hash']],
                $row,
            );
            $ids[$row['source_hash']] = $symbol->id;
        }

        if ($prune && $seenHashes !== []) {
            AtlasEngineeringCodeSymbol::query()
                ->whereNotIn('source_hash', $seenHashes)
                ->where('status', '!=', 'archived')
                ->update(['status' => 'archived', 'archived_at' => now()]);
        }

        return $ids;
    }

    private function syncDocLinks(string $workspace, bool $prune): int
    {
        if (! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return 0;
        }

        $knowledgeItems = AtlasEngineeringKnowledgeItem::query()->active()->get();
        $modules = AtlasEngineeringCodeModule::query()->active()->get();
        $symbols = AtlasEngineeringCodeSymbol::query()->active()->get();
        $seen = [];
        $count = 0;

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
                $seen[] = $link['link_hash'];
                AtlasEngineeringDocLink::query()->updateOrCreate(['link_hash' => $link['link_hash']], $link);
                $count++;
            }

            foreach ($symbols as $symbol) {
                $matchedPath = $paths->first(fn (string $path): bool => $path === $symbol->file_path);
                if (! $matchedPath) {
                    continue;
                }

                $link = $this->docLinkRow($workspace, $item, [
                    'symbol_id' => $symbol->id,
                    'target_path' => $symbol->file_path,
                    'link_type' => 'symbol_path',
                    'metadata' => ['symbol_name' => $symbol->symbol_name, 'symbol_type' => $symbol->symbol_type],
                ]);
                $seen[] = $link['link_hash'];
                AtlasEngineeringDocLink::query()->updateOrCreate(['link_hash' => $link['link_hash']], $link);
                $count++;
            }
        }

        if ($prune && $seen !== []) {
            AtlasEngineeringDocLink::query()
                ->whereNotIn('link_hash', $seen)
                ->whereNull('archived_at')
                ->update(['status' => 'archived', 'archived_at' => now()]);
        }

        return $count;
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
        $moduleDocs = AtlasEngineeringDocLink::query()
            ->whereNotNull('module_id')
            ->whereNull('archived_at')
            ->where('status', 'current')
            ->get()
            ->groupBy('module_id');
        $symbolDocs = AtlasEngineeringDocLink::query()
            ->whereNotNull('symbol_id')
            ->whereNull('archived_at')
            ->where('status', 'current')
            ->get()
            ->groupBy('symbol_id');

        foreach (AtlasEngineeringCodeModule::query()->active()->get() as $module) {
            $links = $moduleDocs->get($module->id, collect());
            $relatedDocs = $links->pluck('canonical_path')->unique()->values()->all();
            $docsHash = $relatedDocs === [] ? null : hash('sha256', implode('|', $links->pluck('doc_hash')->sort()->all()));
            $module->forceFill([
                'docs_status' => $relatedDocs === [] ? 'undocumented' : 'documented',
                'related_docs_json' => $relatedDocs,
                'docs_hash' => $docsHash,
            ])->save();
        }

        foreach (AtlasEngineeringCodeSymbol::query()->active()->get() as $symbol) {
            $links = $symbolDocs->get($symbol->id, collect());
            $relatedDocIds = $links->pluck('knowledge_item_id')->unique()->values()->all();
            $moduleDocumented = $symbol->module_id
                ? AtlasEngineeringCodeModule::query()->whereKey($symbol->module_id)->where('docs_status', 'documented')->exists()
                : false;
            $symbol->forceFill([
                'docs_status' => $relatedDocIds !== [] ? 'documented' : ($moduleDocumented ? 'module_documented' : 'undocumented'),
                'related_doc_ids_json' => $relatedDocIds,
            ])->save();
        }
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

    private function limit(int $limit): int
    {
        return max(1, min(500, $limit));
    }
}
