<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * Deterministic cross-source Reciprocal Rank Fusion.
 *
 * Source adapters keep ownership of retrieval and privacy. This module only
 * compares their already provider-safe ranked outputs on a scale-independent
 * rank signal; it never invents relevance or calls a provider.
 */
final class AtlasRetrievalFusionService
{
    public const SCHEMA_VERSION = 'atlas.retrieval_fusion.v1';

    /**
     * @param  list<array<string,mixed>>  $code
     * @param  list<array<string,mixed>>  $memory
     * @param  list<array<string,mixed>>  $realityPaths
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function fuse(
        array $code,
        array $memory,
        array $realityPaths,
        array $options = [],
    ): array {
        $limit = max(1, (int) ($options['limit'] ?? 12));
        $rrfK = max(1, (int) ($options['rrf_k'] ?? 60));
        $weights = array_merge([
            'code' => 1.0,
            'memory' => 1.0,
            'reality' => 1.0,
        ], is_array($options['weights'] ?? null) ? $options['weights'] : []);

        $candidates = [
            ...$this->codeCandidates($code, (float) $weights['code'], $rrfK),
            ...$this->memoryCandidates($memory, (float) $weights['memory'], $rrfK),
            ...$this->realityCandidates($realityPaths, (float) $weights['reality'], $rrfK),
        ];
        usort($candidates, static fn (array $left, array $right): int =>
            ($right['fused_score'] <=> $left['fused_score'])
            ?: strcmp($left['source'], $right['source'])
            ?: strcmp($left['ref'], $right['ref'])
        );
        $candidates = array_slice($candidates, 0, $limit);
        $sourceCounts = [
            'code' => count($code),
            'memory' => count($memory),
            'reality' => count($realityPaths),
        ];
        $canonical = [
            'schema_version' => self::SCHEMA_VERSION,
            'algorithm' => 'reciprocal_rank_fusion',
            'rrf_k' => $rrfK,
            'source_counts' => $sourceCounts,
            'candidates' => $candidates,
        ];

        return $canonical + [
            'status' => $candidates === [] ? 'empty' : 'ready',
            'fusion_hash' => hash(
                'sha256',
                json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ),
            'provider_safe' => true,
            'providers_invoked' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function codeCandidates(array $items, float $weight, int $rrfK): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            $ref = trim((string) ($item['id'] ?? ''));
            if ($ref === '') {
                continue;
            }
            $out[] = $this->candidate(
                'code',
                $ref,
                (string) ($item['file_path'] ?? $item['symbol_name'] ?? $ref),
                $index + 1,
                $weight,
                $rrfK,
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function memoryCandidates(array $items, float $weight, int $rrfK): array
    {
        $out = [];
        foreach ($items as $index => $item) {
            $ref = trim((string) ($item['id'] ?? $item['content_hash'] ?? ''));
            $label = trim((string) ($item['title'] ?? ''));
            if ($ref === '' && $label === '') {
                continue;
            }
            $out[] = $this->candidate(
                'memory',
                $ref !== '' ? $ref : hash('sha256', $label),
                $label !== '' ? $label : 'memory',
                $index + 1,
                $weight,
                $rrfK,
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $paths
     * @return list<array<string,mixed>>
     */
    private function realityCandidates(array $paths, float $weight, int $rrfK): array
    {
        $out = [];
        foreach ($paths as $index => $path) {
            $chain = array_values(array_filter((array) ($path['chain'] ?? []), 'is_array'));
            $ids = array_values(array_filter(array_map(
                static fn (array $node): string => trim((string) ($node['id'] ?? '')),
                $chain,
            )));
            if ($ids === []) {
                continue;
            }
            $labels = array_values(array_filter(array_map(
                static fn (array $node): string => trim((string) ($node['label'] ?? '')),
                $chain,
            )));
            $out[] = $this->candidate(
                'reality',
                implode('>', $ids),
                $labels !== [] ? implode(' -> ', $labels) : 'cross-layer path',
                $index + 1,
                $weight,
                $rrfK,
            );
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(
        string $source,
        string $ref,
        string $label,
        int $rank,
        float $weight,
        int $rrfK,
    ): array {
        return [
            'source' => $source,
            'ref' => $ref,
            'label' => $label,
            'source_rank' => $rank,
            'fused_score' => round(max(0.0, $weight) / ($rrfK + $rank), 8),
        ];
    }
}
