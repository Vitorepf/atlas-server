<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Two-stage sum-of-legs inconsistency scanner over Polymarket's multi-outcome
 * (negRisk) events. SHADOW ONLY — detects and records, never trades.
 *
 * Stage 1 (cheap, broad): paginate active events ordered by 24h volume and
 * pre-filter on Gamma's cached bestAsk/bestBid sums. Coverage is the edge here,
 * so this stage is deliberately deterministic and API-cost-free.
 *
 * Stage 2 (precise, narrow): for the shortlist, pull live CLOB books per YES
 * leg and hand the real top-of-book to ArbMath. Only CLOB-verified sums become
 * signals; Gamma's cache (~1 min stale) alone is never trusted as evidence.
 *
 * Exhaustiveness guard: long baskets are only evaluated when EVERY market of
 * the event is active and unfrozen — a skipped/placeholder leg could be the
 * one that wins, so such events are conservatively ineligible for the long side.
 */
final class PolymarketArbScanner
{
    private const GAMMA_BASE = 'https://gamma-api.polymarket.com';

    public function __construct(
        private readonly PolymarketPinnedHttp $http = new PolymarketPinnedHttp,
        private readonly PolymarketShadowFeed $feed = new PolymarketShadowFeed,
    ) {}

    /**
     * @return array{
     *     scanned_events: int, eligible_events: int, shortlisted: int, verified: int,
     *     signals: list<array<string, mixed>>,
     *     best_long_sum: float|null, best_short_sum: float|null
     * }
     */
    public function scanOnce(
        int $pages = 4,
        int $perPage = 50,
        float $preFilterMargin = 0.02,
        float $minProfitPerSet = 0.005,
        float $feePerSet = 0.0,
        int $maxClobVerifications = 12,
    ): array {
        $scanned = 0;
        $eligible = 0;
        $shortlist = [];
        $bestLongSum = null;
        $bestShortSum = null;

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
                $scanned++;

                $candidate = $this->preFilter($event, $preFilterMargin);
                if ($candidate === null) {
                    continue;
                }
                $eligible++;

                if ($candidate['pre_long_sum'] !== null) {
                    $bestLongSum = $bestLongSum === null ? $candidate['pre_long_sum'] : min($bestLongSum, $candidate['pre_long_sum']);
                }
                if ($candidate['pre_short_sum'] !== null) {
                    $bestShortSum = $bestShortSum === null ? $candidate['pre_short_sum'] : max($bestShortSum, $candidate['pre_short_sum']);
                }

                if ($candidate['shortlisted']) {
                    $shortlist[] = $candidate;
                }
            }
        }

        usort($shortlist, fn (array $a, array $b) => $a['pre_score'] <=> $b['pre_score']);
        $shortlist = array_slice($shortlist, 0, $maxClobVerifications);

        $signals = [];
        $verified = 0;
        foreach ($shortlist as $candidate) {
            $levels = $this->liveBookLevels($candidate['legs']);
            if ($levels === null) {
                continue;
            }
            $verified++;

            if ($candidate['long_eligible']) {
                $long = ArbMath::longBasketDepth($levels['asks'], $feePerSet, $minProfitPerSet);
                if ($long !== null) {
                    $signals[] = [
                        'kind' => 'long_sum_under',
                        'execution_class' => 'simple_buy_all_legs',
                        'n_legs' => count($candidate['legs']),
                        'sum' => $long['marginal_sum_start'],
                        'profit_per_set' => round($long['profit_usd'] / max($long['sets'], 1e-9), 6),
                        'sets' => $long['sets'],
                        'profit_usd' => $long['profit_usd'],
                        'cost_usd' => $long['cost_usd'],
                        'event_slug' => $candidate['slug'],
                        'event_title' => $candidate['title'],
                        'volume_24hr' => $candidate['volume_24hr'],
                        'liquidity' => $candidate['liquidity'],
                        'legs' => $levels['detail'],
                    ];
                }
            }

            $short = ArbMath::shortBasketDepth($levels['bids'], $feePerSet, $minProfitPerSet);
            if ($short !== null) {
                $signals[] = [
                    'kind' => 'short_sum_over',
                    'execution_class' => 'requires_minting_full_set',
                    'n_legs' => count($candidate['legs']),
                    'sum' => $short['marginal_sum_start'],
                    'profit_per_set' => round($short['profit_usd'] / max($short['sets'], 1e-9), 6),
                    'sets' => $short['sets'],
                    'profit_usd' => $short['profit_usd'],
                    'cost_usd' => $short['cost_usd'],
                    'event_slug' => $candidate['slug'],
                    'event_title' => $candidate['title'],
                    'legs' => $levels['detail'],
                ];
            }
        }

        return [
            'scanned_events' => $scanned,
            'eligible_events' => $eligible,
            'shortlisted' => count($shortlist),
            'verified' => $verified,
            'signals' => $signals,
            'best_long_sum' => $bestLongSum,
            'best_short_sum' => $bestShortSum,
        ];
    }

    /**
     * @return array{slug: string, title: string, legs: list<array{token: string, question: string}>, long_eligible: bool, pre_long_sum: float|null, pre_short_sum: float|null, shortlisted: bool, pre_score: float}|null
     */
    private function preFilter(array $event, float $margin): ?array
    {
        if (! (bool) ($event['negRisk'] ?? false)) {
            return null;
        }
        $markets = $event['markets'] ?? [];
        if (! is_array($markets) || count($markets) < 3) {
            return null;
        }

        $legs = [];
        $sumAsks = 0.0;
        $sumBids = 0.0;
        $allActive = true;

        foreach ($markets as $market) {
            if (! is_array($market) || (bool) ($market['closed'] ?? false)) {
                return null; // closed leg mid-event => structure ambiguous, skip event
            }
            if (! (bool) ($market['active'] ?? false)) {
                $allActive = false;

                continue; // placeholder leg: long side ineligible, short side still freerolls
            }

            $token = $this->yesTokenId($market);
            if ($token === null) {
                return null;
            }

            $ask = isset($market['bestAsk']) ? (float) $market['bestAsk'] : 1.0;
            $bid = isset($market['bestBid']) ? (float) $market['bestBid'] : 0.0;
            $sumAsks += $ask;
            $sumBids += $bid;
            $legs[] = ['token' => $token, 'question' => (string) ($market['question'] ?? '')];
        }

        if (count($legs) < 3) {
            return null;
        }

        $preLongSum = $allActive ? round($sumAsks, 6) : null;
        $preShortSum = round($sumBids, 6);

        $longClose = $allActive && $sumAsks < 1.0 + $margin;
        $shortClose = $sumBids > 1.0 - $margin;

        return [
            'slug' => (string) ($event['slug'] ?? ''),
            'title' => (string) ($event['title'] ?? ''),
            'volume_24hr' => isset($event['volume24hr']) ? round((float) $event['volume24hr'], 2) : null,
            'liquidity' => isset($event['liquidity']) ? round((float) $event['liquidity'], 2) : null,
            'legs' => $legs,
            'long_eligible' => $allActive,
            'pre_long_sum' => $preLongSum,
            'pre_short_sum' => $preShortSum,
            'shortlisted' => $longClose || $shortClose,
            // Lower = more promising: distance from the no-arb boundary.
            'pre_score' => min(
                $longClose ? $sumAsks - 1.0 : INF,
                $shortClose ? 1.0 - $sumBids : INF,
            ),
        ];
    }

    /**
     * Full price levels per leg, for depth-aware basket math.
     *
     * @param  list<array{token: string, question: string}>  $legs
     * @return array{asks: list<list<array{price: float, size: float}>>, bids: list<list<array{price: float, size: float}>>, detail: list<array<string, mixed>>}|null
     */
    private function liveBookLevels(array $legs): ?array
    {
        $asks = [];
        $bids = [];
        $detail = [];

        foreach ($legs as $leg) {
            $levels = $this->feed->bookLevels($leg['token']);
            if ($levels === null) {
                return null; // one unreadable leg invalidates the basket evidence
            }

            $asks[] = $levels['asks'];
            $bids[] = $levels['bids'];
            $detail[] = [
                'question' => $leg['question'],
                'token' => $leg['token'],
                'best_ask' => $levels['asks'][0]['price'] ?? null,
                'ask_levels' => count($levels['asks']),
                'best_bid' => $levels['bids'][0]['price'] ?? null,
                'bid_levels' => count($levels['bids']),
            ];
        }

        return ['asks' => $asks, 'bids' => $bids, 'detail' => $detail];
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

        return (string) $tokenIds[0];
    }
}
