<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Compares Atlas' native backtest with an independent engine report. The command
 * integration can be added later; the gate itself already fails closed when no
 * independent report is available.
 */
final class SecondEngineDivergenceGate
{
    /**
     * @param  array<string,mixed>  $primary
     * @param  array<string,mixed>|null  $secondary
     * @return array<string,mixed>
     */
    public function evaluate(array $primary, ?array $secondary): array
    {
        if ($secondary === null || $secondary === []) {
            return ['passed' => false, 'status' => 'unavailable', 'reasons' => ['second_engine_unavailable']];
        }
        if (($secondary['status'] ?? 'ready') === 'unavailable') {
            return ['passed' => false, 'status' => 'unavailable', 'reasons' => ['second_engine_unavailable']];
        }

        $reasons = [];
        $primaryTrades = (int) ($primary['trade_count'] ?? $primary['n_trades'] ?? 0);
        $secondaryTrades = (int) ($secondary['trade_count'] ?? $secondary['n_trades'] ?? 0);
        $tradeTolerance = max(2, (int) ceil(max($primaryTrades, 1) * 0.10));
        if (abs($primaryTrades - $secondaryTrades) > $tradeTolerance) {
            $reasons[] = 'trade_count_diverged';
        }

        $primarySharpe = (float) ($primary['ann_sharpe'] ?? $primary['sharpe'] ?? NAN);
        $secondarySharpe = (float) ($secondary['ann_sharpe'] ?? $secondary['sharpe'] ?? NAN);
        if (! is_finite($primarySharpe) || ! is_finite($secondarySharpe) || abs($primarySharpe - $secondarySharpe) > 0.15) {
            $reasons[] = 'sharpe_diverged';
        }
        if (is_finite($primarySharpe) && is_finite($secondarySharpe) && ($primarySharpe * $secondarySharpe) < 0.0) {
            $reasons[] = 'sharpe_sign_inverted';
        }

        $primaryDd = (float) ($primary['max_dd'] ?? NAN);
        $secondaryDd = (float) ($secondary['max_dd'] ?? NAN);
        if (! is_finite($primaryDd) || ! is_finite($secondaryDd) || abs($primaryDd - $secondaryDd) > 0.03) {
            $reasons[] = 'drawdown_diverged';
        }

        return [
            'passed' => $reasons === [],
            'status' => $reasons === [] ? 'passed' : 'failed',
            'reasons' => $reasons === [] ? ['second_engine_passed'] : $reasons,
            'primary' => $primary,
            'secondary' => $secondary,
        ];
    }
}
