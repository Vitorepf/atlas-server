<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * KeywordIntentMapper — the keyword-intent-mapper skill. Maps each extracted keyword cluster to the
 * bridge lead it deserves (intent bucket → Great-Leads lead via the playbook), scores it against the
 * Nivor proven keywords (clusters that already SOLD rank first), and hints broad-vs-exact by intent.
 * Deterministic. Replaces the static awareness-only sort in CampaignBlueprintService::buildKeywordsPlan().
 */
class KeywordIntentMapper
{
    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @param  array<int,array<string,mixed>>  $clusters
     * @return array<int,array<string,mixed>>
     */
    public function map(array $clusters, ?AiMarketingWinningPattern $pattern = null): array
    {
        $proven = $this->provenTerms($pattern);
        $buckets = $this->playbook->keywordIntentBuckets();

        $mapped = [];
        foreach ($clusters as $i => $c) {
            if (! is_array($c)) {
                continue;
            }
            $terms = array_values(array_filter((array) ($c['terms'] ?? []), 'is_string'));
            $bucketKey = $this->classify($c, $terms);
            $bucket = $buckets[$bucketKey] ?? ['intent' => 'unknown', 'bridge_lead' => ''];
            $route = $this->playbook->routeAwareness((string) ($c['awareness'] ?? ''));
            $provenHits = $this->countProven($terms, $proven);

            $mapped[] = [
                'name' => (string) ($c['name'] ?? ('ad_group_'.($i + 1))),
                'awareness' => $c['awareness'] ?? null,
                'intent_bucket' => $bucketKey,
                'intent' => $bucket['intent'],
                'bridge_lead' => $bucket['bridge_lead'],
                'recommended_lead' => $route['lead_type'],
                'match_type_hint' => $this->matchHint($bucketKey),
                'terms' => $terms,
                'proven_hits' => $provenHits,
                'priority_score' => $provenHits * 10 + $this->intentRank($bucketKey),
            ];
        }

        // Proven sellers first, then highest-intent.
        usort($mapped, static fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return $mapped;
    }

    /**
     * @param  array<string,mixed>  $cluster
     * @param  array<int,string>  $terms
     */
    private function classify(array $cluster, array $terms): string
    {
        $blob = strtolower(implode(' ', $terms).' '.(string) ($cluster['intent'] ?? '').' '.(string) ($cluster['name'] ?? '')); // search the cluster

        return match (true) {
            str_contains($blob, 'official') || str_contains($blob, 'brand') || str_contains($blob, 'site') => 'brand_official',
            str_contains($blob, 'review') || str_contains($blob, 'avalia') || str_contains($blob, 'resenha') => 'review',
            str_contains($blob, 'scam') || str_contains($blob, 'complaint') || str_contains($blob, 'golpe') || str_contains($blob, 'funciona') => 'scam_complaint',
            str_contains($blob, 'how to') || str_contains($blob, 'symptom') || str_contains($blob, 'why ') || str_contains($blob, 'como ') => 'problem_symptom',
            default => 'solution_category',
        };
    }

    private function matchHint(string $bucket): string
    {
        return in_array($bucket, ['brand_official', 'review', 'scam_complaint'], true)
            ? 'exact/phrase (alta intenção — controlar)'
            : 'broad + Smart Bidding (descoberta com conversão medida)';
    }

    private function intentRank(string $bucket): int
    {
        return [
            'brand_official' => 5,
            'review' => 4,
            'scam_complaint' => 3,
            'solution_category' => 2,
            'problem_symptom' => 1,
        ][$bucket] ?? 0;
    }

    /**
     * @return array<int,string>
     */
    private function provenTerms(?AiMarketingWinningPattern $pattern): array
    {
        if ($pattern === null) {
            return [];
        }
        $out = [];
        foreach ((array) $pattern->converting_keywords as $row) {
            $term = is_array($row) ? ($row['term'] ?? null) : $row;
            if (is_string($term) && $term !== '') {
                $out[] = strtolower($term);
            }
        }

        return $out;
    }

    /**
     * @param  array<int,string>  $terms
     * @param  array<int,string>  $proven
     */
    private function countProven(array $terms, array $proven): int
    {
        if ($proven === []) {
            return 0;
        }
        $n = 0;
        foreach ($terms as $t) {
            $t = strtolower($t);
            foreach ($proven as $p) {
                if ($t === $p || str_contains($p, $t) || str_contains($t, $p)) {
                    $n++;
                    break;
                }
            }
        }

        return $n;
    }
}
