<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

/**
 * Wilson score confidence interval for proportions.
 *
 *   center = (p̂ + z²/(2n)) / (1 + z²/n)
 *   margin = z·√(p̂(n-p̂)/n + z²/(4n²)) / (1 + z²/n)
 *
 * Wilson is correct at boundaries (p=0 or p=1) where Wald approximation fails.
 * Min n = 5 (below this even Wilson is meaningless).
 */
class WilsonCalculator
{
    public const MIN_N = 5;
    public const Z_95 = 1.96;

    /**
     * @return array{lower:?float, upper:?float, center:?float, n:int, k:int, method:string}
     */
    public function ci(int $k, int $n, float $z = self::Z_95): array
    {
        if ($n < self::MIN_N) {
            return ['lower' => null, 'upper' => null, 'center' => null, 'n' => $n, 'k' => $k, 'method' => 'wilson:n_too_small'];
        }

        if ($k < 0 || $k > $n) {
            return ['lower' => null, 'upper' => null, 'center' => null, 'n' => $n, 'k' => $k, 'method' => 'wilson:invalid_input'];
        }

        $pHat = $k / $n;
        $z2 = $z * $z;
        $denominator = 1.0 + $z2 / $n;
        $center = ($pHat + $z2 / (2.0 * $n)) / $denominator;
        $margin = $z * sqrt($pHat * (1.0 - $pHat) / $n + $z2 / (4.0 * $n * $n)) / $denominator;

        return [
            'lower' => round(max(0.0, $center - $margin), 6),
            'upper' => round(min(1.0, $center + $margin), 6),
            'center' => round($center, 6),
            'n' => $n,
            'k' => $k,
            'method' => 'wilson',
        ];
    }
}
