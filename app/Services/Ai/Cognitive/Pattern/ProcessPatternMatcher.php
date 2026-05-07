<?php

namespace App\Services\Ai\Cognitive\Pattern;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class ProcessPatternMatcher
{
    public function __construct(
        private readonly ProcessPatternRepository $patterns,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function match(string $problemDescription, ?string $category = null, int $limit = 5): array
    {
        return $this->slo->measure('cognitive.process_pattern.matcher', function () use ($problemDescription, $category, $limit): array {
            $tokens = $this->tokens($problemDescription);
            $matches = collect($this->patterns->catalog($category))
                ->map(function (array $pattern) use ($tokens, $problemDescription): array {
                    $haystack = strtolower(implode(' ', [
                        $pattern['name'] ?? '',
                        $pattern['category'] ?? '',
                        $pattern['intent'] ?? '',
                        $pattern['problem_context'] ?? '',
                        json_encode($pattern['forces'] ?? []),
                        json_encode($pattern['solution'] ?? []),
                    ]));
                    $overlap = collect($tokens)->filter(fn (string $token): bool => str_contains($haystack, $token))->count();
                    $metricBoost = (float) data_get($pattern, 'metrics.success_rate', 0.0);

                    return [
                        'pattern' => $pattern,
                        'score' => round(($overlap * 0.2) + $metricBoost, 3),
                        'matched_tokens' => $overlap,
                        'why' => $overlap > 0
                            ? 'problem_tokens_overlap_pattern_context'
                            : 'catalog_fallback_ranked_by_evidence',
                        'problem_hash' => hash('sha256', $problemDescription),
                    ];
                })
                ->sortByDesc('score')
                ->take(max(1, $limit))
                ->values()
                ->all();

            return [
                'schema_version' => 'atlas.cognitive.process_pattern_matcher.v1',
                'status' => $matches === [] ? 'missing' : 'ok',
                'problem_hash' => hash('sha256', $problemDescription),
                'category' => $category,
                'matches' => $matches,
            ];
        }, [
            'domain' => 'learning',
            'category' => (string) $category,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(string $text): array
    {
        return str($text)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->explode(' ')
            ->filter(fn (string $token): bool => strlen($token) >= 4)
            ->unique()
            ->values()
            ->all();
    }
}
