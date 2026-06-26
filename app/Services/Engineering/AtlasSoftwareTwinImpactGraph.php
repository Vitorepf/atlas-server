<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use Closure;

/**
 * IMPACT-GRAPH concern, extracted from the god-class
 * {@see AtlasSoftwareTwinRuntimeService}.
 *
 * Owns every impact-graph method: impactGraphRag (the top-level entry),
 * targetSymbols, impactModules, impactDocLinks, docLinkImpactRank,
 * impactSymbols, impactCausalPaths, impactGraphConfidence and
 * symbolImpactPayload.
 *
 * Capabilities that STAY in the service are passed in as Closures —
 * the SAME closure-binding pattern used by AtlasLoopRefillerSupplyLaneCoordinator.
 * Public constants are referenced via AtlasSoftwareTwinRuntimeService::CONST_NAME;
 * the private IMPACT_GRAPH_LIMITS constant is passed as a constructor arg.
 */
class AtlasSoftwareTwinImpactGraph
{
    /**
     * @param  array<string,int>  $impactGraphLimits
     * @param  Closure(array<mixed>): array  $pluckUnique
     * @param  Closure(array<array<string,mixed>>): array<int,string>  $mergedUniqueStrings
     * @param  Closure(): bool  $codeGraphTablesReady
     * @param  Closure(array<mixed>, string): array<int,string>  $moduleRelatedStrings
     * @param  Closure(string): string  $pathDirectory
     */
    public function __construct(
        private readonly array $impactGraphLimits,
        private readonly Closure $pluckUnique,
        private readonly Closure $mergedUniqueStrings,
        private readonly Closure $codeGraphTablesReady,
        private readonly Closure $moduleRelatedStrings,
        private readonly Closure $pathDirectory,
    ) {}

    public function impactGraphRag(string $target, ?string $targetPath, array $reachability, array $ownerDocs, array $tests): array
    {
        if (! ($this->codeGraphTablesReady)()) {
            return [
                'schema_version' => AtlasSoftwareTwinRuntimeService::IMPACT_GRAPHRAG_SCHEMA_VERSION,
                'status' => 'degraded',
                'reason' => 'code_intelligence_graph_unavailable',
                'provider_safe' => true,
                'bounded' => true,
                'selected_context' => [
                    'default_policy' => 'minimal_target_first_degraded_graph',
                    'owner_docs' => array_slice($ownerDocs, 0, $this->impactGraphLimits['doc_links']),
                    'required_tests' => array_slice($tests, 0, $this->impactGraphLimits['tests']),
                    'read_first' => array_values(array_filter([$targetPath])),
                    'expand_when_needed' => [
                        'tests' => [
                            'reason' => 'Code Intelligence graph is unavailable; load tests only if verification or repair needs them.',
                            'count' => count($tests),
                            'refs' => array_slice($tests, 0, $this->impactGraphLimits['tests']),
                        ],
                        'docs' => [
                            'reason' => 'Code Intelligence graph is unavailable; load docs only if ownership or policy is unclear.',
                            'count' => count($ownerDocs),
                            'refs' => array_slice($ownerDocs, 0, $this->impactGraphLimits['doc_links']),
                        ],
                    ],
                ],
                'causal_paths' => [],
                'confidence' => ['score' => 0, 'label' => 'none'],
                'limits' => $this->impactGraphLimits,
            ];
        }

        $targetSymbols = $this->targetSymbols($target, $targetPath);
        $symbolIds = ($this->pluckUnique)($targetSymbols, 'id');
        $moduleIds = ($this->pluckUnique)($targetSymbols, 'module_id');
        $modules = $this->impactModules($moduleIds);
        $docLinks = $this->impactDocLinks($moduleIds, $symbolIds, $targetPath);
        $entrypoints = $this->impactSymbols($moduleIds, $symbolIds, ['route', 'api_resource', 'cli_command', 'migration_table'], $targetPath);
        $graphTests = $this->impactSymbols($moduleIds, $symbolIds, ['test_method'], $targetPath);

        $moduleDocs = ($this->moduleRelatedStrings)($modules, 'related_docs');
        $moduleTests = ($this->moduleRelatedStrings)($modules, 'related_tests');
        $graphOwnerDocs = ($this->mergedUniqueStrings)(array_column($docLinks, 'canonical_path'), $moduleDocs, $ownerDocs);
        $graphRequiredTests = ($this->mergedUniqueStrings)(array_column($graphTests, 'file_path'), $moduleTests, $tests);
        $readFirst = ($this->mergedUniqueStrings)(
            [$targetPath ?? ''],
            array_slice($graphOwnerDocs, 0, 8),
            array_column($entrypoints, 'file_path'),
        );
        $causalPaths = $this->impactCausalPaths($targetPath ?? $target, $modules, $docLinks, $entrypoints, $graphTests);

        return [
            'schema_version' => AtlasSoftwareTwinRuntimeService::IMPACT_GRAPHRAG_SCHEMA_VERSION,
            'status' => $targetSymbols === [] ? 'degraded' : 'ready',
            'reason' => $targetSymbols === [] ? 'target_not_found_in_code_graph_index' : 'bounded_code_graph_context_selected',
            'provider_safe' => true,
            'bounded' => true,
            'selection_policy' => 'target_symbols_then_module_doc_test_entrypoint_neighborhood',
            'target_symbols' => array_slice($targetSymbols, 0, $this->impactGraphLimits['target_symbols']),
            'modules' => array_slice($modules, 0, $this->impactGraphLimits['modules']),
            'entrypoints' => array_slice($entrypoints, 0, $this->impactGraphLimits['entrypoints']),
            'selected_context' => [
                'default_policy' => 'minimal_target_docs_entrypoints_first',
                'owner_docs' => array_slice($graphOwnerDocs, 0, $this->impactGraphLimits['doc_links']),
                'required_tests' => array_slice($graphRequiredTests, 0, $this->impactGraphLimits['tests']),
                'read_first' => array_slice($readFirst, 0, 18),
                'expand_when_needed' => [
                    'tests' => [
                        'reason' => 'Only load test files when planning verification, repairing a failed run, or changing a tested contract.',
                        'count' => count($graphRequiredTests),
                        'refs' => array_slice($graphRequiredTests, 0, $this->impactGraphLimits['tests']),
                    ],
                    'docs' => [
                        'reason' => 'Only load full owner docs when the compact context leaves ownership, policy, or architecture unclear.',
                        'count' => count($graphOwnerDocs),
                        'refs' => array_slice($graphOwnerDocs, 0, $this->impactGraphLimits['doc_links']),
                    ],
                    'entrypoints' => [
                        'reason' => 'Only load entrypoints when the change crosses a runtime, route, CLI, migration, or API boundary.',
                        'count' => count($entrypoints),
                        'refs' => array_slice(array_column($entrypoints, 'file_path'), 0, $this->impactGraphLimits['entrypoints']),
                    ],
                ],
            ],
            'causal_paths' => array_slice($causalPaths, 0, $this->impactGraphLimits['causal_paths']),
            'confidence' => $this->impactGraphConfidence($targetSymbols, $modules, $docLinks, $graphTests, $entrypoints, $reachability),
            'limits' => $this->impactGraphLimits,
        ];
    }

