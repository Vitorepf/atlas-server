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

        $primaryReturn = $this->optionalFloat($primary, ['total_return', 'net_return', 'return']);
        $secondaryReturn = $this->optionalFloat($secondary, ['total_return', 'net_return', 'return']);
        if ($primaryReturn !== null && $secondaryReturn !== null) {
            if (abs($primaryReturn - $secondaryReturn) > 0.05) {
                $reasons[] = 'return_diverged';
            }
            if (($primaryReturn * $secondaryReturn) < 0.0) {
                $reasons[] = 'return_sign_inverted';
            }
        }

        $primaryDd = (float) ($primary['max_dd'] ?? NAN);
        $secondaryDd = (float) ($secondary['max_dd'] ?? NAN);
        if (! is_finite($primaryDd) || ! is_finite($secondaryDd) || abs($primaryDd - $secondaryDd) > 0.03) {
            $reasons[] = 'drawdown_diverged';
        }

        $primaryExposure = $this->optionalFloat($primary, ['exposure', 'exposure_ratio']);
        $secondaryExposure = $this->optionalFloat($secondary, ['exposure', 'exposure_ratio']);
        if ($primaryExposure !== null && $secondaryExposure !== null && abs($primaryExposure - $secondaryExposure) > 0.10) {
            $reasons[] = 'exposure_diverged';
        }

        $equityDistance = $this->equityCurveDistance($this->optionalArray($primary, ['equity_curve_sample', 'equity_curve']), $this->optionalArray($secondary, ['equity_curve_sample', 'equity_curve']));
        if ($equityDistance !== null && $equityDistance > 0.05) {
            $reasons[] = 'equity_curve_diverged';
        }

        $primaryHoldoutPassed = $this->optionalBool($primary, ['holdout_passed']);
        $secondaryHoldoutPassed = $this->optionalBool($secondary, ['holdout_passed']);
        if ($secondaryHoldoutPassed === false || ($primaryHoldoutPassed !== null && $secondaryHoldoutPassed !== null && $primaryHoldoutPassed !== $secondaryHoldoutPassed)) {
            $reasons[] = 'holdout_result_diverged';
        }

        return [
            'passed' => $reasons === [],
            'status' => $reasons === [] ? 'passed' : 'failed',
            'reasons' => $reasons === [] ? ['second_engine_passed'] : $reasons,
            'primary' => $primary,
            'secondary' => $secondary,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function optionalFloat(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (is_numeric($payload[$key] ?? null)) {
                $value = (float) $payload[$key];

                return is_finite($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function optionalBool(array $payload, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (is_bool($payload[$key] ?? null)) {
                return (bool) $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     * @return list<float>|null
     */
    private function optionalArray(array $payload, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (! is_array($payload[$key] ?? null)) {
                continue;
            }
            $values = array_values(array_filter(array_map(
                static fn (mixed $value): ?float => is_numeric($value) && is_finite((float) $value) ? (float) $value : null,
                (array) $payload[$key],
            ), static fn (?float $value): bool => $value !== null));

            return $values !== [] ? $values : null;
        }

        return null;
    }

    /**
     * @param  list<float>|null  $primary
     * @param  list<float>|null  $secondary
     */
    private function equityCurveDistance(?array $primary, ?array $secondary): ?float
    {
        if ($primary === null || $secondary === null || count($primary) < 2 || count($secondary) < 2) {
            return null;
        }

        $points = min(20, count($primary), count($secondary));
        $sum = 0.0;
        for ($i = 0; $i < $points; $i++) {
            $pIdx = (int) round($i * (count($primary) - 1) / max(1, $points - 1));
            $sIdx = (int) round($i * (count($secondary) - 1) / max(1, $points - 1));
            $sum += abs($primary[$pIdx] - $secondary[$sIdx]);
        }

        return $sum / $points;
    }
}
