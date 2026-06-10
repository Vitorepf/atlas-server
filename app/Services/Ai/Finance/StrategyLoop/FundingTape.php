<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop;

use RuntimeException;

/**
 * Reader over the frozen funding-rate CSV cache (feature set derivatives_funding_oi_v1,
 * funding-only) — a frozen input the candidate can never edit, exactly like the price
 * cache. Each event: [funding_time(ms), rate per 8h period as decimal].
 *
 * PUBLISH-TIME POLICY (the feature set's lookahead control): a funding event is
 * knowable AT its funding_time (the payment happens at calc_time). Any consumer must
 * therefore only read events with funding_time <= the bar decision time — enforced by
 * the strategies (proven by prefix-invariance over a truncated tape) and available
 * here as the point-in-time primitive eventsAsOf().
 */
final class FundingTape
{
    public function __construct(private readonly string $baseDir) {}

    public static function default(): self
    {
        // storage_path() exige a Application; em unit test puro só há um Container cru
        // (o profile consome este default ao montar manifests). Fallback derivado do
        // próprio layout do repo: app/Services/Ai/Finance/StrategyLoop -> raiz.
        try {
            return new self(rtrim((string) storage_path('atlas/finance/market-data'), '/'));
        } catch (\Throwable) {
            return new self(dirname(__DIR__, 5).'/storage/atlas/finance/market-data');
        }
    }

    public function path(string $symbol): string
    {
        return $this->baseDir.'/'.$symbol.'-funding.csv';
    }

    public function has(string $symbol): bool
    {
        return is_file($this->path($symbol));
    }

    public function sha256(string $symbol): string
    {
        $file = $this->path($symbol);
        $hash = is_file($file) ? hash_file('sha256', $file) : false;

        return $hash !== false ? $hash : 'funding_tape_missing';
    }

    /**
     * Every funding event, ascending by funding_time.
     *
     * @return list<array{0:int,1:float}>  [funding_time_ms, rate]
     */
    public function load(string $symbol): array
    {
        $file = $this->path($symbol);
        if (! is_file($file)) {
            throw new RuntimeException("funding tape missing: {$file}");
        }
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new RuntimeException("cannot open funding tape: {$file}");
        }

        $events = [];
        try {
            while (($line = fgets($fh)) !== false) {
                $parts = explode(',', trim($line));
                if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                    continue; // header or malformed row
                }
                $events[] = [(int) $parts[0], (float) $parts[1]];
            }
        } finally {
            fclose($fh);
        }
        usort($events, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $events;
    }

    /**
     * Point-in-time primitive: only events already PAID at $atMs (funding_time <= $atMs).
     *
     * @param  list<array{0:int,1:float}>  $events  ascending
     * @return list<array{0:int,1:float}>
     */
    public static function eventsAsOf(array $events, int $atMs): array
    {
        $out = [];
        foreach ($events as $e) {
            if ($e[0] > $atMs) {
                break;
            }
            $out[] = $e;
        }

        return $out;
    }
}
