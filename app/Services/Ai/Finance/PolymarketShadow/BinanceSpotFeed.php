<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

use Illuminate\Support\Facades\Http;

/**
 * Minimal Binance spot reader for the shadow runtime: top-of-book mid price
 * (the leading indicator) plus a 1-second kline bootstrap so the volatility
 * estimator starts warm instead of guessing.
 */
final class BinanceSpotFeed
{
    private const BASE = 'https://api.binance.com';

    public function __construct(private readonly string $symbol = 'BTCUSDT') {}

    /**
     * @return array{price: float, ts_ms: int}|null
     */
    public function mid(): ?array
    {
        try {
            $response = Http::timeout(5)->connectTimeout(3)
                ->get(self::BASE.'/api/v3/ticker/bookTicker', ['symbol' => $this->symbol]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $bid = (float) $response->json('bidPrice', 0);
        $ask = (float) $response->json('askPrice', 0);
        if ($bid <= 0.0 || $ask <= 0.0) {
            return null;
        }

        return [
            'price' => ($bid + $ask) / 2.0,
            'ts_ms' => (int) round(microtime(true) * 1000),
        ];
    }

    /**
     * Recent 1-second closes (ascending), for seeding the EWMA variance.
     *
     * @return list<float>
     */
    public function recentSecondCloses(int $count = 300): array
    {
        $count = max(10, min(1000, $count));

        try {
            $response = Http::timeout(8)->connectTimeout(3)
                ->get(self::BASE.'/api/v3/klines', [
                    'symbol' => $this->symbol,
                    'interval' => '1s',
                    'limit' => $count,
                ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        $closes = [];
        foreach ((array) $response->json() as $kline) {
            $close = is_array($kline) ? (float) ($kline[4] ?? 0) : 0.0;
            if ($close > 0.0) {
                $closes[] = $close;
            }
        }

        return $closes;
    }
}
