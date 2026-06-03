<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

/**
 * The deterministic output of one backtest pass: the equity curve, the per-bar
 * returns that feed the honest metrics, and the realized trades. Pure data — no
 * behaviour, no hidden state.
 */
final class StrategyResult
{
    /**
     * @param  list<float>  $equityCurve  mark-to-market equity at each bar's close (starts at 1.0)
     * @param  list<float>  $dailyReturns  per-bar equity returns (length = bars - 1)
     * @param  list<array<string,mixed>>  $trades  realized round-trips
     */
    public function __construct(
        public readonly array $equityCurve,
        public readonly array $dailyReturns,
        public readonly array $trades,
        public readonly int $nTrades,
    ) {}
}