    public function targetSymbols(string $target, ?string $targetPath): array
    {
        $needle = trim($target);

        return AtlasEngineeringCodeSymbol::query()
            ->active()
            ->where(function ($query) use ($needle, $targetPath): void {
                if ($targetPath !== null) {
                    $query->where('file_path', $targetPath);
                }
                if ($needle !== '') {
                    $query->orWhere('symbol_name', 'like', '%'.$needle.'%')
                        ->orWhere('file_path', 'like', '%'.$needle.'%');
                }
            })
            ->orderBy('file_path')
            ->orderBy('line_start')
            ->limit($this->impactGraphLimits['target_symbols'])
            ->get(['id', 'module_id', 'symbol_type', 'symbol_name', 'file_path', 'line_start', 'language'])
            ->map(fn (AtlasEngineeringCodeSymbol $symbol): array => $this->symbolImpactPayload($symbol))
            ->values()
            ->all();
    }

    public function impactModules(array $moduleIds): array
    {
        if ($moduleIds === []) {
            return [];
        }

        return AtlasEngineeringCodeModule::query()
            ->active()
            ->whereIn('id', $moduleIds)
            ->orderByDesc('symbol_count')
            ->limit($this->impactGraphLimits['modules'])
            ->get(['id', 'slug', 'name', 'layer', 'root_path', 'owner', 'docs_status', 'route_count', 'command_count', 'migration_count', 'test_count', 'related_docs_json', 'related_tests_json'])
            ->map(fn (AtlasEngineeringCodeModule $module): array => [
                'id' => (string) $module->id,
                'slug' => (string) $module->slug,
                'name' => (string) $module->name,
                'layer' => (string) $module->layer,
                'root_path' => $module->root_path,
                'owner' => $module->owner,
                'docs_status' => (string) $module->docs_status,
                'route_count' => (int) $module->route_count,
                'command_count' => (int) $module->command_count,
                'migration_count' => (int) $module->migration_count,
                'test_count' => (int) $module->test_count,
                'related_docs' => array_slice(($this->mergedUniqueStrings)((array) ($module->related_docs_json ?? [])), 0, 8),
                'related_tests' => array_slice(($this->mergedUniqueStrings)((array) ($module->related_tests_json ?? [])), 0, 8),
            ])
            ->values()
            ->all();
    }

function impactDocLinks(array $moduleIds, array $symbolIds, ?string $targetPath): array
    {
        if ($moduleIds === [] && $symbolIds === [] && $targetPath === null) {
            return [];
        }

        return AtlasEngineeringDocLink::query()
            ->active()
            ->where(function ($query) use ($moduleIds, $symbolIds, $targetPath): void {
                if ($moduleIds !== []) {
                    $query->orWhereIn('module_id', $moduleIds);
                }
                if ($symbolIds !== []) {
                    $query->orWhereIn('symbol_id', $symbolIds);
                }
                if ($targetPath !== null) {
                    $query->orWhere('target_path', $targetPath);
                }
            })
            ->orderBy('canonical_path')
            ->limit(60)
            ->get(['id', 'module_id', 'symbol_id', 'link_type', 'canonical_path', 'target_path'])
            ->map(fn (AtlasEngineeringDocLink $link): array => [
                'id' => (string) $link->id,
                'module_id' => $link->module_id,
                'symbol_id' => $link->symbol_id,
                'link_type' => (string) $link->link_type,
                'canonical_path' => (string) $link->canonical_path,
                'target_path' => $link->target_path,
            ])
            ->sortBy(fn (array $link): string => sprintf('%02d:%s', $this->docLinkImpactRank($link, $symbolIds, $targetPath), (string) ($link['canonical_path'] ?? '')))
            ->take($this->impactGraphLimits['doc_links'])
            ->values()
            ->all();
    }

