<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * RAGX-08 — deterministic claim/span/content-version resolver.
 *
 * This does not retrieve new content and never calls a model. It slices the
 * provider-safe items already delivered in the pack into citeable spans, so a
 * response can cite the exact content version behind a claim.
 */
final class SpanLevelRetrievalResolver
{
    public const SCHEMA_VERSION = 'atlas.aobg.span_level_retrieval.v1';

    public const FORMULA_VERSION = 'atlas.ragx_08.span_level_refs.v1';

    private const MIN_TOKEN_OVERLAP = 2;

    /** @var array<string,true> */
    private const STOP_TOKENS = [
        'the' => true,
        'and' => true,
        'for' => true,
        'that' => true,
        'this' => true,
        'must' => true,
        'should' => true,
        'only' => true,
        'with' => true,
        'sem' => true,
        'com' => true,
        'para' => true,
        'deve' => true,
        'não' => true,
        'nao' => true,
    ];

    /**
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public function resolve(array $pack, int $maxSpansPerClaim = 1): array
    {
        $claims = $this->claimTexts($pack);
        $candidates = $this->candidates($pack);
        $maxSpansPerClaim = max(1, $maxSpansPerClaim);

        $empty = [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'present' => false,
            'claims' => [],
            'source' => $this->sourceMeta(),
        ];

        if ($claims === []) {
            return $empty;
        }

        $resolvedClaims = [];
        foreach ($claims as $claim) {
            $spans = $this->rankedSpansForClaim($claim, $candidates, $maxSpansPerClaim);
            $resolvedClaims[] = [
                'claim' => $claim,
                'claim_hash' => hash('sha256', $claim),
                'status' => $spans === [] ? 'unresolved' : 'resolved',
                'spans' => $spans,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'present' => true,
            'claims' => $resolvedClaims,
            'source' => $this->sourceMeta(),
        ];
    }

    /** @param array<string,mixed> $pack */
    private function claimTexts(array $pack): array
    {
        $claims = [];
        foreach ((array) data_get($pack, 'retrieval_agenda.claims', []) as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $text = trim((string) ($claim['claim'] ?? ''));
            if ($text !== '') {
                $claims[] = $text;
            }
        }

        return AtlasCanonicalContextRef::uniqueStrings($claims);
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return list<array{parent_ref:string,source_type:string,text:string,content_version:string}>
     */
    private function candidates(array $pack): array
    {
        $out = [];

        foreach ((array) ($pack['memory'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->providerText([
                (string) ($item['title'] ?? ''),
                (string) ($item['summary'] ?? ''),
                (string) ($item['body'] ?? ''),
            ]);
            if ($text === '') {
                continue;
            }
            $out[] = $this->candidate(AtlasCanonicalContextRef::fromMemoryItem($item), 'memory', $text);
        }

        foreach ((array) ($pack['code_graph'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->providerText([
                (string) ($item['id'] ?? ''),
                (string) ($item['signature'] ?? ''),
                (string) ($item['file_path'] ?? ''),
            ]);
            if ($text === '') {
                continue;
            }
            $out[] = $this->candidate(AtlasCanonicalContextRef::fromCodeItem($item), 'code', $text);
        }

        foreach ((array) ($pack['reality_graph_paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }
            $labels = [];
            foreach ((array) ($path['chain'] ?? []) as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $labels[] = (string) (($node['label'] ?? '') !== '' ? $node['label'] : ($node['id'] ?? ''));
            }
            $text = $this->providerText($labels);
            if ($text === '') {
                continue;
            }
            $out[] = $this->candidate(AtlasCanonicalContextRef::fromGraphPath($path), 'graph', $text);
        }

        return $out;
    }

    /** @param list<string> $parts */
    private function providerText(array $parts): string
    {
        return trim(implode("\n", array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        ))));
    }

    /**
     * @return array{parent_ref:string,source_type:string,text:string,content_version:string}
     */
    private function candidate(string $parentRef, string $sourceType, string $text): array
    {
        return [
            'parent_ref' => $parentRef,
            'source_type' => $sourceType,
            'text' => $text,
            'content_version' => hash('sha256', $text),
        ];
    }

    /**
     * @param  list<array{parent_ref:string,source_type:string,text:string,content_version:string}>  $candidates
     * @return list<array<string,mixed>>
     */
    private function rankedSpansForClaim(string $claim, array $candidates, int $limit): array
    {
        $claimTokens = $this->tokens($claim);
        if ($claimTokens === []) {
            return [];
        }

        $matches = [];
        foreach ($candidates as $candidate) {
            foreach ($this->spans((string) $candidate['text']) as $span) {
                $overlap = $this->overlapCount($claimTokens, $this->tokens($span['text']));
                if ($overlap < self::MIN_TOKEN_OVERLAP) {
                    continue;
                }
                $score = round($overlap / max(1, count($claimTokens)), 4);
                $matches[] = [
                    'span_ref' => AtlasCanonicalContextRef::spanRef(
                        (string) $candidate['parent_ref'],
                        (string) $candidate['content_version'],
                        (int) $span['start'],
                        (int) $span['end'],
                    ),
                    'parent_ref' => (string) $candidate['parent_ref'],
                    'source_type' => (string) $candidate['source_type'],
                    'content_version' => (string) $candidate['content_version'],
                    'start' => (int) $span['start'],
                    'end' => (int) $span['end'],
                    'span_hash' => hash('sha256', (string) $span['text']),
                    'span_excerpt' => (string) $span['text'],
                    'score' => $score,
                ];
            }
        }

        usort($matches, static fn (array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: strcmp((string) $a['parent_ref'], (string) $b['parent_ref'])
            ?: ((int) $a['start'] <=> (int) $b['start']));

        return array_slice($matches, 0, $limit);
    }

    /**
     * @return list<array{text:string,start:int,end:int}>
     */
    private function spans(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $spans = [];
        foreach ($parts as $part) {
            if (! is_array($part) || count($part) < 2) {
                continue;
            }
            $spanText = trim((string) $part[0]);
            if ($spanText === '') {
                continue;
            }
            $start = (int) $part[1];
            $spans[] = [
                'text' => $spanText,
                'start' => $start,
                'end' => $start + strlen($spanText),
            ];
        }

        if ($spans === []) {
            return [[
                'text' => $text,
                'start' => 0,
                'end' => strlen($text),
            ]];
        }

        return $spans;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\pL\pN][\pL\pN._-]{2,}/u', mb_strtolower($text), $matches);
        $tokens = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = trim((string) $token, '._-');
            if ($token === '' || isset(self::STOP_TOKENS[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }

        return array_keys($tokens);
    }

    /** @param list<string> $spanTokens */
    private function overlapCount(array $claimTokens, array $spanTokens): int
    {
        $spanSet = array_fill_keys($spanTokens, true);
        $overlap = 0;
        foreach ($claimTokens as $token) {
            if (isset($spanSet[$token])) {
                $overlap++;
            }
        }

        return $overlap;
    }

    /** @return array<string,mixed> */
    private function sourceMeta(): array
    {
        return [
            'llm_used' => false,
            'db_touched' => false,
            'record_usage' => false,
            'fabricates_spans' => false,
            'uses_delivered_pack_only' => true,
            'flag' => 'atlas.aobg.span_level_retrieval',
            'flag_default' => 'off',
        ];
    }
}
