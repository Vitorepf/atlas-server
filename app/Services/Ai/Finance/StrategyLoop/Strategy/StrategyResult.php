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
     * @param  array{entry_idx:int,entry_price:float}|null  $openPosition  long aberto no último bar (paper/live precisa disso; o backtest ignora)
     * @param  'enter'|'exit'|null  $pendingAction  decisão tomada no close do ÚLTIMO bar, ainda não executada (o sinal acionável "agora")
     */
    public function __construct(
        public readonly array $equityCurve,
        public readonly array $dailyReturns,
        public readonly array $trades,
        public readonly int $nTrades,
        public readonly ?array $openPosition = null,
        public readonly ?string $pendingAction = null,
    ) {}
}
