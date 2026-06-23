<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * SearchTermWasteMiner — mines the Google Ads SEARCH-TERMS REPORT (the real queries that triggered the
 * ads) with n-gram analysis to surface waste and emit negative keywords BEFORE more money burns. Two
 * signals: (1) lexical — n-grams in the waste lexicons (informational/free/jobs/retail/media/competitor)
 * or queries with NO owned root are unqualified by construction; (2) performance — when metrics are
 * present, an n-gram that accumulated cost with ZERO conversions across multiple terms is a proven
 * money-leak. Complements NegativeListMiner (which mines Nivor proven-loser terms) and closes the
 * Keyword OS loop: the campaign keeps cutting waste as new search terms appear. Pure PHP.
 */
class SearchTermWasteMiner
{
    private const KILL = [
        'informational' => ['what is', 'how to', 'why', 'symptoms', 'causes', 'meaning', 'definition', 'guide', 'tutorial', 'wiki'],
        'free' => ['free', 'cheap', 'cheapest', 'discount', 'coupon', 'sample', 'trial', 'torrent', 'download'],
        'jobs' => ['jobs', 'job', 'careers', 'salary', 'hiring', 'course', 'training', 'degree'],
        'retail' => ['amazon', 'walmart', 'ebay', 'gnc', 'cvs', 'walgreens', 'costco', 'near me', 'in stores'],
        'media' => ['meme', 'joke', 'picture', 'photo', 'image', 'lyrics', 'net worth', 'shirt', 'news', 'reddit'],
    ];

    private const STOP = ['the', 'a', 'an', 'to', 'for', 'of', 'and', 'or', 'with', 'in', 'on', 'is', 'it', 'my', 'i', 'how', 'best'];

    /** Tokens too generic to count as an "owned root" hit. */
    private const GENERIC_TOKENS = ['weight', 'loss', 'lose', 'diet', 'fat', 'belly', 'slim', 'burn', 'drops', 'protocol', 'natural', 'reviews', 'review'];

    /**
     * @param  array<int,string|array<string,mixed>>  $searchTerms  string or {term,clicks,cost,conversions}
     * @param  array<string,mixed>  $artifacts  QualifiedKeywordPatternEngine artifacts (owned roots)
     * @param  array<string,mixed>  $opts  cost_threshold, product_name
     * @return array{negatives:array<int,array<string,mixed>>,no_owned_root:array<int,string>,waste_cost:float,summary:array<string,mixed>}
     */
    public function mine(array $searchTerms, array $artifacts = [], array $opts = []): array
    {
        $threshold = (float) ($opts['cost_threshold'] ?? 20.0);
        $product = mb_strtolower((string) ($opts['product_name'] ?? ($artifacts['product_name'] ?? '')));
        $ownedTokens = $this->ownedRootTokens($artifacts);

        $rows = array_map(fn ($t) => $this->normalize($t), $searchTerms);

        $negatives = [];
        $noRoot = [];
        $wasteCost = 0.0;
        $seen = [];

        // Per-term lexical kills + owned-root presence.
        foreach ($rows as $r) {
            $term = $r['term'];
            if ($term === '') {
                continue;
            }
            $lk = $this->lexicalKill($term, $product);
            if ($lk !== null) {
                $key = $lk['ngram'];
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $negatives[] = $lk + ['cost' => $r['cost'], 'evidence' => [$term]];
                } else {
                    $wasteCost += $r['cost'];
                }
                $wasteCost += $r['cost'];

                continue;
            }
            if ($ownedTokens !== [] && ! $this->carriesOwnedRoot($term, $ownedTokens)) {
                $noRoot[] = $term;
            }
        }

        // N-gram performance mining (only meaningful when metrics exist): cost > 0, conversions == 0.
        // SAFETY: never emit a single-word negative, and never a negative that co-occurs with an
        // owned-root or converting term — that would block our own best keywords.
        $grams = $this->aggregateNgrams($rows, $ownedTokens);
        foreach ($grams as $g) {
            if ($g['cost'] >= $threshold && $g['conversions'] === 0 && $g['n_terms'] >= 2
                && ! $g['protected'] && str_word_count($g['ngram']) >= 2) {
                $key = $g['ngram'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $negatives[] = [
                    'ngram' => $g['ngram'],
                    'match' => str_word_count($g['ngram']) > 1 ? 'phrase' : 'exact',
                    'reason' => 'zero_conversions_with_cost',
                    'category' => 'performance',
                    'cost' => round($g['cost'], 2),
                    'n_terms' => $g['n_terms'],
                    'evidence' => array_slice($g['terms'], 0, 4),
                ];
                $wasteCost += $g['cost'];
            }
        }

        usort($negatives, fn ($a, $b) => ($b['cost'] ?? 0) <=> ($a['cost'] ?? 0));

        return [
            'negatives' => $negatives,
            'no_owned_root' => array_values(array_unique($noRoot)),
            'waste_cost' => round($wasteCost, 2),
            'summary' => [
                'terms_analyzed' => count($rows),
                'negatives_found' => count($negatives),
                'unqualified_no_root' => count(array_unique($noRoot)),
            ],
        ];
    }

