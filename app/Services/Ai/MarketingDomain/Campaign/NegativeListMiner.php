<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingWinningPattern;

/**
 * NegativeListMiner — mines a negative-keyword list from the real Nivor performance data instead of
 * a hard-coded list. Low-CVR terms (spent clicks, few sales) become negative candidates; the words
 * that appear in low-CVR terms but never in high-CVR terms become n-gram negatives; plus the
 * always-on category negatives (free/cheap/jobs/diy/…). Deterministic. Feeds buildKeywordsPlan().
 */
class NegativeListMiner
{
    public const LOW_CVR = 0.02;       // below 2% click→sale

    public const MIN_CLICKS = 20;      // enough spend to judge

    public const HIGH_CVR = 0.10;      // a "winning" term

    /** Always-on category negatives for paid VSL traffic. */
    public const CATEGORY_NEGATIVES = ['free', 'gratis', 'grátis', 'cheap', 'recipe', 'receita', 'diy', 'job', 'jobs', 'salary', 'download', 'torrent', 'sample', 'pdf', 'reddit'];

    /**
     * @return array<string,mixed>
     */
    public function mine(?AiMarketingWinningPattern $pattern = null): array
    {
        $byCvr = $this->byCvr($pattern);

        $lowCandidates = [];
        $lowWords = [];
        $highWords = [];
        foreach ($byCvr as $row) {
            $term = strtolower((string) ($row['term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $cvr = (float) ($row['cvr'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);

            if ($cvr >= self::HIGH_CVR) {
                $highWords = array_merge($highWords, $this->words($term));
            }
            if ($cvr < self::LOW_CVR && $clicks >= self::MIN_CLICKS) {
                $lowCandidates[] = ['term' => $term, 'cvr' => round($cvr, 4), 'clicks' => $clicks];
                $lowWords = array_merge($lowWords, $this->words($term));
            }
        }

        // Protect any word that appears in a keyword that ACTUALLY SOLD (core niche terms).
        $protected = array_unique(array_merge($highWords, $this->convertingWords($pattern)));
        // words that drag CVR down and never appear in winners/converters
        $nGramNegatives = array_values(array_unique(array_filter(
            $lowWords,
            static fn (string $w): bool => ! in_array($w, $protected, true),
        )));

        return [
            'low_cvr_candidates' => $lowCandidates,
            'n_gram_negatives' => $nGramNegatives,
            'category_negatives' => self::CATEGORY_NEGATIVES,
            'combined' => array_values(array_unique(array_merge(self::CATEGORY_NEGATIVES, $nGramNegatives))),
            'source' => $pattern !== null ? 'nivor:'.$pattern->niche : 'category_only',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function byCvr(?AiMarketingWinningPattern $pattern): array
    {
        if ($pattern === null) {
            return [];
        }
        $kp = is_array($pattern->keyword_performance) ? $pattern->keyword_performance : [];
        $rows = $kp['by_cvr'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array<int,string>
     */
    private function convertingWords(?AiMarketingWinningPattern $pattern): array
    {
        if ($pattern === null) {
            return [];
        }
        $words = [];
        foreach ((array) $pattern->converting_keywords as $row) {
            $term = is_array($row) ? ($row['term'] ?? '') : $row;
            if (is_string($term) && $term !== '') {
                $words = array_merge($words, $this->words(strtolower($term)));
            }
        }

        return array_unique($words);
    }

    /**
     * @return array<int,string>
     */
    private function words(string $term): array
    {
        $parts = preg_split('/\s+/', trim($term)) ?: [];

        return array_values(array_filter($parts, static fn (string $w): bool => strlen($w) > 2));
    }
}
