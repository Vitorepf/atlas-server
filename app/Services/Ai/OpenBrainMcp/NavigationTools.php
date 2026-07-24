<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Kernel\Mcp\OpenBrainMcpInput;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * GOD-DEBULK D3: workspace / code-navigation tool family relocated verbatim from
 * AtlasOpenBrainMcpService (workspace info, recent changes, decision query, module/
 * route/test lookups, unified context-for). Every handler is read-only; bodies are
 * byte-identical to the pre-split service (only dispatched-handler visibility
 * private->public). The façade dispatch delegates here; the OpenBrainAudit shared
 * input-contract source-pins (the mcpInput recent-changes / decision / symbols /
 * context limit calls) were relocated to this file under GOD-DEBULK D3 with their
 * str_contains strength unchanged.
 */
class NavigationTools
{
    use OpenBrainMcpToolInput;

    public function __construct(
        private readonly AtlasHybridMemoryRetrievalService $recall,
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly OpenBrainMcpInput $mcpInput,
    ) {}

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function workspaceInfo(array $arguments): array
    {
        $workspace = $this->workspace($arguments['workspace'] ?? null);

        // Derive project slug from basename of workspace path
        $slug = $workspace ? basename($workspace) : null;

        $memoryCount = 0;
        if ($slug && Schema::hasTable('atlas_memory_entries')) {
            $memoryCount = AtlasMemoryEntry::query()
                ->where('status', 'active')
                ->where(function ($q) use ($slug) {
                    $q->where(function ($inner) use ($slug) {
                        $inner->where('scope_type', 'project')->where('scope_id', $slug);
                    })->orWhere('scope_type', 'global');
                })
                ->count();
        }

        $codeSummary = $this->code->summary();
        $knowledgeSummary = $this->knowledge->summary();

        return [
            'ok' => true,
            'tool' => 'atlas_workspace_info',
            'workspace' => $workspace,
            'inferred_slug' => $slug,
            'atlas_tracked' => $memoryCount > 0,
            'memory_entry_count' => $memoryCount,
            'code_intelligence' => [
                'indexed' => ($codeSummary['module_count'] ?? 0) > 0,
                'last_indexed_at' => $codeSummary['last_indexed_at'] ?? null,
                'module_count' => $codeSummary['module_count'] ?? 0,
                'symbol_count' => $codeSummary['symbol_count'] ?? 0,
            ],
            'knowledge_base' => [
                'indexed' => ($knowledgeSummary['active'] ?? 0) > 0,
                'last_indexed_at' => $knowledgeSummary['last_indexed_at'] ?? null,
                'doc_count' => $knowledgeSummary['active'] ?? 0,
            ],
            'recommended_action' => $memoryCount > 0
                ? 'consult_atlas_first'
                : 'fallback_to_local_exploration',
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function recentChanges(array $arguments): array
    {
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        if ($workspace === null || ! is_dir($workspace.'/.git')) {
            return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'workspace_not_git_repo'];
        }

        $since = $this->string($arguments['since'] ?? null) ?: '7 days ago';
        $limit = $this->mcpInput->recentChangesLimit($arguments['limit'] ?? null);

        $process = new Process(
            ['git', 'log', '--name-only', '--pretty=format:', '--since='.$since],
            $workspace
        );
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return ['ok' => false, 'tool' => 'atlas_recent_changes', 'error' => 'git_command_failed'];
        }

        $files = array_values(array_unique(array_filter(explode("\n", $process->getOutput()))));
        $files = array_slice($files, 0, $limit);

        $codeSummary = $this->code->summary();
        $lastIndexAt = $codeSummary['last_indexed_at'] ?? null;
        $indexFresh = false;
        if ($lastIndexAt !== null) {
            try {
                $indexFresh = Carbon::parse($lastIndexAt)->greaterThan(now()->subDay());
            } catch (Throwable $e) {
                $indexFresh = false;
            }
        }

        return [
            'ok' => true,
            'tool' => 'atlas_recent_changes',
            'workspace' => $workspace,
            'since' => $since,
            'changed_files' => $files,
            'count' => count($files),
            'index_fresh' => $indexFresh,
            'last_indexed_at' => $lastIndexAt,
            'recommended_action' => $indexFresh ? null : 'reindex_recommended',
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function decisionQuery(array $arguments): array
    {
        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return ['ok' => false, 'tool' => 'atlas_decision_query', 'error' => 'query_required'];
        }

        $context = [];
        $workspace = $this->workspace($arguments['workspace'] ?? null);
        if ($workspace !== null) {
            $context['workspace'] = $workspace;
        }

        $filters = ['memory_type' => ['decision']];
        $scope = $this->string($arguments['scope'] ?? null);
        if ($scope !== null) {
            $filters['scope_type'] = $scope;
        }

        $options = ['limit' => $this->mcpInput->decisionLimit($arguments['limit'] ?? null)];

        $recall = $this->recall->recall($query, $context, $filters, $options);

        // Post-filter: ensure only decision-type items leak through
        // (registry items use 'type' key; verbatim/semantic items are not decision-typed)
        $decisions = array_values(array_filter(
            $recall['recall'] ?? [],
            fn (array $item): bool => ($item['type'] ?? null) === 'decision',
        ));

        return [
            'ok' => true,
            'tool' => 'atlas_decision_query',
            'query' => $query,
            'decisions' => $decisions,
            'count' => count($decisions),
            'summary' => $recall['summary'] ?? [],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function moduleInfo(array $arguments): array
    {
        $slug = $this->string($arguments['slug'] ?? null);
        if ($slug === null) {
            return ['ok' => false, 'tool' => 'atlas_module_info', 'error' => 'slug_required'];
        }

        $data = $this->code->module($slug);
        if ($data === null) {
            return ['ok' => false, 'tool' => 'atlas_module_info', 'error' => 'module_not_found'];
        }

        // code->module() already returns ['module' => ..., 'symbols' => ..., 'doc_links' => ...]
        // Respect include_symbols and symbols_limit parameters
        $includeSymbols = (bool) ($arguments['include_symbols'] ?? true);
        $symbolsLimit = $this->mcpInput->symbolsLimit($arguments['symbols_limit'] ?? null);

        $payload = [
            'ok' => true,
            'tool' => 'atlas_module_info',
            'module' => $data['module'],
        ];

        if ($includeSymbols) {
            $payload['symbols'] = array_slice($data['symbols'] ?? [], 0, $symbolsLimit);
            $payload['doc_links'] = $data['doc_links'] ?? [];
        }

        $payload['generated_at'] = now()->toJSON();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function routeInfo(array $arguments): array
    {
        $path = $this->string($arguments['path'] ?? null);
        if ($path === null) {
            return ['ok' => false, 'tool' => 'atlas_route_info', 'error' => 'path_required'];
        }

        $limit = $this->mcpInput->codeLimit($arguments['limit'] ?? null);
        // AP-815 W-3 — same default-safe scoping as codeFindRelevant(): resolve the workspace
        // to its stable id and thread it into symbols(); the filter only applies when the
        // W-1 workspace_id column exists, so a pre-W-1 read-model keeps global behaviour.
        $workspacePath = $this->workspace($arguments['workspace'] ?? null);
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspacePath);
        $result = $this->code->symbols(['q' => $path, 'symbol_type' => 'route', 'workspace_id' => $workspaceId], $limit);
        $routes = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_route_info',
            'path' => $path,
            'workspace' => $workspacePath,
            'workspace_id' => $workspaceId,
            'routes' => $routes,
            'count' => count($routes),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function testFor(array $arguments): array
    {
        $target = $this->string($arguments['target'] ?? null);
        if ($target === null) {
            return ['ok' => false, 'tool' => 'atlas_test_for', 'error' => 'target_required'];
        }

        $limit = $this->mcpInput->codeLimit($arguments['limit'] ?? null);
        // AP-815 W-3 — same default-safe scoping as codeFindRelevant() (see routeInfo()).
        $workspacePath = $this->workspace($arguments['workspace'] ?? null);
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspacePath);
        $result = $this->code->symbols(['q' => $target, 'symbol_type' => 'test_method', 'workspace_id' => $workspaceId], $limit);
        $tests = $result['symbols'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_test_for',
            'target' => $target,
            'workspace' => $workspacePath,
            'workspace_id' => $workspaceId,
            'tests' => $tests,
            'count' => count($tests),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function contextFor(array $arguments): array
    {
        $task = $this->string($arguments['task_description'] ?? null);
        if ($task === null) {
            return ['ok' => false, 'tool' => 'atlas_context_for', 'error' => 'task_description_required'];
        }

        $workspace = $this->workspace($arguments['workspace'] ?? null);
        $context = $workspace !== null ? ['workspace' => $workspace] : [];
        // AP-815 W-3 — same default-safe scoping as codeFindRelevant() for the code leg
        // (memory recall is already workspace-scoped via $context above).
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($workspace);

        $memoryLimit = $this->mcpInput->contextMemoryLimit($arguments['memory_limit'] ?? null);
        $codeLimit = $this->mcpInput->contextCodeLimit($arguments['code_limit'] ?? null);
        $docsLimit = $this->mcpInput->contextDocsLimit($arguments['docs_limit'] ?? null);

        $memory = $this->recall->recall($task, $context, [], ['limit' => $memoryLimit]);
        $code = $this->code->symbols(['q' => $task, 'workspace_id' => $workspaceId], $codeLimit);
        $docs = $this->knowledge->catalog(['q' => $task, 'status' => 'active'], $docsLimit);

        $memoryEntries = $memory['recall'] ?? [];
        $codeSymbols = $code['symbols'] ?? [];
        $docsItems = $docs['items'] ?? [];

        return [
            'ok' => true,
            'tool' => 'atlas_context_for',
            'task_description' => $task,
            'workspace' => $workspace,
            'workspace_id' => $workspaceId,
            'memory' => [
                'entries' => $memoryEntries,
                'count' => count($memoryEntries),
            ],
            'code' => [
                'symbols' => $codeSymbols,
                'count' => count($codeSymbols),
            ],
            'docs' => [
                'items' => $docsItems,
                'count' => count($docsItems),
            ],
            'generated_at' => now()->toJSON(),
        ];
    }

    // ponytail: string/workspace copied verbatim from the façade (which keeps its own
    // pinned copies) — matches the existing per-Tools-class primitive convention.

}
