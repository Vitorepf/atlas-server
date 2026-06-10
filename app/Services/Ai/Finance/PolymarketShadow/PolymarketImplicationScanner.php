<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Two-stage implication-violation scanner over Polymarket markets.
 * SHADOW ONLY — detects and records, never trades.
 *
 * Stage 0 (catalog): paginate active events ordered by 24h volume and collect
 * every active binary market (question, slug, endDate, YES token, Gamma's
 * cached bestBid/bestAsk).
 *
 * Stage 1 (relations, deterministic): ImplicationRelationParser emits ordered
 * pairs (A implies B) under cite-or-omit; no relation is ever guessed.
 *
 * Stage 2 (cheap pre-filter): Gamma's cached quotes shortlist pairs whose
 * bid(A) - ask(B) gap runs within margin of violating P(A) <= P(B).
 *
 * Stage 3 (precise): for the shortlist, pull live CLOB books and hand them to
 * ImplicationMath. Only CLOB-verified violations become signals; Gamma's cache
 * (~1 min stale) alone is never trusted as evidence.
 */
final class PolymarketImplicationScanner
{
    private const GAMMA_BASE = 'https://gamma-api.polymarket.com';

    public function __construct(
        private readonly PolymarketPinnedHttp $http = new PolymarketPinnedHttp,
        private readonly PolymarketShadowFeed $feed = new PolymarketShadowFeed,
    ) {}

