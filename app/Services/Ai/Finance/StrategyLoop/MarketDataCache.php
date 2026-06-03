<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop;

use RuntimeException;

/**
 * Reader over the frozen Binance CSV cache — the loop's only market-data source, a frozen
 * input the candidate strategy can never edit (it lives outside every scenario workspace; the
 * judge's scope guard keeps the candidate to strategy.json).
 *
 * barsAsOf($t) is a point-in-time primitive: it returns ONLY candles that had already CLOSED
 * at $t (closeTime <= $t); a candle still forming at $t is invisible. It is pinned by the unit
 * test.
 *
 * NOTE on where the LIVE no-look-ahead guarantee actually comes from (it does NOT depend on
 * barsAsOf): (1) the strategy engine decides on close[t] and fills at open[t+1] with every
 * indicator window bounded by t — proven by prefix-invariance; and (2) the scoring/holdout
 * split is a disjoint, contiguous region cut. barsAsOf stays the canonical helper for any
 * time-addressed read and a second, independent guard if the split is ever made time-based.
 */
final class MarketDataCache
{
    public function __construct(private readonly string $baseDir) {}

    public static function default(): self
    {
        return new self(rtrim((string) storage_path('atlas/finance/market-data'), '/'));
    }

    public function path(string $symbol, string $interval): string
    {
        return $this->baseDir.'/'.$symbol.'-'.$interval.'.csv';
    }

    public function has(string $symbol, string $interval): bool
    {
        return is_file($this->path($symbol, $interval));
    }

    /**
     * Every cached candle, ascending by openTime.
     *
     * @return list<Bar>
     */
    public function load(string $symbol, string $interval): array
    {
        $file = $this->path($symbol, $interval);
        if (! is_file($file)) {
            throw new RuntimeException("market-data cache missing: {$file}");
        }
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException("cannot open market-data cache: {$file}");
        }

        $bars = [];
        try {
            while (($line = fgets($fh)) !== false) {
                $row = str_getcsv(trim($line), ',', '"', '');
                if (count($row) < 7 || ! is_numeric($row[0])) {
                    continue; // header row / malformed line
                }
                $bars[] = new Bar(
                    $this->ms((string) $row[0]),
                    (float) $row[1],
                    (float) $row[2],
                    (float) $row[3],
                    (float) $row[4],
                    (float) $row[5],
                    $this->ms((string) $row[6]),
                );
            }
        } finally {
            fclose($fh);
        }

        usort($bars, static fn (Bar $a, Bar $b): int => $a->openTime <=> $b->openTime);

        return $bars;
    }

    /**
     * Candles known to have CLOSED at-or-before $asOfMs — the point-in-time view.
     * null $asOfMs returns every cached candle.
     *
     * @return list<Bar>
     */
    public function barsAsOf(string $symbol, string $interval, ?int $asOfMs = null): array
    {
        $bars = $this->load($symbol, $interval);
        if ($asOfMs === null) {
            return $bars;
        }

        return array_values(array_filter($bars, static fn (Bar $b): bool => $b->closeTime <= $asOfMs));
    }

    /**
     * Defensive unit normalization: Binance switched its public CSV stamps to
     * microseconds in 2025 (16-digit). Anything above ~1e14 is µs → fold to ms so the
     * whole cache is uniform regardless of which vintage a row came from.
     */
    private function ms(string $raw): int
    {
        $v = (int) $raw;

        return $v > 100_000_000_000_000 ? intdiv($v, 1000) : $v;
    }
}
