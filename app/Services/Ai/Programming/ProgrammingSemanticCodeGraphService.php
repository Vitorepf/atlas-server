<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Models\AtlasEngineeringDocLink;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProgrammingSemanticCodeGraphService
{
    /**
     * @return array<string,mixed>
     */
    public function query(string $workspace, string $objective, string $flow = 'programming.dev'): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $terms = $this->terms($objective, $flow);
        $indexed = $this->indexedGraph($workspace, $terms, $flow);
        if ($indexed !== null && $indexed['node_count'] > 0) {
            return $indexed;
        }

        $files = $this->candidateFiles($workspace, $terms);
        $nodes = [];
        $edges = [];
        $tests = [];
        $docs = [];

        foreach ($files as $file) {
            $relative = $this->relative($workspace, $file);
            $kind = $this->nodeKind($relative);
            $nodes[] = [
                'id' => hash('sha256', $relative),
                'kind' => $kind,
                'path' => $relative,
                'reason' => $this->reasonFor($relative, $terms),
                'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
            ];

            if ($kind === 'test') {
                $tests[] = $relative;
            }
            if (str_starts_with($relative, 'docs/')) {
                $docs[] = $relative;
            }
            foreach ($this->symbolNames($file) as $symbol) {
                $symbolId = hash('sha256', $relative.'#'.$symbol);
                $nodes[] = [
                    'id' => $symbolId,
                    'kind' => 'symbol',
                    'name' => $symbol,
                    'path' => $relative,
                ];
                $edges[] = [
                    'from' => hash('sha256', $relative),
                    'to' => $symbolId,
                    'type' => 'defines',
                ];
            }
        }

        return [
            'schema_version' => 'atlas.programming.semantic_code_graph.context.v1',
            'workspace_hash' => hash('sha256', $workspace),
            'flow' => str_starts_with($flow, 'programming.') ? $flow : 'programming.'.$flow,
            'query_terms' => $terms,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'nodes' => array_slice($nodes, 0, 40),
            'edges' => array_slice($edges, 0, 60),
            'related_tests' => array_values(array_unique($tests)),
            'related_docs' => array_values(array_unique($docs)),
            'source' => 'filesystem_scan',
            'complete' => $nodes !== [],
        ];
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<string,mixed>|null
     */
    private function indexedGraph(string $workspace, array $terms, string $flow): ?array
    {
        if (! $this->codeIntelligenceTablesAvailable()) {
            return null;
        }

        $modules = AtlasEngineeringCodeModule::query()
            ->active()
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('slug', 'like', '%'.$term.'%')
                        ->orWhere('name', 'like', '%'.$term.'%')
                        ->orWhere('root_path', 'like', '%'.$term.'%')
                        ->orWhere('description', 'like', '%'.$term.'%');
                }
            })
            ->limit(12)
            ->get();

        $symbols = AtlasEngineeringCodeSymbol::query()
            ->active()
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $query->orWhere('symbol_name', 'like', '%'.$term.'%')
                        ->orWhere('file_path', 'like', '%'.$term.'%')
                        ->orWhere('namespace', 'like', '%'.$term.'%')
                        ->orWhere('parent_symbol', 'like', '%'.$term.'%');
                }
            })
            ->limit(24)
            ->get();

        $moduleIds = $modules->pluck('id')->filter()->values()->all();
        $symbolIds = $symbols->pluck('id')->filter()->values()->all();
        $docLinks = AtlasEngineeringDocLink::query()
            ->active()
            ->where(function ($query) use ($terms, $moduleIds, $symbolIds): void {
                if ($moduleIds !== []) {
                    $query->orWhereIn('module_id', $moduleIds);
                }
                if ($symbolIds !== []) {
                    $query->orWhereIn('symbol_id', $symbolIds);
                }
                foreach ($terms as $term) {
                    $query->orWhere('canonical_path', 'like', '%'.$term.'%')
                        ->orWhere('target_path', 'like', '%'.$term.'%');
                }
            })
            ->limit(24)
            ->get();

        $nodes = [];
        $edges = [];
        $tests = [];
        $docs = [];

        foreach ($modules as $module) {
            $moduleId = 'module:'.$module->id;
            $nodes[] = [
                'id' => $moduleId,
                'kind' => 'module',
                'name' => $module->name,
                'path' => $module->root_path,
                'layer' => $module->layer,
                'language' => $module->primary_language,
                'reason' => 'code_intelligence_module_match',
            ];
            $tests = array_merge($tests, array_values(array_filter($module->related_tests_json ?? [], 'is_string')));
            $docs = array_merge($docs, array_values(array_filter($module->related_docs_json ?? [], 'is_string')));
        }

        foreach ($symbols as $symbol) {
            $symbolId = 'symbol:'.$symbol->id;
            $nodes[] = [
                'id' => $symbolId,
                'kind' => 'symbol',
                'name' => $symbol->symbol_name,
                'symbol_type' => $symbol->symbol_type,
                'path' => $symbol->file_path,
                'line_start' => $symbol->line_start,
                'line_end' => $symbol->line_end,
                'namespace' => $symbol->namespace,
                'reason' => 'code_intelligence_symbol_match',
            ];
            if ($symbol->module_id !== null) {
                $edges[] = [
                    'from' => 'module:'.$symbol->module_id,
                    'to' => $symbolId,
                    'type' => 'contains_symbol',
                ];
            }
        }

        foreach ($docLinks as $link) {
            $docPath = $link->canonical_path ?: $link->target_path;
            if (is_string($docPath) && $docPath !== '') {
                $docs[] = $docPath;
                $docId = 'doc:'.hash('sha256', $docPath);
                $nodes[] = [
                    'id' => $docId,
                    'kind' => 'doc',
                    'path' => $docPath,
                    'link_type' => $link->link_type,
                    'reason' => 'code_intelligence_doc_link',
                ];
                if ($link->module_id !== null) {
                    $edges[] = [
                        'from' => 'module:'.$link->module_id,
                        'to' => $docId,
                        'type' => 'documented_by',
                    ];
                }
                if ($link->symbol_id !== null) {
                    $edges[] = [
                        'from' => 'symbol:'.$link->symbol_id,
                        'to' => $docId,
                        'type' => 'documented_by',
                    ];
                }
            }
        }

        return [
            'schema_version' => 'atlas.programming.semantic_code_graph.context.v1',
            'workspace_hash' => hash('sha256', $workspace),
            'flow' => str_starts_with($flow, 'programming.') ? $flow : 'programming.'.$flow,
            'query_terms' => $terms,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'nodes' => array_slice($nodes, 0, 60),
            'edges' => array_slice($edges, 0, 80),
            'related_tests' => array_values(array_unique($tests)),
            'related_docs' => array_values(array_unique($docs)),
            'source' => 'engineering_code_intelligence',
            'complete' => $nodes !== [],
        ];
    }

    private function codeIntelligenceTablesAvailable(): bool
    {
        try {
            return Schema::hasTable('atlas_engineering_code_modules')
                && Schema::hasTable('atlas_engineering_code_symbols')
                && Schema::hasTable('atlas_engineering_doc_links');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int,string>
     */
    private function terms(string $objective, string $flow): array
    {
        $words = collect(preg_split('/[^A-Za-z0-9_]+/', Str::ascii($objective)) ?: [])
            ->map(fn (string $word): string => strtolower(trim($word)))
            ->filter(fn (string $word): bool => strlen($word) >= 4)
            ->reject(fn (string $word): bool => in_array($word, ['implemente', 'corrija', 'ajuste', 'para', 'with', 'from', 'that', 'this'], true))
            ->take(8)
            ->values()
            ->all();

        $flow = str_replace('programming.', '', strtolower($flow));
        if ($flow !== '' && $flow !== 'dev') {
            array_unshift($words, $flow);
        }

        return array_values(array_unique($words ?: ['programming']));
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,string>
     */
    private function candidateFiles(string $workspace, array $terms): array
    {
        if (! is_dir($workspace)) {
            return [];
        }
        if (! File::exists($workspace.'/app')
            && ! File::exists($workspace.'/src')
            && ! File::exists($workspace.'/tests')
            && ! File::exists($workspace.'/docs')
        ) {
            return [];
        }

        $files = collect(File::allFiles($workspace))
            ->filter(function ($file): bool {
                $path = $file->getPathname();

                return ! str_contains($path, '/vendor/')
                    && ! str_contains($path, '/node_modules/')
                    && ! str_contains($path, '/.git/')
                    && in_array(strtolower($file->getExtension()), ['php', 'ts', 'tsx', 'js', 'jsx', 'md'], true);
            })
            ->filter(function ($file) use ($terms, $workspace): bool {
                $relative = $this->relative($workspace, $file->getPathname());
                $haystack = strtolower($relative);
                foreach ($terms as $term) {
                    if (str_contains($haystack, $term)) {
                        return true;
                    }
                }

                return str_contains($relative, 'tests/') || str_contains($relative, 'docs/engineering-knowledge-base/domains/programming');
            })
            ->take(30)
            ->map(fn ($file): string => $file->getPathname())
            ->values()
            ->all();

        return $files;
    }

    private function nodeKind(string $relative): string
    {
        if (str_contains($relative, 'tests/')) {
            return 'test';
        }
        if (str_starts_with($relative, 'docs/')) {
            return 'doc';
        }
        if (str_contains($relative, 'routes/')) {
            return 'route';
        }

        return 'file';
    }

    /**
     * @return array<int,string>
     */
    private function symbolNames(string $file): array
    {
        if (! is_file($file) || filesize($file) > 300_000) {
            return [];
        }

        $content = File::get($file);
        preg_match_all('/(?:class|interface|trait|enum|function)\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $matches);

        return array_values(array_unique(array_slice($matches[1] ?? [], 0, 12)));
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function reasonFor(string $relative, array $terms): string
    {
        foreach ($terms as $term) {
            if (str_contains(strtolower($relative), $term)) {
                return 'path_matches_agentic_rag_term:'.$term;
            }
        }

        return str_contains($relative, 'tests/') ? 'candidate_related_test' : 'candidate_canonical_doc';
    }

    private function relative(string $workspace, string $path): string
    {
        return ltrim(Str::after($path, rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
    }
}
