<?php

namespace App\Services\Ai\Programming;

class ProgrammingProfessionalReranker
{
    /**
     * @param  array<int,array<string,mixed>>  $refs
     * @param  array<int,string>  $requiredSources
     * @return array{ranked_refs:array<int,array<string,mixed>>,excluded_refs:array<int,array<string,mixed>>,metrics:array<string,mixed>}
     */
    public function rerank(array $refs, array $requiredSources, string $flow, int $maxRefs): array
    {
        $deduped = [];
        $excluded = [];

        foreach ($refs as $ref) {
            if (! is_array($ref)) {
                continue;
            }

            $key = ($ref['source'] ?? 'unknown').'|'.($ref['ref'] ?? '');
            $ref['score'] = $this->score($ref, $requiredSources, $flow);
            $ref['scope'] = $ref['scope'] ?? $this->scope($ref, $flow);
            $ref['freshness'] = $ref['freshness'] ?? 'current';
            $ref['privacy'] = $ref['privacy'] ?? 'provider_safe';

            if (isset($deduped[$key])) {
                if ((float) $ref['score'] > (float) ($deduped[$key]['score'] ?? 0)) {
                    $deduped[$key] = array_merge($deduped[$key], $ref, [
                        'reason' => $deduped[$key]['reason'].'+'.$ref['reason'],
                    ]);
                }

                $excluded[] = [
                    'ref' => (string) ($ref['ref'] ?? ''),
                    'reason' => 'duplicate',
                    'source' => (string) ($ref['source'] ?? 'unknown'),
                ];

                continue;
            }

            $deduped[$key] = $ref;
        }

        $ranked = collect(array_values($deduped))
            ->sortByDesc('score')
            ->take(max(1, $maxRefs))
            ->values()
            ->all();

        foreach (array_slice(collect(array_values($deduped))->sortByDesc('score')->values()->all(), count($ranked)) as $ref) {
            $excluded[] = [
                'ref' => (string) ($ref['ref'] ?? ''),
                'reason' => 'budget_trimmed',
                'source' => (string) ($ref['source'] ?? 'unknown'),
            ];
        }

        $scores = collect($ranked)->pluck('score')->map(fn ($score): float => (float) $score);

        return [
            'ranked_refs' => $ranked,
            'excluded_refs' => $excluded,
            'metrics' => [
                'reranker' => 'deterministic_professional_v1',
                'required_source_coverage' => collect($requiredSources)
                    ->mapWithKeys(fn (string $source): array => [$source => collect($ranked)->contains('source', $source)])
                    ->all(),
                'score_min' => $scores->isEmpty() ? 0.0 : $scores->min(),
                'score_max' => $scores->isEmpty() ? 0.0 : $scores->max(),
                'score_avg' => $scores->isEmpty() ? 0.0 : round($scores->avg(), 4),
                'fallback_used' => collect($ranked)->doesntContain('retrieval_channel', 'local_semantic_vector'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    private function score(array $ref, array $requiredSources, string $flow): float
    {
        $base = (float) ($ref['score'] ?? 0.5);
        if ($base > 10.0) {
            $base = $base / 100;
        }
        if (($ref['reason'] ?? null) === 'code_intelligence_symbol_match' && ($ref['retrieval_channel'] ?? null) !== 'local_semantic_vector') {
            $base = min($base, 0.65);
        }

        $source = (string) ($ref['source'] ?? 'unknown');
        $path = (string) ($ref['ref'] ?? '');
        $bonus = in_array($source, $requiredSources, true) ? 0.12 : 0.0;
        $bonus += $source === 'stage_receipts' && in_array($flow, ['programming.repair', 'programming.forge'], true) ? 0.08 : 0.0;
        $bonus += (($ref['retrieval_channel'] ?? null) === 'local_semantic_vector') ? 0.22 : 0.0;
        $bonus += (($ref['retrieval_channel'] ?? null) === 'professional_companion_expansion') ? 0.18 : 0.0;
        $bonus += (($ref['retrieval_channel'] ?? null) === 'audited_empty_source') ? 0.35 : 0.0;
        $bonus += $this->programmingRelevanceBoost($path);

        return round(max(0.0, min(2.0, $base + $bonus + $this->noisePenalty($path))), 4);
    }

    private function programmingRelevanceBoost(string $path): float
    {
        if (str_starts_with($path, 'app/Services/Ai/Programming/')
            || str_starts_with($path, 'tests/Unit/Ai/Programming/')
            || str_starts_with($path, 'tests/Unit/Ai/AtlasProgramming')
            || str_starts_with($path, 'docs/engineering-knowledge-base/domains/programming')
        ) {
            return 0.22;
        }

        return 0.0;
    }

    private function noisePenalty(string $path): float
    {
        if (str_contains($path, '/self-construction/')
            || str_contains($path, 'professionalize_bitacula')
            || str_contains($path, 'add_professional_fields')
            || str_contains($path, 'CaptureInbox')
            || str_contains($path, 'ProviderRelease')
        ) {
            return -0.45;
        }

        return 0.0;
    }

    /**
     * @param  array<string,mixed>  $ref
     */
    private function scope(array $ref, string $flow): string
    {
        $source = (string) ($ref['source'] ?? '');

        return match ($source) {
            'related_tests' => 'test',
            'canonical_docs' => 'review',
            'stage_receipts', 'known_failures' => 'repair',
            default => str_contains($flow, 'review') ? 'review' : 'patch',
        };
    }
}