    public function docLinkImpactRank(array $link, array $symbolIds, ?string $targetPath): int
    {
        if ($targetPath !== null && (string) ($link['target_path'] ?? '') === $targetPath) {
            return 0;
        }
        if (in_array((string) ($link['symbol_id'] ?? ''), $symbolIds, true)) {
            return 0;
        }
        $rank = (int) ($link['rank'] ?? 0);

        return $rank > 0 ? $rank : 1;
    }

    public function impactSymbols(array $moduleIds, array $excludeSymbolIds, array $types, ?string $targetPath): array
    {
        $directory = $targetPath !== null ? ($this->pathDirectory)($targetPath) : null;
        if ($moduleIds === [] && $directory === null) {
            return [];
        }

        return AtlasEngineeringCodeSymbol::query()
            ->active()
            ->whereIn('symbol_type', $types)
            ->when($excludeSymbolIds !== [], fn ($query) => $query->whereNotIn('id', $excludeSymbolIds))
            ->where(function ($query) use ($moduleIds, $directory): void {
                if ($moduleIds !== []) {
                    $query->orWhereIn('module_id', $moduleIds);
                }
                if ($directory !== null) {
                    $query->orWhere('file_path', 'like', $directory.'%');
                }
            })
            ->orderBy('file_path')
            ->orderBy('line_start')
            ->limit(in_array('test_method', $types, true) ? $this->impactGraphLimits['tests'] : $this->impactGraphLimits['entrypoints'])
            ->get(['id', 'module_id', 'symbol_type', 'symbol_name', 'file_path', 'line_start', 'language'])
            ->map(fn (AtlasEngineeringCodeSymbol $symbol): array => $this->symbolImpactPayload($symbol))
            ->values()
            ->all();
    }

    public function impactCausalPaths(string $target, array $modules, array $docLinks, array $entrypoints, array $tests): array
    {
        $paths = [];
        foreach ($modules as $module) {
            $moduleName = (string) ($module['slug'] ?? $module['name'] ?? 'module');
            $paths[] = ['from' => $target, 'via' => $moduleName, 'to' => 'module_surface', 'kind' => 'owns_or_contains_target'];
        }
        foreach ($docLinks as $link) {
            $paths[] = ['from' => $target, 'via' => (string) ($link['link_type'] ?? 'doc_link'), 'to' => (string) ($link['canonical_path'] ?? ''), 'kind' => 'requires_owner_doc_context'];
        }
        foreach ($entrypoints as $entrypoint) {
            $paths[] = ['from' => $target, 'via' => (string) ($entrypoint['symbol_type'] ?? 'entrypoint'), 'to' => (string) ($entrypoint['file_path'] ?? ''), 'kind' => 'may_affect_runtime_entrypoint'];
        }
        foreach ($tests as $test) {
            $paths[] = ['from' => $target, 'via' => 'verification', 'to' => (string) ($test['file_path'] ?? ''), 'kind' => 'must_verify_with_test'];
        }

        return $paths;
    }

    public function impactGraphConfidence(array $targetSymbols, array $modules, array $docLinks, array $tests, array $entrypoints, array $reachability): array
    {
        $score = 0;
        $score += $targetSymbols !== [] ? 30 : 0;
        $score += $modules !== [] ? 15 : 0;
        $score += $docLinks !== [] ? 20 : 0;
        $score += $tests !== [] ? 15 : 0;
        $score += $entrypoints !== [] ? 10 : 0;
        $score += data_get($reachability, 'status') === 'reachable' ? 10 : 0;

        return [
            'score' => $score,
            'label' => $score >= 75 ? 'high' : ($score >= 45 ? 'medium' : 'low'),
        ];
    }

    public function symbolImpactPayload(AtlasEngineeringCodeSymbol $symbol): array
    {
        return [
            'id' => (string) $symbol->id,
            'module_id' => $symbol->module_id,
            'symbol_type' => (string) $symbol->symbol_type,
            'symbol_name' => (string) $symbol->symbol_name,
            'file_path' => (string) $symbol->file_path,
            'line_start' => $symbol->line_start,
            'language' => $symbol->language,
        ];
    }

}