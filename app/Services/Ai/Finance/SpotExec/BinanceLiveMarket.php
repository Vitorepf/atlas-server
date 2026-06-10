<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\SpotExec;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use Illuminate\Support\Facades\Http;

/**
 * Mercado ao vivo da Binance spot via endpoints PÚBLICOS (sem chave): klines
 * fechadas + mid do book. Fail-soft: erro de rede vira lista vazia / null e o
 * runtime trata como "sem tick" — nunca inventa dado.
 */
final class BinanceLiveMarket implements MarketSource
{
    private const BASE = 'https://api.binance.com';

    public function closedBars(string $symbol, string $interval, int $limit): array
    {
        $limit = max(10, min(1000, $limit));

        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->get(self::BASE.'/api/v3/klines', [
                    'symbol' => strtoupper($symbol),
                    'interval' => $interval,
                    'limit' => $limit,
                ]);
        } catch (\Throwable) {
            return [];
        }
        if (! $response->ok()) {
            return [];
        }

        $nowMs = (int) round(microtime(true) * 1000);
        $bars = [];
        foreach ((array) $response->json() as $k) {
            if (! is_array($k) || count($k) < 7) {
                continue;
            }
            $closeTime = (int) $k[6];
            if ($closeTime >= $nowMs) {
                continue; // vela ainda formando — invisível por contrato
            }
            $bars[] = new Bar(
                openTime: (int) $k[0],
                open: (float) $k[1],
                high: (float) $k[2],
                low: (float) $k[3],
                close: (float) $k[4],
                volume: (float) $k[5],
                closeTime: $closeTime,
            );
        }

        return $bars;
    }

    public function mid(string $symbol): ?float
    {
        try {
            $response = Http::timeout(5)->connectTimeout(3)
                ->get(self::BASE.'/api/v3/ticker/bookTicker', ['symbol' => strtoupper($symbol)]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->ok()) {
            return null;
        }
        $bid = (float) $response->json('bidPrice', 0);
        $ask = (float) $response->json('askPrice', 0);

        return ($bid > 0.0 && $ask > 0.0) ? ($bid + $ask) / 2.0 : null;
    }
}
