<?php

namespace App\Services\Engineering\CodeIntelligence;

/**
 * GOD-DEBULK FASE C - the tree-sitter symbol mapping family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class TreeSitterSection
{
    public function __construct(
        private readonly DiscoverySection $discovery,
        private readonly ScanSummarySection $scanSummarySection,
        private readonly SymbolExtractor $symbolExtractor,
    ) {}


    /**
     * @return array<int,array<string,mixed>>
     */
    /**
     * AP-815 A1: tree-sitter symbol extraction is gated behind the runtime flag (same
     * master flag as the python boundary) plus an opt-out sub-flag.
     */
    public function treeSitterEnabled(): bool
    {
        return (bool) config('atlas.code_graph.real_edges', false)
            && (bool) config('atlas.code_graph.treesitter_symbols', true);
    }


    /**
     * AP-815 A1: grammar id for a non-PHP source file tree-sitter should extract, or
     * null for PHP (kept on nikic/php-parser), markdown (kept on its parser), and
     * unknown extensions (which keep the existing empty/regex behaviour).
     */
    public function treeSitterLanguage(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'ts', 'tsx' => 'typescript',
            'js', 'jsx', 'mjs', 'cjs' => 'javascript',
            'py' => 'python',
            'go' => 'go',
            'rs' => 'rust',
            'java' => 'java',
            'rb' => 'ruby',
            'kt', 'kts' => 'kotlin',
            'scala' => 'scala',
            'swift' => 'swift',
            'cpp', 'hpp' => 'cpp',
            'lua' => 'lua',
            default => null,
        };
    }


    /**
     * AP-815 A1: map tree-sitter nodes (from CodeGraphTreeSitterExtractor) into the
     * indexer's symbol-row shape.
     *
     * @param  array{symbols:array<int,array<string,mixed>>,imports:array<int,string>}  $data
     * @return array<int,array<string,mixed>>
     */
    public function mapTreeSitterSymbols(array $data, string $relativePath, string $moduleSlug): array
    {
        $language = $this->scanSummarySection->languageForPath($relativePath);
        $symbols = [];
        foreach (($data['symbols'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $name = trim((string) ($node['label'] ?? ''));
            if ($name === '') {
                continue;
            }
            $symbols[] = $this->symbolExtractor->symbol([
                'module_slug' => $moduleSlug,
                'symbol_type' => (string) ($node['kind'] ?? 'symbol'),
                'symbol_name' => $name,
                'file_path' => $relativePath,
                'line_start' => (int) ($node['line_start'] ?? $node['line'] ?? 1),
                'language' => $language,
                'signature' => $name,
                'metadata' => [
                    'line_end' => $node['line_end'] ?? null,
                    'source' => 'treesitter',
                    'grammar' => $node['language'] ?? null,
                ],
            ]);
        }

        return $symbols;
    }


    /**
     * @param  array<int,array<string,mixed>>  $symbols
     * @return array<int,array<string,mixed>>
     */
    public function withIndexedSymbolPaths(array $symbols, string $relativePath, string $analysisPath): array
    {
        if ($analysisPath === $relativePath) {
            return $symbols;
        }

        $prefix = $this->discovery->analysisPrefixForRelativePath($relativePath, $analysisPath);

        return collect($symbols)
            ->map(function (array $symbol) use ($relativePath, $analysisPath, $prefix): array {
                if (! array_key_exists('file_path', $symbol) || blank($symbol['file_path']) || $symbol['file_path'] === $analysisPath) {
                    $symbol['file_path'] = $relativePath;
                }

                $metadata = is_array($symbol['metadata'] ?? null) ? $symbol['metadata'] : [];
                $metadata['analysis_path'] = $analysisPath;
                if ($prefix !== null) {
                    $metadata['analysis_prefix'] = $prefix;
                }
                $symbol['metadata'] = $metadata;

                return $symbol;
            })
            ->values()
            ->all();
    }


    /**
     * @param  array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}  $relations
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    public function withIndexedRelationPaths(array $relations, string $relativePath, string $analysisPath): array
    {
        if ($analysisPath === $relativePath) {
            return $relations;
        }

        $rewriteRows = function (mixed $rows) use ($relativePath, $analysisPath): array {
            return collect(is_array($rows) ? $rows : [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(function (array $row) use ($relativePath, $analysisPath): array {
                    if (! array_key_exists('file_path', $row) || blank($row['file_path']) || $row['file_path'] === $analysisPath) {
                        $row['file_path'] = $relativePath;
                    }

                    if (array_key_exists('test_path', $row) && ($row['test_path'] === $analysisPath || blank($row['test_path']))) {
                        $row['test_path'] = $relativePath;
                    }

                    foreach (['to_module', 'target_module'] as $field) {
                        if (array_key_exists($field, $row)) {
                            $row[$field] = $this->discovery->qualifyTargetModuleForAnalysisPath($row[$field], $relativePath, $analysisPath);
                        }
                    }

                    return $row;
                })
                ->values()
                ->all();
        };

        return [
            'dependencies' => $rewriteRows($relations['dependencies'] ?? []),
            'symbol_references' => $rewriteRows($relations['symbol_references'] ?? []),
            'test_targets' => $rewriteRows($relations['test_targets'] ?? []),
        ];
    }
}
