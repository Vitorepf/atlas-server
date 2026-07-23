<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Context\AtlasCanonicalContextRef;
use App\Services\AtlasCode\WorkspaceFolderIntelligenceService;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim code family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class CodeSection
{
    /**
     * Code-graph symbol types that are useful, but usually too noisy for the
     * first implementation brief. They remain available through explicit pulls.
     *
     * @var array<string,string>
     */
    private const AUXILIARY_CODE_SOURCE_TYPES = [
        'test_method' => 'test_symbols',
        'doc_heading' => 'canonical_doc',
        'file' => 'code_files',
        'cli_command' => 'runtime_surfaces',
        'route' => 'runtime_surfaces',
    ];

    public function __construct(
        private readonly Support $support,
        private readonly CodeGraphContextRetriever $codeGraph,
    ) {}

    /**
     * Code-graph section via the proven BM25 + E-3 retriever (workspace-scoped).
     * The retriever's token budget is char-budget / ~4 (its ~4-chars-per-token
     * convention) so the section respects the supplied char sub-budget.
     *
     * @param  array<int,string>  $changedFiles
     * @return array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    public function codeSection(string $task, string $workspaceId, int $budgetChars, array $changedFiles, array $opts = []): array
    {
        $empty = [
            'present' => false,
            'items' => [],
            'chars' => 0,
            'provenance' => ['retriever' => CodeGraphContextRetriever::SCHEMA, 'note' => AtlasOpenBrainContextPackService::HONESTY_LABEL],
        ];

        if ($task === '' || $budgetChars <= 0) {
            return $empty;
        }

        try {
            $tokenBudget = (int) max(0, (int) floor($budgetChars / 4));
            // AP-818 F2.5 — workspace ativo = guarda-chuva (flag ON) → recall no
            // escopo agregado: grafo do umbrella + grafos próprios dos membros.
            // Flag OFF (default) → packFor single-workspace byte-idêntico.
            $workspaceScope = $this->umbrellaContextScope($workspaceId);
            $assemblyOptions = ['fill_gaps' => true];
            $pack = count($workspaceScope) > 1
                ? $this->codeGraph->packForWorkspaces($task, $workspaceScope, $tokenBudget, $changedFiles, $assemblyOptions)
                : $this->codeGraph->packFor($task, $workspaceId, $tokenBudget, $changedFiles, $assemblyOptions);
        } catch (Throwable) {
            return $empty; // best-effort recall, never a gate
        }

        $included = is_array($pack['included'] ?? null) ? $pack['included'] : [];
        $items = [];
        $chars = 0;
        foreach ($included as $node) {
            if (! is_array($node)) {
                continue;
            }
            $signature = (string) ($node['signature'] ?? '');
            $item = [
                'id' => (string) ($node['id'] ?? ''),
                'symbol_type' => (string) ($node['symbol_type'] ?? ''),
                'file_path' => (string) ($node['file_path'] ?? ''),
                'signature' => $signature,
                'tokens' => (int) ($node['tokens'] ?? 0),
            ];
            $chars += strlen($item['id'].$item['file_path'].$signature);
            $items[] = $item;
        }
        [$items, $pathFilteredCount] = $this->filterInitialCodePathNoise($task, $items, $opts);
        [$items, $demotedCount] = $this->filterDemotedCodeItems($items, $this->support->stringList($opts['_demote_context_refs'] ?? []));
        $delivery = $this->initialCodeGraphDeliveryPolicy($task, $items, $opts);
        $items = $delivery['items'];
        $chars = $this->support->codeItemsChars($items);

        return [
            'present' => $items !== [],
            'items' => $items,
            'chars' => $chars,
            'provenance' => array_merge([
                'retriever' => CodeGraphContextRetriever::SCHEMA,
                'workspace_id' => $workspaceId,
            ], count($workspaceScope) > 1 ? [
                // F2.5: só aparece quando o escopo umbrella expandiu — flag OFF
                // mantém a proveniência byte-idêntica ao formato provado.
                'workspace_scope' => $workspaceScope,
            ] : [], [
                'token_budget' => (int) ($pack['budget'] ?? 0),
                'estimated_tokens' => (int) ($pack['estimated_tokens'] ?? 0),
                'truncated' => (bool) ($pack['truncated'] ?? false),
                'assembly_fill_gaps' => true,
                'path_filtered_count' => $pathFilteredCount,
                'feedback_demoted_count' => $demotedCount,
                'note' => AtlasOpenBrainContextPackService::HONESTY_LABEL,
            ], $delivery['provenance']),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $opts
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function filterInitialCodePathNoise(string $task, array $items, array $opts): array
    {
        if ($items === [] || $this->support->boolOpt($opts, 'include_noisy_code_context', false)) {
            return [$items, 0];
        }

        $changedFiles = $this->support->stringList($opts['changed_files'] ?? []);
        $filtered = [];
        $removed = 0;
        foreach ($items as $item) {
            $path = (string) ($item['file_path'] ?? '');
            $noiseType = $this->initialCodeNoiseType($path, $item);
            if (
                $noiseType !== null
                && ! $this->taskAllowsNoisyCodePath($task, $noiseType)
                && ! in_array($path, $changedFiles, true)
            ) {
                $removed++;

                continue;
            }
            $filtered[] = $item;
        }

        return [$filtered, $removed];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public function initialCodeNoiseType(string $path, array $item): ?string
    {
        $path = strtolower(trim($path));
        $path = ltrim($path, './');
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'tools/rivals/benchmarks/') || str_contains($path, '/tools/rivals/benchmarks/')) {
            return 'benchmark_fixture';
        }
        if (str_starts_with($path, 'vendor/') || str_contains($path, '/vendor/') || str_starts_with($path, 'node_modules/') || str_contains($path, '/node_modules/')) {
            return 'vendor_dependency';
        }
        if (str_starts_with($path, 'database/migrations/') || str_contains($path, '/database/migrations/')) {
            return 'migration';
        }
        if (str_contains($path, '/generated/') || str_contains($path, 'aaeos/generated/')) {
            return 'generated';
        }

        $symbolName = ltrim((string) ($item['symbol_name'] ?? ''), '\\');
        $id = ltrim((string) ($item['id'] ?? ''), '\\');
        if (str_starts_with($symbolName, 'phpDocumentor\\') || str_starts_with($id, 'sym:phpDocumentor\\')) {
            return 'vendor_namespace_stub';
        }

        return null;
    }

    public function taskAllowsNoisyCodePath(string $task, string $noiseType): bool
    {
        $text = $this->support->normalizedIntentText($task);

        return match ($noiseType) {
            'benchmark_fixture' => $this->support->containsAny($text, ['benchmark', 'rivals', 'swe-bench', 'inspect evals', 'tau2', 'eval fixture', 'evaluation fixture']),
            'vendor_dependency' => $this->support->containsAny($text, ['vendor', 'composer', 'dependency', 'dependencia', 'package', 'node_modules']),
            'migration' => $this->support->containsAny($text, ['migration', 'migrations', 'database', 'schema', 'tabela', 'table', 'column', 'coluna']),
            'generated' => $this->support->containsAny($text, ['generated', 'gerado', 'gerada', 'aaeos/generated']),
            'vendor_namespace_stub' => $this->support->containsAny($text, ['phpdocumentor', 'docblock', 'doc block', 'selfmod', 'invariant', 'invariante']),
            default => false,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,string>  $demoteRefs
     * @return array{0:array<int,array<string,mixed>>,1:int}
     */
    public function filterDemotedCodeItems(array $items, array $demoteRefs): array
    {
        if ($items === [] || $demoteRefs === []) {
            return [$items, 0];
        }

        $filtered = [];
        $demoted = 0;
        foreach ($items as $item) {
            if ($this->support->matchesDemotedRef($this->codeItemRefs($item), $demoteRefs)) {
                $demoted++;

                continue;
            }
            $filtered[] = $item;
        }

        return [$filtered, $demoted];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<int,string>
     */
    public function codeItemRefs(array $item): array
    {
        $id = trim((string) ($item['id'] ?? ''));
        $filePath = trim((string) ($item['file_path'] ?? ''));
        $symbol = $id;
        if (str_contains($symbol, ':')) {
            $symbol = (string) str($symbol)->afterLast(':');
        }

        return $this->support->uniqueStrings([
            $id,
            $filePath,
            $filePath !== '' && $symbol !== '' ? $filePath.'::'.$symbol : '',
            AtlasCanonicalContextRef::fromCodeItem($item),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<string,mixed>  $opts
     * @return array{items:array<int,array<string,mixed>>, provenance:array<string,mixed>}
     */
    public function initialCodeGraphDeliveryPolicy(string $task, array $items, array $opts): array
    {
        $base = [
            'schema_version' => 'atlas.aobg.initial_code_graph_delivery_policy.v1',
            'mode' => 'implementation_symbols_first',
            'original_count' => count($items),
            'retained_count' => count($items),
            'deferred_count' => 0,
            'deferred_symbol_counts' => [],
            'deferred_source_types' => [],
            'deferred_on_demand_handles' => [],
            'explicit_auxiliary_intent' => false,
            'guardrails' => [
                'min_top_item_when_auxiliary_only' => true,
                'raw_test_bodies_exposed' => false,
                'raw_docs_dumped' => false,
                'provider_safe_only' => true,
            ],
        ];

        if ($items === []) {
            return ['items' => [], 'provenance' => ['initial_delivery_policy' => array_merge($base, ['mode' => 'empty'])]];
        }

        $initialAuxiliarySourceTypes = $this->initialAuxiliarySourceTypes($task, $opts);
        if (in_array('*', $initialAuxiliarySourceTypes, true)) {
            return [
                'items' => $items,
                'provenance' => [
                    'initial_delivery_policy' => array_merge($base, [
                        'mode' => 'auxiliary_symbols_included_by_intent',
                        'explicit_auxiliary_intent' => true,
                    ]),
                ],
            ];
        }

        $retained = [];
        $deferred = [];
        foreach ($items as $item) {
            $sourceType = $this->auxiliaryCodeSourceType($item);
            if ($sourceType !== null && ! in_array($sourceType, $initialAuxiliarySourceTypes, true)) {
                $deferred[] = $item;

                continue;
            }
            $retained[] = $item;
        }

        if ($deferred === []) {
            return [
                'items' => $items,
                'provenance' => [
                    'initial_delivery_policy' => array_merge($base, $initialAuxiliarySourceTypes === [] ? [] : [
                        'mode' => 'auxiliary_symbols_included_by_intent',
                        'explicit_auxiliary_intent' => true,
                        'included_auxiliary_source_types' => $initialAuxiliarySourceTypes,
                    ]),
                ],
            ];
        }

        $keptAuxiliaryTopItem = null;
        if ($retained === []) {
            $keptAuxiliaryTopItem = array_shift($deferred);
            if (is_array($keptAuxiliaryTopItem)) {
                $retained[] = $keptAuxiliaryTopItem;
            }
        }

        $counts = [];
        $sourceTypes = [];
        $handles = [];
        foreach ($deferred as $item) {
            $symbolType = (string) ($item['symbol_type'] ?? '');
            $sourceType = $this->auxiliaryCodeSourceType($item);
            if ($sourceType === null) {
                continue;
            }
            $countKey = $symbolType !== '' ? $symbolType : $sourceType;
            $counts[$countKey] = ($counts[$countKey] ?? 0) + 1;
            $sourceTypes[] = $sourceType;
            $handles[] = $this->auxiliaryCodeHandle($sourceType);
        }

        return [
            'items' => array_values($retained),
            'provenance' => [
                'initial_delivery_policy' => array_merge($base, [
                    'mode' => $counts === [] ? 'auxiliary_only_min_top_item' : 'auxiliary_symbols_deferred',
                    'retained_count' => count($retained),
                    'deferred_count' => array_sum($counts),
                    'deferred_symbol_counts' => $counts,
                    'deferred_source_types' => $this->support->uniqueStrings($sourceTypes),
                    'deferred_on_demand_handles' => $this->support->uniqueStrings($handles),
                    'explicit_auxiliary_intent' => $initialAuxiliarySourceTypes !== [],
                    'included_auxiliary_source_types' => $initialAuxiliarySourceTypes,
                    'kept_auxiliary_top_item_type' => is_array($keptAuxiliaryTopItem)
                        ? (string) ($keptAuxiliaryTopItem['symbol_type'] ?? '')
                        : null,
                ]),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public function auxiliaryCodeSourceType(array $item): ?string
    {
        $symbolType = (string) ($item['symbol_type'] ?? '');
        if (isset(self::AUXILIARY_CODE_SOURCE_TYPES[$symbolType])) {
            return self::AUXILIARY_CODE_SOURCE_TYPES[$symbolType];
        }

        $filePath = strtolower((string) ($item['file_path'] ?? ''));
        if ($filePath === '') {
            return null;
        }
        if (str_starts_with($filePath, 'tests/') || str_contains($filePath, '/tests/')) {
            return 'test_symbols';
        }
        if (str_starts_with($filePath, 'docs/') || str_contains($filePath, '/docs/')) {
            return 'canonical_doc';
        }
        if (
            str_starts_with($filePath, 'app/console/commands/')
            || str_starts_with($filePath, 'app/http/controllers/')
            || str_starts_with($filePath, 'app/http/requests/')
        ) {
            return 'runtime_surfaces';
        }

        return null;
    }

    public function auxiliaryCodeHandle(string $sourceType): string
    {
        return match ($sourceType) {
            'test_symbols' => 'expand:test_symbols',
            'canonical_doc' => 'recheck:canonical_doc',
            default => 'expand:code_intelligence',
        };
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $initialPolicy
     * @return array<string,mixed>
     */
    public function mergeInitialCodeGraphDeliveryPolicy(array $policy, array $initialPolicy): array
    {
        $deferredCount = (int) ($initialPolicy['deferred_count'] ?? 0);
        if ($deferredCount <= 0) {
            return $policy;
        }

        $handles = $this->support->stringList($initialPolicy['deferred_on_demand_handles'] ?? []);
        $sourceTypes = $this->support->stringList($initialPolicy['deferred_source_types'] ?? []);
        if ($handles === [] && $sourceTypes === []) {
            return $policy;
        }

        $actions = $this->support->uniqueStrings(array_merge(
            $this->support->stringList($policy['actions'] ?? []),
            ['defer_auxiliary_code_symbols'],
        ));
        $policy['actions'] = $actions !== [] ? $actions : ['defer_auxiliary_code_symbols'];
        $policy['status'] = 'active';

        $mode = (string) ($policy['delivery_mode'] ?? 'standard_minimal_top_k');
        if (! str_starts_with($mode, 'feedback_')) {
            $policy['delivery_mode'] = 'initial_code_symbols_first_expand_on_demand';
        }

        $source = (string) ($policy['source'] ?? 'none');
        $policy['source'] = in_array($source, ['none', 'no_recent_feedback', 'no_flow_feedback'], true)
            ? 'initial_code_graph_delivery_policy'
            : (str_contains($source, 'initial_code_graph_delivery_policy')
                ? $source
                : $source.'+initial_code_graph_delivery_policy');

        $policy['deferred_source_types'] = $this->support->uniqueStrings(array_merge(
            $this->support->stringList($policy['deferred_source_types'] ?? []),
            $sourceTypes,
        ));
        $policy['expand_source_types'] = $this->support->uniqueStrings(array_merge(
            $this->support->stringList($policy['expand_source_types'] ?? []),
            $sourceTypes,
        ));
        $policy['on_demand_handles'] = $this->support->uniqueStrings(array_merge(
            $this->support->stringList($policy['on_demand_handles'] ?? []),
            $handles,
        ));
        $policy['initial_code_graph_delivery_policy'] = [
            'schema_version' => (string) ($initialPolicy['schema_version'] ?? 'atlas.aobg.initial_code_graph_delivery_policy.v1'),
            'mode' => (string) ($initialPolicy['mode'] ?? 'auxiliary_symbols_deferred'),
            'original_count' => (int) ($initialPolicy['original_count'] ?? 0),
            'retained_count' => (int) ($initialPolicy['retained_count'] ?? 0),
            'deferred_count' => $deferredCount,
            'deferred_symbol_counts' => (array) ($initialPolicy['deferred_symbol_counts'] ?? []),
            'deferred_source_types' => $sourceTypes,
            'deferred_on_demand_handles' => $handles,
        ];
        $policy['quality_gate_hint'] = 'expand_deferred_auxiliary_code_symbols_when_task_requires_them';

        if (is_array($policy['policy'] ?? null)) {
            $policy['policy']['requires_provider_pull_for_expansion'] = true;
            $policy['policy']['source_expansion_auto_applied'] = false;
        }

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return array<int,string>
     */
    public function initialAuxiliarySourceTypes(string $task, array $opts): array
    {
        if ($this->support->boolOpt($opts, 'include_auxiliary_code_symbols', false)) {
            return ['*'];
        }

        $sourceTypes = [];
        $taskType = strtolower((string) ($this->support->stringOpt($opts, 'task_type') ?? ''));
        if (in_array($taskType, ['test', 'tests', 'qa', 'coverage'], true)) {
            $sourceTypes[] = 'test_symbols';
        }

        foreach ($this->support->stringList($opts['changed_files'] ?? []) as $path) {
            $path = strtolower($path);
            if (str_starts_with($path, 'tests/') || str_contains($path, '/tests/')) {
                $sourceTypes[] = 'test_symbols';
            }
            if (str_starts_with($path, 'docs/') || str_contains($path, '/docs/')) {
                $sourceTypes[] = 'canonical_doc';
            }
        }

        $text = $this->support->normalizedIntentText($task);
        foreach ([
            'defer',
            'deferir',
            'adiar',
            'sob demanda',
            'on demand',
            'expandir sob demanda',
            'nao trazer testes',
            'nao trazer docs',
            'sem testes no inicial',
            'sem docs no inicial',
        ] as $deferTerm) {
            if (str_contains($text, $deferTerm)) {
                return [];
            }
        }

        $patternsBySourceType = [
            'test_symbols' => [
                '/\b(find|list|listar|quais|which|mapear|impact|impacto|rodar|run|corrigir|fix|failing|falhando)\b.*\b(test|tests|teste|testes|spec|coverage|cobertura)\b/',
                '/\b(test|tests|teste|testes|coverage|cobertura)\b.*\b(impact|impacto|falhando|failing|rodar|run|corrigir|fix|listar|list)\b/',
            ],
            'canonical_doc' => [
                '/\b(find|list|listar|ler|read|quais|which|auditar|review|revisar|mapear)\b.*\b(doc|docs|documentacao|canonical doc)\b/',
            ],
            'runtime_surfaces' => [
                '/\b(cli|artisan|command|commands|comando|comandos|route|routes|rota|rotas|api|endpoint|endpoints)\b/',
            ],
        ];
        foreach ($patternsBySourceType as $sourceType => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $sourceTypes[] = $sourceType;
                    break;
                }
            }
        }

        return $this->support->uniqueStrings($sourceTypes);
    }

    /**
     * AP-818 F2.5 — the retrieval scope for the active workspace. Flag
     * `atlas.code_folder_intelligence.umbrella_context` OFF (default) keeps the
     * proven single-workspace behaviour; ON expands an umbrella workspace to
     * [umbrella graph + every member with its own graph] via the folder
     * intelligence service. Fail-safe: any error degrades to single scope.
     *
     * @return array<int,string>
     */
    public function umbrellaContextScope(string $workspaceId): array
    {
        if (! (bool) config('atlas.code_folder_intelligence.umbrella_context', false)) {
            return [$workspaceId];
        }

        try {
            return app(WorkspaceFolderIntelligenceService::class)
                ->contextScopeIds($workspaceId);
        } catch (Throwable) {
            return [$workspaceId];
        }
    }
}
