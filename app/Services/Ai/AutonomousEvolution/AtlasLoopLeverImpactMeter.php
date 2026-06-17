<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ROADMAP #4 — the measurement instrument that turns every lever flip from FAITH into a measured decision.
 *
 * Two readings, both pure + deterministic (no DB / no provider):
 *  1. impact(before, after): did flipping a flag actually help? It compares ADMISSION-rate (how much of
 *     generated work survives the draft gates) and CONVERSION-rate (how much of attempted work certifies)
 *     across a before/after window, and flags a STARVATION risk when a stricter draft gate collapsed supply
 *     (the loop's #1 historical failure mode — the floor-check's explicit warning for HC1/DD1).
 *  2. saturation(inDist, transfer): the saturation probe. Given a J-curve on the in-distribution metric AND
 *     on a held-out TRANSFER slice, it says whether there is real, transfer-validated headroom (keep
 *     investing) or you have hit the Goodhart ceiling (in-distribution still climbing while transfer is flat
 *     => more optimization only overfits the proxy => stop; a stronger optimizer / capex would NOT pay off).
 *
 * This is the gate on every "should we flip this flag / spend the capex" decision: never flip / spend blind.
 */
final class AtlasLoopLeverImpactMeter
{
    /** Safe rate in [0,1]; 0 when the denominator is 0. Pure. */
    public static function rate(int $numerator, int $denominator): float
    {
        return $denominator <= 0 ? 0.0 : max(0.0, min(1.0, $numerator / $denominator));
    }

    /**
     * Impact of a lever between a BEFORE and AFTER window of loop outcomes. Pure.
     *
     * @param  array{generated?:int, admitted?:int, attempted?:int, certified?:int}  $before
     * @param  array{generated?:int, admitted?:int, attempted?:int, certified?:int}  $after
     * @return array{admission_before:float, admission_after:float, admission_delta:float, conversion_before:float, conversion_after:float, conversion_delta:float, verdict:string, starvation_risk:bool}
     */
    public static function impact(array $before, array $after, float $minDelta = 0.02): array
    {
        $admBefore = self::rate((int) ($before['admitted'] ?? 0), (int) ($before['generated'] ?? 0));
        $admAfter = self::rate((int) ($after['admitted'] ?? 0), (int) ($after['generated'] ?? 0));
        $convBefore = self::rate((int) ($before['certified'] ?? 0), (int) ($before['attempted'] ?? 0));
        $convAfter = self::rate((int) ($after['certified'] ?? 0), (int) ($after['attempted'] ?? 0));

        $convDelta = $convAfter - $convBefore;

        // Anti-starvation (floor-check mandate): a draft gate that halves admission has starved supply, even
        // if the conversion of the survivors rose. Surface it so a flag is not flipped on a vanity metric.
        $starvation = $admBefore > 0.0 && $admAfter < 0.5 * $admBefore;

        if ($starvation) {
            $verdict = 'starved';
        } elseif ($convDelta > $minDelta) {
            $verdict = 'improved';
        } elseif ($convDelta < -$minDelta) {
            $verdict = 'regressed';
        } else {
            $verdict = 'flat';
        }

        return [
            'admission_before' => round($admBefore, 4),
            'admission_after' => round($admAfter, 4),
            'admission_delta' => round($admAfter - $admBefore, 4),
            'conversion_before' => round($convBefore, 4),
            'conversion_after' => round($convAfter, 4),
            'conversion_delta' => round($convDelta, 4),
            'verdict' => $verdict,
            'starvation_risk' => $starvation,
        ];
    }

    /**
     * The saturation probe: read a J-curve on the in-distribution metric and the held-out transfer slice and
     * decide whether real headroom remains. Pure.
     *
     * @param  list<float>  $inDist    in-distribution J over successive rounds (e.g. dev/aggregate score)
     * @param  list<float>  $transfer  the SAME rounds' J on a held-out transfer slice (different task type)
     * @return array{in_dist_trend:float, transfer_trend:float, in_dist_climbing:bool, transfer_climbing:bool, verdict:string}
     */
    public static function saturation(array $inDist, array $transfer, float $minTrend = 0.01): array
    {
        $inTrend = self::trend($inDist);
        $trTrend = self::trend($transfer);
        $inClimbing = $inTrend > $minTrend;
        $trClimbing = $trTrend > $minTrend;

        if ($inClimbing && $trClimbing) {
            // both climbing => the gain GENERALIZES => a stronger optimizer / more investment can harvest it.
            $verdict = 'real_headroom';
        } elseif ($inClimbing && ! $trClimbing) {
            // in-distribution climbs while transfer is flat => proxy-overfit => at the Goodhart ceiling. More
            // optimization (or strong-model capex) only games the metric. STOP; widen the holdout instead.
            $verdict = 'goodhart_ceiling';
        } else {
            // in-distribution itself plateaus => shallow surface => little to harvest.
            $verdict = 'shallow';
        }

        return [
            'in_dist_trend' => round($inTrend, 4),
            'transfer_trend' => round($trTrend, 4),
            'in_dist_climbing' => $inClimbing,
            'transfer_climbing' => $trClimbing,
            'verdict' => $verdict,
        ];
    }

    /** Net per-step trend = (last - first) / steps. Pure; 0 for <2 points. */
    public static function trend(array $series): float
    {
        $values = array_values(array_map('floatval', $series));
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        return ($values[$n - 1] - $values[0]) / ($n - 1);
    }
}
