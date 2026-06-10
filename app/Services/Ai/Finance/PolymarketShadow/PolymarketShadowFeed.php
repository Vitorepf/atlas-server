<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Read-only market data for the Polymarket "Bitcoin Up or Down" 5-minute series.
 *
 * Discovery is deterministic: the event slug is btc-updown-5m-<unix start of the
 * 5-minute window> (verified live 2026-06-10). Resolution source per the market
 * description is the Chainlink BTC/USD data stream, with ties (end >= start)
 * resolving "Up" — the shadow settles on Binance as a proxy and reconciles the
 * official outcome afterwards to measure the basis risk explicitly.
 */
final class PolymarketShadowFeed
{
    public const WINDOW_SECONDS = 300;

    private const GAMMA_BASE = 'https://gamma-api.polymarket.com';

    private const CLOB_BASE = 'https://clob.polymarket.com';

    public function __construct(private readonly PolymarketPinnedHttp $http = new PolymarketPinnedHttp) {}

    public function windowStart(int $unixSeconds): int
    {
        return intdiv($unixSeconds, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }

    public function eventSlug(int $windowStart): string
    {
        return 'btc-updown-5m-'.$windowStart;
    }

    /**
     * @return array{slug: string, window_start: int, token_up: string, token_down: string, question: string}|null
     */
    public function marketForWindow(int $windowStart): ?array
    {
        $slug = $this->eventSlug($windowStart);
        $events = $this->http->getJson(self::GAMMA_BASE.'/events?slug='.$slug);
        $event = is_array($events) ? ($events[0] ?? null) : null;
        $market = is_array($event) ? (($event['markets'] ?? [])[0] ?? null) : null;
        if (! is_array($market)) {
            return null;
        }

        $tokenIds = $market['clobTokenIds'] ?? null;
        if (is_string($tokenIds)) {
            $tokenIds = json_decode($tokenIds, true);
        }
        $outcomes = $market['outcomes'] ?? null;
        if (is_string($outcomes)) {
            $outcomes = json_decode($outcomes, true);
        }
        if (! is_array($tokenIds) || count($tokenIds) < 2 || ! is_array($outcomes) || count($outcomes) < 2) {
            return null;
        }

        // Map token ids by outcome label rather than assuming order.
        $upIndex = null;
        foreach ($outcomes as $i => $label) {
            if (is_string($label) && strcasecmp(trim($label), 'Up') === 0) {
                $upIndex = $i;
                break;
            }
        }
        if ($upIndex === null) {
            return null;
        }
        $downIndex = $upIndex === 0 ? 1 : 0;

        return [
            'slug' => (string) ($market['slug'] ?? $slug),
            'window_start' => $windowStart,
            'token_up' => (string) $tokenIds[$upIndex],
            'token_down' => (string) $tokenIds[$downIndex],
            'question' => (string) ($market['question'] ?? ''),
        ];
    }

    /**
     * conditionId(s) for an event's markets, looked up by slug. Used by the
     * fill-confidence probe to map a stored opportunity back to its on-chain
     * market for trade-activity lookup.
     *
     * @return list<string>
     */
    public function conditionIdsForEvent(string $slug): array
    {
        $events = $this->http->getJson(self::GAMMA_BASE.'/events?slug='.$slug, 12);
        $event = is_array($events) ? ($events[0] ?? null) : null;
        if (! is_array($event) || ! is_array($event['markets'] ?? null)) {
            return [];
        }

        $ids = [];
        foreach ($event['markets'] as $m) {
            $cid = is_array($m) ? ($m['conditionId'] ?? null) : null;
            if (is_string($cid) && $cid !== '') {
                $ids[] = $cid;
            }
        }

        return $ids;
    }

    /**
     * Official resolution, once available: 'up', 'down' or null while unresolved.
     */
    public function resolvedOutcome(int $windowStart): ?string
    {
        $events = $this->http->getJson(self::GAMMA_BASE.'/events?slug='.$this->eventSlug($windowStart));
        $event = is_array($events) ? ($events[0] ?? null) : null;
        $market = is_array($event) ? (($event['markets'] ?? [])[0] ?? null) : null;
        if (! is_array($market)) {
            return null;
        }

        $prices = $market['outcomePrices'] ?? null;
        if (is_string($prices)) {
            $prices = json_decode($prices, true);
        }
        $outcomes = $market['outcomes'] ?? null;
        if (is_string($outcomes)) {
            $outcomes = json_decode($outcomes, true);
        }
        $closed = (bool) ($market['closed'] ?? false);
        if (! $closed || ! is_array($prices) || ! is_array($outcomes) || count($prices) !== count($outcomes)) {
            return null;
        }

        foreach ($prices as $i => $price) {
            if ((float) $price >= 0.99) {
                $label = strtolower(trim((string) ($outcomes[$i] ?? '')));

                return in_array($label, ['up', 'down'], true) ? $label : null;
            }
        }

        return null;
    }

    private const DATA_BASE = 'https://data-api.polymarket.com';

    /**
     * Fill-confidence probe: how recently/actively a market actually TRADED.
     * A standing arb in a market that hasn't traded in hours is probably a stale
     * (phantom) book that won't fill; one trading every few minutes is real. This
     * is the honest predictor of "will my order fill" — measurable without risking
     * a cent.
     *
     * @return array{trades: int, last_trade_min_ago: float|null, volume_recent: float}
     */
    public function recentTradeActivity(string $conditionId, int $nowUnix): array
    {
        $trades = $this->http->getJson(self::DATA_BASE.'/trades?market='.$conditionId.'&limit=100', 12);
        if (! is_array($trades) || $trades === []) {
            return ['trades' => 0, 'last_trade_min_ago' => null, 'volume_recent' => 0.0];
        }

        $lastTs = 0;
        $volume = 0.0;
        $count = 0;
        foreach ($trades as $t) {
            if (! is_array($t)) {
                continue;
            }
            $ts = (int) ($t['timestamp'] ?? 0);
            $lastTs = max($lastTs, $ts);
            // window: trades in the last 6h
            if ($ts >= $nowUnix - 21600) {
                $count++;
                $volume += (float) ($t['size'] ?? 0) * (float) ($t['price'] ?? 0);
            }
        }

        return [
            'trades' => $count,
            'last_trade_min_ago' => $lastTs > 0 ? round(($nowUnix - $lastTs) / 60, 1) : null,
            'volume_recent' => round($volume, 2),
        ];
    }

    /**
     * Full price levels for one outcome token: asks ascending, bids descending.
     *
     * @return array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>}|null
     */
    public function bookLevels(string $tokenId, int $timeoutSeconds = 10): ?array
    {
        $book = $this->http->getJson(self::CLOB_BASE.'/book?token_id='.$tokenId, $timeoutSeconds);
        if (! is_array($book)) {
            return null;
        }

        $normalize = static function (array $levels): array {
            $out = [];
            foreach ($levels as $level) {
                $price = (float) ($level['price'] ?? 0);
                $size = (float) ($level['size'] ?? 0);
                if ($price > 0.0 && $price < 1.0 && $size > 0.0) {
                    $out[] = ['price' => $price, 'size' => $size];
                }
            }

            return $out;
        };

        $asks = $normalize((array) ($book['asks'] ?? []));
        $bids = $normalize((array) ($book['bids'] ?? []));
        usort($asks, fn (array $a, array $b) => $a['price'] <=> $b['price']);
        usort($bids, fn (array $a, array $b) => $b['price'] <=> $a['price']);

        return ['asks' => $asks, 'bids' => $bids];
    }

    /**
     * Normalized top-of-book for one outcome token.
     *
     * @return array{best_bid: float, best_ask: float, bid_size: float, ask_size: float, ts_ms: int}|null
     */
    public function book(string $tokenId): ?array
    {
        $book = $this->http->getJson(self::CLOB_BASE.'/book?token_id='.$tokenId);
        if (! is_array($book)) {
            return null;
        }

        $bestBid = 0.0;
        $bidSize = 0.0;
        foreach ((array) ($book['bids'] ?? []) as $level) {
            $price = (float) ($level['price'] ?? 0);
            if ($price > $bestBid) {
                $bestBid = $price;
                $bidSize = (float) ($level['size'] ?? 0);
            }
        }

        $bestAsk = 1.0;
        $askSize = 0.0;
        foreach ((array) ($book['asks'] ?? []) as $level) {
            $price = (float) ($level['price'] ?? 1);
            if ($price < $bestAsk) {
                $bestAsk = $price;
                $askSize = (float) ($level['size'] ?? 0);
            }
        }

        if ($bestBid <= 0.0 && $askSize <= 0.0) {
            return null;
        }

        return [
            'best_bid' => $bestBid,
            'best_ask' => $bestAsk,
            'bid_size' => $bidSize,
            'ask_size' => $askSize,
            'ts_ms' => (int) ($book['timestamp'] ?? 0),
        ];
    }
}
