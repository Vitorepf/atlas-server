<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain\Support;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Support\YesNo;

/**
 * Pure helpers for {@see \App\Services\Ai\AtlasOpenBrainContextExpansionService}
 * (provider-safe render peel): ref/symbol shaping, query summary, policy flags,
 * hash-stable payload trim, markdown render, next-action copy, source-type
 * aliases, scalar/list normalize, truncate, and objective-redacted excerpts.
 * No DB, no ranking, no context-pack I/O.
 */
final class ContextExpansionRenderSupport
{
    /**
     * @param  array<string,mixed>  $ref
     * @return array<string,mixed>
     */
    public static function providerSafeRef(array $ref): array
    {
        return array_filter([
            'source_type' => self::normalizeSourceType((string) ($ref['source_type'] ?? 'unknown')),
            'source_ref_hash' => self::scalarString($ref['source_ref_hash'] ?? null),
            'score_total' => ($score = AiValueNormalizer::finiteFloatOrNull($ref['score_total'] ?? null)) === null
                ? null
                : round($score, 4),
            'reasons' => self::stringList($ref['reasons'] ?? []),
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
            'schema_version' => self::scalarString($ref['schema_version'] ?? null),
            'reason' => self::scalarString($ref['reason'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    public static function providerSafeCodeSymbol(array $item): array
    {
        return array_filter([
            'id' => self::scalarString($item['id'] ?? null),
            'symbol_type' => self::scalarString($item['symbol_type'] ?? null),
            'file_path' => self::scalarString($item['file_path'] ?? null),
            'signature' => self::scalarString($item['signature'] ?? null),
            'tokens' => isset($item['tokens']) ? (int) $item['tokens'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<string,mixed>
     */
    public static function querySummary(string $objective, ?string $workspace, string $taskType, string $domain, string $risk): array
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
    public static function policy(): array
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
    public static function stableForHash(array $payload): array
    {
        unset($payload['expansion_hash'], $payload['markdown']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function renderMarkdown(array $payload): string
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
                .'; required_source_covered='.(YesNo::trueFalse($expansion['required_source_covered'] ?? false));
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
    public static function nextAction(array $handle, array $selected, bool $covered): string
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

    public static function normalizeSourceType(string $value): string
    {
        $value = str_replace('-', '_', strtolower(trim($value)));

        return match ($value) {
            'test', 'tests', 'test_method', 'test_methods' => 'test_symbols',
            'doc', 'docs', 'doc_heading', 'doc_headings', 'doc_symbols' => 'canonical_doc',
            default => $value,
        };
    }

    public static function scalarString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * @return array<int,string>
     */
    public static function stringList(mixed $value): array
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

    public static function truncate(string $value, int $budget): string
    {
        $limit = max(800, min($budget, 8000));
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'... [truncated]';
    }

    public static function providerSafeMarkdownExcerpt(string $markdown, string $objective, int $budget): string
    {
        $markdown = $objective !== ''
            ? str_replace($objective, '[objective redacted]', $markdown)
            : $markdown;

        return self::truncate($markdown, $budget);
    }
}
