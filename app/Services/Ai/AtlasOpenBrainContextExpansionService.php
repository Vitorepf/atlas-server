<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use App\Services\Ai\Mission\MissionCanonicalHash;

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
        $workspace = $this->scalarString($input['workspace'] ?? null);
        $taskType = $this->scalarString($input['task_type'] ?? null, 'dev');
        $domain = $this->scalarString($input['domain'] ?? null, 'atlas');
        $risk = $this->scalarString($input['risk_level'] ?? $input['risk'] ?? null, 'low');
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
            'query' => $this->querySummary($objective, $workspace, $taskType, $domain, $risk),
            'handle' => $handle,
            'policy' => $this->policy(),
        ];
        $payload['expansion_hash'] = MissionCanonicalHash::sha256($this->stableForHash($payload));
        $payload['markdown'] = $this->renderMarkdown($payload);

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
            'source_type' => $this->normalizeSourceType($sourceType),
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
                'recommended_next_action' => $this->nextAction($handle, $selected, $covered),
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
                'markdown_excerpt' => $this->providerSafeMarkdownExcerpt((string) ($pack['markdown'] ?? ''), $objective, $budget),
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
            ->map(fn (array $item): array => $this->providerSafeCodeSymbol($item))
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
            ->map(fn (array $ref): array => $this->providerSafeRef($ref))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $ref
     * @return array<string,mixed>
     */
    private function providerSafeRef(array $ref): array
    {
        return array_filter([
            'source_type' => $this->normalizeSourceType((string) ($ref['source_type'] ?? 'unknown')),
            'source_ref_hash' => $this->scalarString($ref['source_ref_hash'] ?? null),
            'score_total' => isset($ref['score_total']) ? round((float) $ref['score_total'], 4) : null,
            'reasons' => $this->stringList($ref['reasons'] ?? []),
            'score_components' => is_array($ref['score_components'] ?? null)
                ? array_intersect_key((array) $ref['score_components'], array_flip([
                    'schema_version',
                    'semantic',
                    'professional_rerank',
                    'authority',
                    'freshness',
                    'graph',
                    'privacy',
                    'feedback_hint_delta',
                ]))
                : null,
            'schema_version' => $this->scalarString($ref['schema_version'] ?? null),
            'reason' => $this->scalarString($ref['reason'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function providerSafeCodeSymbol(array $item): array
    {
        return array_filter([
            'id' => $this->scalarString($item['id'] ?? null),
            'symbol_type' => $this->scalarString($item['symbol_type'] ?? null),
            'file_path' => $this->scalarString($item['file_path'] ?? null),
            'signature' => $this->scalarString($item['signature'] ?? null),
            'tokens' => isset($item['tokens']) ? (int) $item['tokens'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
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
            'query' => $this->querySummary($objective, $workspace, 'dev', 'atlas', 'low'),
            'handle' => $handle,
            'expansion' => [
                'source_type' => (string) ($handle['source_type'] ?? ''),
                'selected_refs' => [],
                'excluded_refs' => [],
                'recommended_next_action' => 'Request a supported source type: evidence_replay, code_intelligence, memory_signals, vector_retrieval, test_symbols or canonical_doc.',
            ],
            'warnings' => [$reason],
            'policy' => $this->policy(),
        ];
        $payload['expansion_hash'] = MissionCanonicalHash::sha256($this->stableForHash($payload));
        $payload['markdown'] = $this->renderMarkdown($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function querySummary(string $objective, ?string $workspace, string $taskType, string $domain, string $risk): array
    {
        return [
            'objective_hash' => MissionCanonicalHash::sha256($objective),
            'objective_length' => mb_strlen($objective),
            'workspace_hash' => $workspace !== null ? MissionCanonicalHash::sha256($workspace) : null,
            'workspace_label' => $workspace !== null ? basename($workspace) : null,
            'task_type' => $taskType,
            'domain' => $domain,
            'risk_level' => $risk,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function policy(): array
    {
        return [
            'provider_safe_only' => true,
            'raw_text_exposed' => false,
            'raw_docs_dumped' => false,
            'raw_tests_dumped' => false,
            'providers_invoked' => false,
            'writes' => false,
            'advisory_only' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stableForHash(array $payload): array
    {
        unset($payload['expansion_hash'], $payload['markdown']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderMarkdown(array $payload): string
    {
        $lines = [
            '# Atlas Open Brain Context Expansion',
            '- status: '.(string) ($payload['status'] ?? 'unknown'),
            '- mode: '.(string) ($payload['mode'] ?? 'unknown'),
            '- handle: '.(string) data_get($payload, 'handle.id', ''),
            '- source_type: '.(string) data_get($payload, 'handle.source_type', 'unknown'),
            '- policy: provider_safe_only=true; raw_text_exposed=false; raw_docs_dumped=false; raw_tests_dumped=false; providers_invoked=false; writes=false',
        ];

        $warnings = (array) ($payload['warnings'] ?? []);
        if ($warnings !== []) {
            $lines[] = '- warnings: '.implode(', ', array_map('strval', $warnings));
        }

        $expansion = (array) ($payload['expansion'] ?? []);
        if (is_array($expansion['counts'] ?? null)) {
            $counts = (array) $expansion['counts'];
            $lines[] = '- counts: code_graph='.(int) ($counts['code_graph'] ?? 0)
                .'; reality_graph_paths='.(int) ($counts['reality_graph_paths'] ?? 0)
                .'; memory='.(int) ($counts['memory'] ?? 0);
        }
        if (isset($expansion['selected_ref_count'])) {
            $lines[] = '- selected_refs: '.(int) $expansion['selected_ref_count']
                .'; excluded_refs='.(int) ($expansion['excluded_ref_count'] ?? 0)
                .'; required_source_covered='.(($expansion['required_source_covered'] ?? false) ? 'true' : 'false');
        }
        if (isset($expansion['selected_symbol_count'])) {
            $lines[] = '- selected_symbols: '.(int) $expansion['selected_symbol_count'];
            foreach (array_slice((array) ($expansion['selected_symbols'] ?? []), 0, 6) as $symbol) {
                if (! is_array($symbol)) {
                    continue;
                }
                $lines[] = '- '.(string) ($symbol['id'] ?? '')
                    .' ['.(string) ($symbol['file_path'] ?? 'n/a').']'
                    .' type='.(string) ($symbol['symbol_type'] ?? 'n/a');
            }
        }
        if (is_string($expansion['recommended_next_action'] ?? null)) {
            $lines[] = '- next: '.$expansion['recommended_next_action'];
        }
        if (is_string($expansion['markdown_excerpt'] ?? null) && $expansion['markdown_excerpt'] !== '') {
            $lines[] = '';
            $lines[] = '## Compact Pack Excerpt';
            $lines[] = $expansion['markdown_excerpt'];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,string>  $handle
     * @param  array<int,array<string,mixed>>  $selected
     */
    private function nextAction(array $handle, array $selected, bool $covered): string
    {
        if ((string) ($handle['action'] ?? '') === 'recheck') {
            return $covered
                ? 'Use these provider-safe refs as the required source recheck before implementation.'
                : 'Escalate before implementation; the requested required source was not covered.';
        }

        return $selected !== []
            ? 'Use these refs as targeted expansion context and request file-context only for touched files.'
            : 'Request a broader context pack or a specific file-context because no refs matched this source.';
    }

    private function normalizeSourceType(string $value): string
    {
        $value = str_replace('-', '_', strtolower(trim($value)));

        return match ($value) {
            'test', 'tests', 'test_method', 'test_methods' => 'test_symbols',
            'doc', 'docs', 'doc_heading', 'doc_headings', 'doc_symbols' => 'canonical_doc',
            default => $value,
        };
    }

    private function scalarString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(static fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->take(12)
            ->all();
    }

    private function truncate(string $value, int $budget): string
    {
        $limit = max(800, min($budget, 8000));
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'... [truncated]';
    }

    private function providerSafeMarkdownExcerpt(string $markdown, string $objective, int $budget): string
    {
        $markdown = $objective !== ''
            ? str_replace($objective, '[objective redacted]', $markdown)
            : $markdown;

        return $this->truncate($markdown, $budget);
    }
}
