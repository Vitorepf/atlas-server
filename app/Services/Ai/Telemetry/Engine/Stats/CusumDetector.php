<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

/**
 * Two-sided tabular CUSUM for change-point detection.
 *
 *   C+_t = max(0, C+_{t-1} + (X_t - μ_ref - k))
 *   C-_t = max(0, C-_{t-1} - (X_t - μ_ref + k))
 *   alarm if C+ > h OR C- > h
 *
 * k = 0.5·σ_ref (detect 1-sigma shifts)
 * h = 4·σ_ref (ARL_0 ≈ 465 — effectively zero false alarms in daily use)
 *
 * Reference (μ_ref, σ_ref) computed from oldest half of the window.
 * Minimum window: 15 days total with at least 7 in reference half.
 */
class CusumDetector
{
    public const MIN_WINDOW = 15;
    public const MIN_REFERENCE_HALF = 7;
    private const K_MULTIPLIER = 0.5;
    private const H_MULTIPLIER = 4.0;

    /**
     * @param  array<int,float>  $series
     * @return array{
     *   fired: bool,
     *   change_point_index: ?int,
     *   direction: string,
     *   magnitude_sigma: ?float,
     *   suppressed: bool,
     *   suppression_reason: ?string
     * }
     */
    public function detect(array $series): array
    {
        $n = count($series);
        if ($n < self::MIN_WINDOW) {
            return $this->suppress('window_below_min:'.self::MIN_WINDOW);
        }

        $refHalfLength = intdiv($n, 2);
        if ($refHalfLength < self::MIN_REFERENCE_HALF) {
            return $this->suppress('reference_half_too_small');
        }

        $reference = array_slice($series, 0, $refHalfLength);
        $muRef = array_sum($reference) / $refHalfLength;
        $sigmaRef = sqrt(array_sum(array_map(fn ($x) => ($x - $muRef) ** 2, $reference)) / $refHalfLength);

        if ($sigmaRef < 1e-6) {
            return $this->suppress('reference_variance_too_low');
        }

        $k = self::K_MULTIPLIER * $sigmaRef;
        $h = self::H_MULTIPLIER * $sigmaRef;

        $cPlus = 0.0;
        $cMinus = 0.0;
        $cPlusZero = 0;
        $cMinusZero = 0;
        $firedIndex = null;
        $direction = 'none';
        $magnitudeSigma = null;

        for ($i = $refHalfLength; $i < $n; $i++) {
            $x = $series[$i];
            $cPlus = max(0.0, $cPlus + ($x - $muRef - $k));
            $cMinus = max(0.0, $cMinus - ($x - $muRef + $k));

            if ($cPlus === 0.0) {
                $cPlusZero = $i;
            }
            if ($cMinus === 0.0) {
                $cMinusZero = $i;
            }

            if ($cPlus > $h) {
                $firedIndex = $cPlusZero;
                $direction = 'upward';
                $magnitudeSigma = round($cPlus / $sigmaRef, 4);
                break;
            }
            if ($cMinus > $h) {
                $firedIndex = $cMinusZero;
                $direction = 'downward';
                $magnitudeSigma = round($cMinus / $sigmaRef, 4);
                break;
            }
        }

        return [
            'fired' => $firedIndex !== null,
            'change_point_index' => $firedIndex,
            'direction' => $direction,
            'magnitude_sigma' => $magnitudeSigma,
            'suppressed' => false,
            'suppression_reason' => null,
        ];
    }

    private function suppress(string $reason): array
    {
        return [
            'fired' => false,
            'change_point_index' => null,
            'direction' => 'none',
            'magnitude_sigma' => null,
            'suppressed' => true,
            'suppression_reason' => $reason,
        ];
    }
}
