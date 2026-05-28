<?php

namespace App\Services\Ai\Programming;

class ProgrammingRetrievalEvaluator
{
    /**
     * @param  array<int,string>  $requiredSources
     * @param  array<string,mixed>  $contextPack
     * @param  array<string,mixed>  $gapCritic
     * @return array<string,mixed>
     */
    public function evaluate(array $requiredSources, array $contextPack, array $gapCritic): array
    {
        $rankedRefs = collect((array) ($contextPack['ranked_refs'] ?? []));
        $uniqueRequiredSources = array_values(array_unique($requiredSources));
        $requiredCovered = collect($uniqueRequiredSources)
            ->filter(fn (string $source): bool => $rankedRefs->contains('source', $source))
            ->count();

        $recallProxy = count($uniqueRequiredSources) > 0
            ? round($requiredCovered / count($uniqueRequiredSources), 4)
            : 1.0;
        $lowValueRefs = $rankedRefs->filter(fn (array $ref): bool => (float) ($ref['score'] ?? 0) < 0.35)->count();
        $contextWasteRatio = $rankedRefs->isEmpty() ? 0.0 : round($lowValueRefs / $rankedRefs->count(), 4);
        $precisionProxy = round(max(0.0, 1.0 - $contextWasteRatio), 4);

        return [
            'schema_version' => 'atlas.programming.retrieval_eval.v1',
            'evaluation_mode' => 'online_proxy',
            'status' => match (data_get($gapCritic, 'status')) {
                'passed' => 'passed',
                'degraded' => 'degraded',
                default => 'needs_review',
            },
            'recall_at_k_proxy' => $recallProxy,
            'precision_at_k_proxy' => $precisionProxy,
            'context_waste_ratio' => $contextWasteRatio,
            'source_coverage' => $contextPack['source_counts'] ?? [],
            'replayability' => [
                'context_pack_hash' => $contextPack['context_pack_hash'] ?? null,
                'ranked_ref_count' => $rankedRefs->count(),
                'provider_safe' => (bool) ($contextPack['provider_safe'] ?? false),
            ],
            'professional_promotion_allowed' => false,
            'promotion_blocker' => 'golden_set_retrieval_benchmark_not_attached',
        ];
    }
}
