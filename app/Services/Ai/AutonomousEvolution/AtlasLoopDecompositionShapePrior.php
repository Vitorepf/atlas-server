<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE Leap 5 — the shape-prior verdict (Wilson lower-bound certified-rate, n-guarded).
 *
 * Given a fingerprint's terminal history ({certified, total}) from the decomposition outcome corpus,
 * compute the Wilson score-interval LOWER bound on its certified-rate and judge:
 *   - n < minSamples              => UNKNOWN  (cold/thin corpus contributes ZERO — never blocks a novel shape)
 *   - lower_bound < targetRate    => SUSPECT  (REPLAN advisory: this shape EMPIRICALLY thrashes the frozen gate)
 *   - otherwise                   => OK
 *
 * The Wilson lower bound (not the raw rate) is the honest signal: it demands the failure be statistically
 * established, not a small-sample fluke. Every input is a machine-resolved terminal outcome — the model
 * cannot talk past a statistic computed from prior frozen-bar verdicts. Pure: fixed corpus => fixed verdict.
 */
final class AtlasLoopDecompositionShapePrior
{
    /**
     * @return array{verdict:string, lower_bound:float, rate:float, n:int, certified:int, reason:string}
     */
    public function assess(int $certified, int $total, float $targetRate = 0.5, int $minSamples = 8): array
    {
        $total = max(0, $total);
        $certified = max(0, min($certified, $total));
        $targetRate = max(0.0, min(1.0, $targetRate));
        $minSamples = max(1, $minSamples);

        if ($total < $minSamples) {
            return [
                'verdict' => 'unknown', 'lower_bound' => 0.0, 'rate' => 0.0,
                'n' => $total, 'certified' => $certified, 'reason' => 'insufficient_samples:'.$total.'<'.$minSamples,
            ];
        }

        $rate = $total > 0 ? $certified / $total : 0.0;
        $lb = $this->wilsonLowerBound($certified, $total);
        $suspect = $lb + 1e-9 < $targetRate;

        return [
            'verdict' => $suspect ? 'suspect' : 'ok',
            'lower_bound' => round($lb, 4),
            'rate' => round($rate, 4),
            'n' => $total,
            'certified' => $certified,
            'reason' => $suspect
                ? 'shape_historically_thrashes:lb='.round($lb, 2).'<target='.round($targetRate, 2).':n='.$total
                : 'ok:lb='.round($lb, 2),
        ];
    }

    /** Wilson score-interval lower bound for a binomial proportion (z=1.96 ≈ 95%). */
    private function wilsonLowerBound(int $k, int $n, float $z = 1.96): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $phat = $k / $n;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $centre = $phat + $z2 / (2 * $n);
        $margin = $z * sqrt(($phat * (1 - $phat) + $z2 / (4 * $n)) / $n);

        return max(0.0, min(1.0, ($centre - $margin) / $denom));
    }
}
