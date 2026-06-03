<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Cheap hardening checks for champion quarantine. They never make a candidate pass
 * the honesty gate; they only fail fragile candidates that looked good by accident.
 */
final class StrategyRobustnessChecks
{
    /**
     * @param  array<string,mixed>  $normal
     * @param  array<string,mixed>  $stressed
     * @return array<string,mixed>
     */
    public function costStress(array $normal, array $stressed, float $minStressSharpe = 0.0, float $maxDrawdown = 0.6): array
    {
        $stressSharpe = (float) ($stressed['ann_sharpe'] ?? -INF);
        $normalSharpe = (float) ($normal['ann_sharpe'] ?? -INF);
        $drawdown = (float) ($stressed['max_dd'] ?? INF);
        $passed = is_finite($stressSharpe)
            && $stressSharpe >= $minStressSharpe
            && $drawdown <= $maxDrawdown
            && $stressSharpe >= min($normalSharpe, 0.0);

        return [
            'passed' => $passed,
            'normal_sharpe' => is_finite($normalSharpe) ? round($normalSharpe, 6) : null,
            'stress_sharpe' => is_finite($stressSharpe) ? round($stressSharpe, 6) : null,
            'stress_max_dd' => is_finite($drawdown) ? round($drawdown, 6) : null,
            'reason' => $passed ? 'cost_stress_passed' : 'cost_stress_failed',
        ];
    }

    /**
     * @param  list<array<string,mixed>|null>  $neighbors
     * @return array<string,mixed>
     */
    public function neighborhood(array $neighbors, int $minPassing = 3, float $minMedianSharpe = 0.0): array
    {
        $passing = array_values(array_filter($neighbors, static fn ($n): bool => is_array($n) && is_finite((float) ($n['ann_sharpe'] ?? NAN))));
        $sharpes = array_map(static fn (array $n): float => (float) ($n['ann_sharpe'] ?? 0.0), $passing);
        sort($sharpes);
        $median = $sharpes === [] ? null : $sharpes[intdiv(count($sharpes), 2)];
        $passed = count($passing) >= $minPassing && $median !== null && $median >= $minMedianSharpe;

        return [
            'passed' => $passed,
            'neighbors_evaluated' => count($neighbors),
            'neighbors_passing_sanity' => count($passing),
            'median_neighbor_sharpe' => $median !== null ? round($median, 6) : null,
            'reason' => $passed ? 'neighborhood_passed' : 'neighborhood_failed',
        ];
    }
}
