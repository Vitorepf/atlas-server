<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainManifest;

class DomainRuntimeSelectionService
{
    /**
     * Heuristic v1: pick domain by keyword match across charter/name/domain_id.
     * Returns ranked list with score + reason. Primary is the highest scorer
     * unless score == 0, in which case primary is null and selection is "ambiguous".
     *
     * @return array<string,mixed>
     */
    public function select(string $objective, ?string $hintDomain = null): array
    {
        $manifests = AiDomainManifest::query()
            ->whereIn('status', ['active', 'scaffold'])
            ->get();

        if ($manifests->isEmpty()) {
            return [
                'ok' => false,
                'reason' => 'no_manifests_registered',
                'primary_domain' => null,
                'secondary_domains' => [],
                'ranked' => [],
            ];
        }

        $normalized = $this->normalize($objective);
        $tokens = $this->tokenize($normalized);

        $ranked = $manifests->map(function (AiDomainManifest $manifest) use ($tokens, $hintDomain): array {
            $haystack = $this->haystack($manifest);
            $score = 0;
            $matches = [];
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (str_contains($haystack, $token)) {
                    $score++;
                    $matches[] = $token;
                }
            }
            if ($hintDomain !== null && $manifest->domain_id === $hintDomain) {
                $score += 10;
                $matches[] = '__hint__';
            }

            return [
                'domain_id' => $manifest->domain_id,
                'name' => $manifest->name,
                'score' => $score,
                'maturity_stage' => $manifest->maturity_stage,
                'status' => $manifest->status,
                'matches' => array_values(array_unique($matches)),
            ];
        })->sortByDesc(fn (array $r): array => [$r['score'], $r['maturity_stage']])->values();

        $topScore = (int) ($ranked->first()['score'] ?? 0);

        if ($topScore === 0) {
            return [
                'ok' => true,
                'reason' => 'no_keyword_match',
                'primary_domain' => null,
                'secondary_domains' => [],
                'ranked' => $ranked->all(),
            ];
        }

        $primary = $ranked->first();
        $secondary = $ranked
            ->slice(1)
            ->filter(fn (array $r): bool => $r['score'] >= max(1, intdiv($topScore, 2)))
            ->take(3)
            ->values()
            ->all();

        return [
            'ok' => true,
            'reason' => $hintDomain !== null && $primary['domain_id'] === $hintDomain ? 'hint_match' : 'keyword_match',
            'primary_domain' => $primary['domain_id'],
            'secondary_domains' => array_column($secondary, 'domain_id'),
            'ranked' => $ranked->all(),
        ];
    }

    private function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($ascii === false) {
            $ascii = $lower;
        }

        return preg_replace('/[^a-z0-9\s_]/', ' ', $ascii) ?? $ascii;
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/\s+/', $text) ?: [];

        return array_values(array_filter($tokens, fn (string $t): bool => $t !== '' && mb_strlen($t) >= 3));
    }

    private function haystack(AiDomainManifest $manifest): string
    {
        $parts = [
            $manifest->domain_id,
            $manifest->name,
            (string) (($manifest->charter['mission'] ?? '')),
            implode(' ', (array) $manifest->departments),
            implode(' ', (array) $manifest->flow_profiles),
            implode(' ', (array) ($manifest->charter['outcomes'] ?? [])),
        ];

        return $this->normalize(implode(' ', $parts));
    }
}