    /**
     * @return array{
     *     scanned_events: int, markets_seen: int, pairs: int, pairs_by_family: array<string, int>,
     *     shortlisted: int, verified: int, signals: list<array<string, mixed>>, best_gap: float|null
     * }
     */
    public function scanOnce(
        int $pages = 4,
        int $perPage = 50,
        float $preFilterMargin = 0.02,
        float $minEdgePerShare = 0.005,
        float $feePerShare = 0.0,
        int $maxClobVerifications = 12,
    ): array {
        $scannedEvents = 0;
        $catalog = [];

        for ($page = 0; $page < $pages; $page++) {
            $events = $this->http->getJson(sprintf(
                '%s/events?active=true&closed=false&order=volume24hr&ascending=false&limit=%d&offset=%d',
                self::GAMMA_BASE, $perPage, $page * $perPage,
            ), 20);

            if (! is_array($events) || $events === []) {
                break;
            }

            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }
                $scannedEvents++;

                foreach ((array) ($event['markets'] ?? []) as $market) {
                    if (! is_array($market) || (bool) ($market['closed'] ?? false) || ! (bool) ($market['active'] ?? false)) {
                        continue;
                    }
                    $slug = (string) ($market['slug'] ?? '');
                    if ($slug === '' || isset($catalog[$slug])) {
                        continue;
                    }
                    $token = $this->yesTokenId($market);
                    if ($token === null) {
                        continue;
                    }

                    $catalog[$slug] = [
                        'key' => $slug,
                        'slug' => $slug,
                        'question' => (string) ($market['question'] ?? ''),
                        'end_date' => (string) ($market['endDate'] ?? ($market['endDateIso'] ?? '')),
                        'token' => $token,
                        'bid' => isset($market['bestBid']) ? (float) $market['bestBid'] : null,
                        'ask' => isset($market['bestAsk']) ? (float) $market['bestAsk'] : null,
                        'event_slug' => (string) ($event['slug'] ?? ''),
                        'volume_24hr' => isset($event['volume24hr']) ? round((float) $event['volume24hr'], 2) : null,
                        'liquidity' => isset($event['liquidity']) ? round((float) $event['liquidity'], 2) : null,
                    ];
                }
            }
        }

        $pairs = ImplicationRelationParser::pairs(array_values(array_map(
            fn (array $m) => ['key' => $m['key'], 'question' => $m['question'], 'slug' => $m['slug'], 'end_date' => $m['end_date']],
            $catalog,
        )));

        $pairsByFamily = [];
        $bestGap = null;
        $shortlist = [];

        foreach ($pairs as $pair) {
            $pairsByFamily[$pair['family']] = ($pairsByFamily[$pair['family']] ?? 0) + 1;

            $implicant = $catalog[$pair['implicant_key']] ?? null;
            $implied = $catalog[$pair['implied_key']] ?? null;
            if ($implicant === null || $implied === null) {
                continue;
            }

            // Missing cached quotes degrade conservatively: bid->0 / ask->1
            // never shortlists on absent evidence.
            $cachedGap = ($implicant['bid'] ?? 0.0) - ($implied['ask'] ?? 1.0);
            $bestGap = $bestGap === null ? $cachedGap : max($bestGap, $cachedGap);

            if ($cachedGap > -$preFilterMargin) {
                $shortlist[] = ['pair' => $pair, 'implicant' => $implicant, 'implied' => $implied, 'cached_gap' => round($cachedGap, 6)];
            }
        }

        usort($shortlist, fn (array $a, array $b) => $b['cached_gap'] <=> $a['cached_gap']);
        $shortlist = array_slice($shortlist, 0, $maxClobVerifications);

        $signals = [];
        $verified = 0;
        foreach ($shortlist as $candidate) {
            $implicantBook = $this->feed->bookLevels($candidate['implicant']['token']);
            $impliedBook = $this->feed->bookLevels($candidate['implied']['token']);
            if ($implicantBook === null || $impliedBook === null) {
                continue; // one unreadable book invalidates the pair evidence
            }
            $verified++;

            $hit = ImplicationMath::violationDepth($implicantBook['bids'], $impliedBook['asks'], $feePerShare, $minEdgePerShare);
            if ($hit === null) {
                continue;
            }

            $signals[] = [
                'kind' => 'implication_violation',
                'family' => $candidate['pair']['family'],
                'execution_class' => $hit['execution_class'],
                'implicant_slug' => $candidate['implicant']['slug'],
                'implicant_question' => $candidate['implicant']['question'],
                'implied_slug' => $candidate['implied']['slug'],
                'implied_question' => $candidate['implied']['question'],
                'gap' => $hit['edge_start'],
                'cached_gap' => $candidate['cached_gap'],
                'shares' => $hit['shares'],
                'profit_usd' => $hit['profit_usd'],
                'cost_usd' => $hit['cost_usd'],
                'volume_24hr' => self::minNullable($candidate['implicant']['volume_24hr'], $candidate['implied']['volume_24hr']),
                'liquidity' => self::minNullable($candidate['implicant']['liquidity'], $candidate['implied']['liquidity']),
                'evidence' => $candidate['pair']['evidence'],
                'legs' => [
                    'implicant' => [
                        'token' => $candidate['implicant']['token'],
                        'slug' => $candidate['implicant']['slug'],
                        'question' => $candidate['implicant']['question'],
                        'best_bid' => $implicantBook['bids'][0]['price'] ?? null,
                        'bid_levels' => count($implicantBook['bids']),
                    ],
                    'implied' => [
                        'token' => $candidate['implied']['token'],
                        'slug' => $candidate['implied']['slug'],
                        'question' => $candidate['implied']['question'],
                        'best_ask' => $impliedBook['asks'][0]['price'] ?? null,
                        'ask_levels' => count($impliedBook['asks']),
                    ],
                ],
            ];
        }

        return [
            'scanned_events' => $scannedEvents,
            'markets_seen' => count($catalog),
            'pairs' => count($pairs),
            'pairs_by_family' => $pairsByFamily,
            'shortlisted' => count($shortlist),
            'verified' => $verified,
            'signals' => $signals,
            'best_gap' => $bestGap !== null ? round($bestGap, 6) : null,
        ];
    }

    /**
     * Hot-watch tier: re-verify ONE known pair from its stored leg tokens at
     * high frequency. Returns fresh depth-aware numbers while the violation
     * still clears the floor, null when gone — the caller's lifecycle row stops
     * advancing, which IS the TTL measurement.
     *
     * @return array{gap: float, shares: float, profit_usd: float, cost_usd: float}|null
     */
    public function verifyKnownPair(string $implicantToken, string $impliedToken, float $feePerShare = 0.0, float $minEdgePerShare = 0.005): ?array
    {
        if ($implicantToken === '' || $impliedToken === '') {
            return null;
        }

        $implicantBook = $this->feed->bookLevels($implicantToken);
        $impliedBook = $this->feed->bookLevels($impliedToken);
        if ($implicantBook === null || $impliedBook === null) {
            return null;
        }

        $hit = ImplicationMath::violationDepth($implicantBook['bids'], $impliedBook['asks'], $feePerShare, $minEdgePerShare);
        if ($hit === null) {
            return null;
        }

        return [
            'gap' => $hit['edge_start'],
            'shares' => $hit['shares'],
            'profit_usd' => $hit['profit_usd'],
            'cost_usd' => $hit['cost_usd'],
        ];
    }

    private static function minNullable(?float $a, ?float $b): ?float
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => min($a, $b),
        };
    }

    private function yesTokenId(array $market): ?string
    {
        $tokenIds = $market['clobTokenIds'] ?? null;
        if (is_string($tokenIds)) {
            $tokenIds = json_decode($tokenIds, true);
        }
        $outcomes = $market['outcomes'] ?? null;
        if (is_string($outcomes)) {
            $outcomes = json_decode($outcomes, true);
        }
        if (! is_array($tokenIds) || $tokenIds === [] || ! is_array($outcomes)) {
            return null;
        }

        foreach ($outcomes as $i => $label) {
            if (is_string($label) && strcasecmp(trim($label), 'Yes') === 0 && isset($tokenIds[$i])) {
                return (string) $tokenIds[$i];
            }
        }

        // No explicit Yes outcome (e.g. Over/Under markets): the primary token's
        // semantics are not derivable from the question text — cite-or-omit.
        return null;
    }
}