    /**
     * @param  string|array<string,mixed>  $t
     * @return array{term:string,clicks:int,cost:float,conversions:int}
     */
    private function normalize(string|array $t): array
    {
        if (is_string($t)) {
            return ['term' => mb_strtolower(trim($t)), 'clicks' => 0, 'cost' => 0.0, 'conversions' => 0];
        }

        return [
            'term' => mb_strtolower(trim((string) ($t['term'] ?? ''))),
            'clicks' => (int) ($t['clicks'] ?? 0),
            'cost' => (float) ($t['cost'] ?? 0),
            'conversions' => (int) ($t['conversions'] ?? 0),
        ];
    }

    /**
     * @return array{ngram:string,match:string,reason:string,category:string}|null
     */
    private function lexicalKill(string $term, string $product): ?array
    {
        $t = ' '.$term.' ';
        if ($product !== '' && str_contains($t, $product)) {
            return ['ngram' => $product, 'match' => 'phrase', 'reason' => 'product_name', 'category' => 'lexical'];
        }
        foreach (self::KILL as $cat => $words) {
            foreach ($words as $w) {
                if (str_contains($t, ' '.$w.' ') || str_contains($t, ' '.$w) || str_contains($term, $w.' ')) {
                    return ['ngram' => $w, 'match' => str_word_count($w) > 1 ? 'phrase' : 'exact', 'reason' => $cat, 'category' => 'lexical'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $ownedTokens
     * @return array<int,array{ngram:string,n_terms:int,cost:float,conversions:int,terms:array<int,string>,protected:bool}>
     */
    private function aggregateNgrams(array $rows, array $ownedTokens = []): array
    {
        $acc = [];
        foreach ($rows as $r) {
            $toks = $this->tokens($r['term']);
            // A term is "protected" if it converted OR carries an owned root — any n-gram inside it
            // must never become a negative (it would block our own qualified/converting traffic).
            $protected = $r['conversions'] > 0 || ($ownedTokens !== [] && $this->carriesOwnedRoot($r['term'], $ownedTokens));
            $grams = [];
            $n = count($toks);
            for ($size = 1; $size <= 3; $size++) {
                for ($i = 0; $i + $size <= $n; $i++) {
                    $grams[] = implode(' ', array_slice($toks, $i, $size));
                }
            }
            foreach (array_unique($grams) as $g) {
                if (! isset($acc[$g])) {
                    $acc[$g] = ['ngram' => $g, 'n_terms' => 0, 'cost' => 0.0, 'conversions' => 0, 'terms' => [], 'protected' => false];
                }
                $acc[$g]['n_terms']++;
                $acc[$g]['cost'] += $r['cost'];
                $acc[$g]['conversions'] += $r['conversions'];
                $acc[$g]['terms'][] = $r['term'];
                $acc[$g]['protected'] = $acc[$g]['protected'] || $protected;
            }
        }

        return array_values($acc);
    }

    /**
     * @param  array<string,mixed>  $artifacts
     * @return array<int,string>
     */
    private function ownedRootTokens(array $artifacts): array
    {
        $blob = implode(' ', array_filter([
            (string) ($artifacts['mechanism'] ?? ''), (string) ($artifacts['trick'] ?? ''),
            (string) ($artifacts['slogan'] ?? ''), implode(' ', (array) ($artifacts['celebrities'] ?? [])),
            implode(' ', (array) ($artifacts['power_phrases'] ?? [])),
        ]));
        $toks = $this->tokens(mb_strtolower($blob));

        return array_values(array_filter(array_unique($toks), fn ($t) => ! in_array($t, self::GENERIC_TOKENS, true)));
    }

    /** @param array<int,string> $owned */
    private function carriesOwnedRoot(string $term, array $owned): bool
    {
        $set = array_flip($this->tokens($term));
        foreach ($owned as $t) {
            if (isset($set[$t])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function tokens(string $s): array
    {
        $raw = preg_split('/[^a-z0-9]+/', mb_strtolower($s)) ?: [];

        return array_values(array_filter($raw, fn ($t) => $t !== '' && mb_strlen($t) > 1 && ! in_array($t, self::STOP, true)));
    }
}
