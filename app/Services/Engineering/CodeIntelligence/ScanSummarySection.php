<?php

namespace App\Services\Engineering\CodeIntelligence;

use Illuminate\Support\Str;

/**
 * GOD-DEBULK FASE C - the scan-summary / module+symbol assembly family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class ScanSummarySection
{

    /**
     * @param  array<string,mixed>  $module
     * @return array<string,mixed>
     */
    public function emptyModule(array $module): array
    {
        return array_merge($module, [
            'files' => [],
            'languages' => [],
            'symbol_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'dependencies' => [],
            'symbol_references' => [],
            'test_targets' => [],
        ]);
    }


    /**
     * @param  array<string,mixed>  $module
     * @param  array<string,string>  $fileHashes
     * @param  array<int,string>  $testPaths
     * @return array<string,mixed>
     */
    public function finalizeModule(array $module, array $fileHashes, array $testPaths): array
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
                'dependency_edges' => $this->uniqueRelationRows((array) $module['dependencies'], 80),
                'symbol_references' => $this->uniqueRelationRows((array) $module['symbol_references'], 120),
                'test_targets' => $this->uniqueRelationRows((array) $module['test_targets'], 80),
                'code_intelligence_depth' => 'symbols_dependencies_tests_docs',
            ],
            'indexed_at' => now(),
            'archived_at' => null,
        ];
    }


    /**
     * @param  array<string,mixed>  $symbol
     * @return array<string,mixed>
     */
    public function finalizeSymbol(array $symbol): array
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
    public function fitSymbolStorageLimits(array $symbol, array $metadata): array
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
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @return array<string,mixed>
     */
    public function scanSummary(array $moduleRows, array $symbolRows, int $docLinkCount): array
    {
        $routeCount = 0;
        $commandCount = 0;
        $migrationCount = 0;
        $testCount = 0;
        $fileCount = 0;

        foreach ($symbolRows as $symbol) {
            $type = (string) ($symbol['symbol_type'] ?? '');
            match ($type) {
                'route', 'api_resource' => $routeCount++,
                'cli_command' => $commandCount++,
                'migration_table' => $migrationCount++,
                'test_method' => $testCount++,
                'file' => $fileCount++,
                default => null,
            };
        }

        return [
            'module_count' => count($moduleRows),
            'symbol_count' => count($symbolRows),
            'doc_link_count' => $docLinkCount,
            'route_count' => $routeCount,
            'command_count' => $commandCount,
            'migration_count' => $migrationCount,
            'test_count' => $testCount,
            'file_count' => $fileCount,
        ];
    }


    /**
     * @return array{symbol_count:int,route_count:int,command_count:int,migration_count:int,test_count:int,file_count:int}
     */
    public function emptyScanSummaryCounts(): array
    {
        return [
            'symbol_count' => 0,
            'route_count' => 0,
            'command_count' => 0,
            'migration_count' => 0,
            'test_count' => 0,
            'file_count' => 0,
        ];
    }


    /**
     * @param  array<string,array<string,mixed>>  $modules
     * @param  array{symbol_count:int,route_count:int,command_count:int,migration_count:int,test_count:int,file_count:int}  $summaryCounts
     * @param  array<string,true>  $testPathSet
     */
    public function countScannedSymbol(array &$modules, array $symbol, array &$summaryCounts, array &$testPathSet): void
    {
        $type = (string) ($symbol['symbol_type'] ?? '');
        $summaryCounts['symbol_count']++;

        match ($type) {
            'route', 'api_resource' => $summaryCounts['route_count']++,
            'cli_command' => $summaryCounts['command_count']++,
            'migration_table' => $summaryCounts['migration_count']++,
            'test_method' => $summaryCounts['test_count']++,
            'file' => $summaryCounts['file_count']++,
            default => null,
        };

        $moduleSlug = (string) ($symbol['module_slug'] ?? '');
        if (! isset($modules[$moduleSlug]) || $type === 'file') {
            return;
        }

        $modules[$moduleSlug]['symbol_count']++;
        match ($type) {
            'route', 'api_resource' => $modules[$moduleSlug]['route_count']++,
            'cli_command' => $modules[$moduleSlug]['command_count']++,
            'migration_table' => $modules[$moduleSlug]['migration_count']++,
            'test_method' => $modules[$moduleSlug]['test_count']++,
            default => null,
        };

        if ($type === 'test_method') {
            $filePath = (string) ($symbol['file_path'] ?? '');
            if ($filePath !== '') {
                $testPathSet[$filePath] = true;
            }
        }
    }


    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @param  array{symbol_count:int,route_count:int,command_count:int,migration_count:int,test_count:int,file_count:int}  $counts
     * @return array<string,mixed>
     */
    public function scanSummaryFromCounts(array $moduleRows, array $counts, int $docLinkCount): array
    {
        return [
            'module_count' => count($moduleRows),
            'symbol_count' => $counts['symbol_count'],
            'doc_link_count' => $docLinkCount,
            'route_count' => $counts['route_count'],
            'command_count' => $counts['command_count'],
            'migration_count' => $counts['migration_count'],
            'test_count' => $counts['test_count'],
            'file_count' => $counts['file_count'],
        ];
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    public function uniqueRelationRows(array $rows, int $limit): array
    {
        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->unique(fn (array $row): string => hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''))
            ->values()
            ->take($limit)
            ->all();
    }


    /**
     * @return array<int,string>
     */
    public function relatedTestsForModule(string $moduleSlug, array $testPaths): array
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


    public function languageForPath(string $path): string
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
}
