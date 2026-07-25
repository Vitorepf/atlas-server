<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\OpenBrain\Support\ContextExpansionRenderSupport;

final class AtlasOpenBrainContextExpansionService
{
    public const SCHEMA_VERSION = 'atlas.open_brain.context_expansion.v1';

    /**
     * @var array<int,string>
     */
    private const RANKING_SOURCE_TYPES = [
        'evidence_replay',
        'code_intelligence',
        'memory_signals',
        'vector_retrieval',
    ];

    public function __construct(
        private readonly AtlasContextRankingSystemService $ranking,
        private readonly AtlasOpenBrainContextPackService $contextPacks,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function expand(array $input): array
    {
        $handle = $this->handle((string) ($input['handle'] ?? ''));
        $objective = trim((string) ($input['objective'] ?? $input['task'] ?? $input['query'] ?? ''));
        $workspace = ContextExpansionRenderSupport::scalarString($input['workspace'] ?? null);
        $taskType = ContextExpansionRenderSupport::scalarString($input['task_type'] ?? null, 'dev');
        $domain = ContextExpansionRenderSupport::scalarString($input['domain'] ?? null, 'atlas');
        $risk = ContextExpansionRenderSupport::scalarString($input['risk_level'] ?? $input['risk'] ?? null, 'low');
        $maxRefs = max(1, min(20, (int) ($input['max_refs'] ?? 6)));
        $budget = max(800, min(8000, (int) ($input['budget'] ?? 3200)));

        if ($handle['source_type'] === '') {
            return $this->unsupported($handle, $objective, $workspace, 'missing_source_type');
        }

        if (in_array($handle['source_type'], self::RANKING_SOURCE_TYPES, true)) {
            $payload = $this->rankingExpansion($handle, $objective, $domain, $risk, $taskType, $maxRefs);
        } elseif ($handle['source_type'] === 'test_symbols') {
            $payload = $this->testSymbolExpansion($handle, $objective, $workspace, $taskType, $maxRefs, $budget);
        } elseif ($handle['source_type'] === 'canonical_doc') {
            $payload = $this->canonicalDocExpansion($handle, $objective, $workspace, $budget);
        } else {
            return $this->unsupported($handle, $objective, $workspace, 'unsupported_source_type');
        }

        $payload += [
            'schema_version' => self::SCHEMA_VERSION,
            'query' => ContextExpansionRenderSupport::querySummary($objective, $workspace, $taskType, $domain, $risk),
            'handle' => $handle,
            'policy' => ContextExpansionRenderSupport::policy(),
        ];
        $payload['expansion_hash'] = MissionCanonicalHash::sha256(ContextExpansionRenderSupport::stableForHash($payload));
        $payload['markdown'] = ContextExpansionRenderSupport::renderMarkdown($payload);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function handle(string $handle): array
    {
        $handle = trim($handle);
        $action = 'expand';
        $sourceType = $handle;

        if (str_contains($handle, ':')) {
            [$prefix, $rest] = array_pad(explode(':', $handle, 2), 2, '');
            $prefix = trim($prefix);
            $rest = trim($rest);
            if (in_array($prefix, ['expand', 'recheck'], true)) {
                $action = $prefix;
                $sourceType = $rest;
            }
        }

        return [
            'id' => $handle,
            'action' => $action,
            'source_type' => ContextExpansionRenderSupport::normalizeSourceType($sourceType),
            'quality_gate_hint' => $action === 'recheck'
                ? 'required_source_recheck_before_implementation'
                : 'targeted_context_expansion',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rankingExpansion(array $handle, string $objective, string $domain, string $risk, string $taskType, int $maxRefs): array
    {
        $sourceType = (string) $handle['source_type'];
        $ranking = $this->ranking->rank([
            'objective' => $objective,
            'task_type' => $taskType,
            'domain' => $domain,
            'risk_level' => $risk,
            'max_refs' => max($maxRefs, 8),
            'feedback_hint_input' => [
                'repromote_source_types' => [$sourceType],
            ],
        ]);

        $selected = $this->refsForSource((array) data_get($ranking, 'rerank_result.selected_refs', []), $sourceType, $maxRefs);
        $excluded = $this->refsForSource((array) data_get($ranking, 'rerank_result.excluded_refs', []), $sourceType, $maxRefs);
        $coverage = (array) data_get($ranking, 'rerank_result.metrics.required_source_coverage', []);
        $covered = (bool) ($coverage[$sourceType] ?? $selected !== []);

        return [
            'status' => $selected !== [] || $excluded !== [] ? 'ready' : 'degraded',
            'mode' => 'ranking_filtered_source_refs',
            'expansion' => [
                'source_type' => $sourceType,
                'selected_refs' => $selected,
                'excluded_refs' => $excluded,
                'required_source_covered' => $covered,
                'selected_ref_count' => count($selected),
                'excluded_ref_count' => count($excluded),
                'ranking_status' => (string) ($ranking['status'] ?? 'unknown'),
                'rerank_result_hash' => (string) ($ranking['rerank_result_hash'] ?? ''),
                'recommended_next_action' => ContextExpansionRenderSupport::nextAction($handle, $selected, $covered),
            ],
            'warnings' => $covered ? [] : ['requested_source_not_covered_by_current_ranking'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalDocExpansion(array $handle, string $objective, ?string $workspace, int $budget): array
    {
        $pack = $this->contextPacks->packFor($objective, array_filter([
            'workspace' => $workspace,
            'budget' => $budget,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        $sourcesPresent = array_values((array) data_get($pack, 'provenance.sources_present', []));
        $counts = (array) ($pack['counts'] ?? []);
        $hasContext = $sourcesPresent !== [] || array_sum(array_map('intval', $counts)) > 0;

        return [
            'status' => $hasContext ? 'ready' : 'degraded',
            'mode' => 'canonical_doc_recheck_pack',
            'expansion' => [
                'source_type' => 'canonical_doc',
                'context_pack_schema' => (string) ($pack['schema'] ?? AtlasOpenBrainContextPackService::SCHEMA),
                'context_pack_honesty' => (string) ($pack['honesty'] ?? AtlasOpenBrainContextPackService::HONESTY_LABEL),
                'sources_present' => $sourcesPresent,
                'counts' => $counts,
                'budget' => (array) ($pack['budget'] ?? []),
                'markdown_excerpt' => ContextExpansionRenderSupport::providerSafeMarkdownExcerpt(
                    (string) ($pack['markdown'] ?? ''),
                    $objective,
                    $budget,
                ),
                'recommended_next_action' => 'Read owner docs or request a specific file-context before implementation.',
            ],
            'warnings' => array_values(array_filter([
                'canonical_doc_recheck_required_before_implementation',
                $hasContext ? null : 'context_pack_empty_for_recheck',
            ])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function testSymbolExpansion(array $handle, string $objective, ?string $workspace, string $taskType, int $maxRefs, int $budget): array
    {
        $query = trim($objective.' tests coverage validation');
        $pack = $this->contextPacks->packFor($query, array_filter([
            'workspace' => $workspace,
            'budget' => $budget,
            'task_type' => $taskType !== '' ? $taskType : 'test',
            'include_auxiliary_code_symbols' => true,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $symbols = collect((array) ($pack['code_graph'] ?? []))
            ->filter(static fn (mixed $item): bool => is_array($item) && (string) ($item['symbol_type'] ?? '') === 'test_method')
            ->map(static fn (array $item): array => ContextExpansionRenderSupport::providerSafeCodeSymbol($item))
            ->values()
            ->take($maxRefs)
            ->all();

        return [
            'status' => $symbols !== [] ? 'ready' : 'degraded',
            'mode' => 'code_graph_test_symbol_expansion',
            'expansion' => [
                'source_type' => 'test_symbols',
                'selected_symbols' => $symbols,
                'selected_symbol_count' => count($symbols),
                'context_pack_hash' => (string) ($pack['context_pack_hash'] ?? ''),
                'counts' => (array) ($pack['counts'] ?? []),
                'recommended_next_action' => $symbols !== []
                    ? 'Use these test symbols as targeted pointers, then request file-context only for tests you will touch or run.'
                    : 'Request a broader code-intelligence expansion or search tests directly; no provider-safe test symbol matched.',
            ],
            'warnings' => $symbols !== [] ? [] : ['requested_test_symbols_not_found'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @return array<int,array<string,mixed>>
     */
    private function refsForSource(array $refs, string $sourceType, int $limit): array
    {
        return collect($refs)
            ->filter(static fn (mixed $ref): bool => is_array($ref) && (string) ($ref['source_type'] ?? '') === $sourceType)
            ->map(static fn (array $ref): array => ContextExpansionRenderSupport::providerSafeRef($ref))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function unsupported(array $handle, string $objective, ?string $workspace, string $reason): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'unsupported',
            'mode' => 'unsupported_source_type',
            'query' => ContextExpansionRenderSupport::querySummary($objective, $workspace, 'dev', 'atlas', 'low'),
            'handle' => $handle,
            'expansion' => [
                'source_type' => (string) ($handle['source_type'] ?? ''),
                'selected_refs' => [],
                'excluded_refs' => [],
                'recommended_next_action' => 'Request a supported source type: evidence_replay, code_intelligence, memory_signals, vector_retrieval, test_symbols or canonical_doc.',
            ],
            'warnings' => [$reason],
            'policy' => ContextExpansionRenderSupport::policy(),
        ];
        $payload['expansion_hash'] = MissionCanonicalHash::sha256(ContextExpansionRenderSupport::stableForHash($payload));
        $payload['markdown'] = ContextExpansionRenderSupport::renderMarkdown($payload);

        return $payload;
    }
}
