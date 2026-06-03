<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Coarse strategy-region signature. Cross-campaign rediscovery should not require
 * byte-identical params; it should ask whether another campaign independently found
 * the same robust region of the search space.
 */
final class StrategyCandidateSignature
{
    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function make(string $symbol, string $interval, string $family, array $params): array
    {
        $bucketed = [
            'regime_period' => $this->bucketInt((int) ($params['regime_period'] ?? 0), 25),
            'entry_lookback' => $this->bucketInt((int) ($params['entry_lookback'] ?? 0), 5),
            'exit_lookback' => $this->bucketInt((int) ($params['exit_lookback'] ?? 0), 5),
            'atr_period' => $this->bucketInt((int) ($params['atr_period'] ?? 0), 5),
            'atr_mult' => $this->bucketFloat((float) ($params['atr_mult'] ?? 0.0), 0.5),
            'risk_pct' => $this->bucketFloat((float) ($params['risk_pct'] ?? 0.0), 0.05),
            'min_hold_bars' => $this->bucketInt((int) ($params['min_hold_bars'] ?? 0), 2),
        ];
        $scope = [
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'family' => $family,
            'bucketed_params' => $bucketed,
        ];

        return [
            'schema_version' => 'atlas.finance.strategy_candidate_signature.v1',
            'signature' => substr(hash('sha256', json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: ''), 0, 20),
            'scope' => $scope,
            'raw_params' => $params,
        ];
    }

    private function bucketInt(int $value, int $step): int
    {
        if ($value <= 0) {
            return 0;
        }

        return (int) (round($value / $step) * $step);
    }

    private function bucketFloat(float $value, float $step): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        return round(round($value / $step) * $step, 6);
    }
}
