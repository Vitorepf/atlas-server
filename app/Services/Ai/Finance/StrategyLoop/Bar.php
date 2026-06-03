<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop;

/**
 * One OHLCV candle. Timestamps are MILLISECONDS since epoch. Immutable by
 * construction — the backtest reads bars, it never mutates history.
 */
final class Bar
{
    public function __construct(
        public readonly int $openTime,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume,
        public readonly int $closeTime,
    ) {}
}
