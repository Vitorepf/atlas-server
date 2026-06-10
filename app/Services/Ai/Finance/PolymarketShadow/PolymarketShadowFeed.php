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
