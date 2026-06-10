<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Digital-option fair value for the 5-minute Up/Down binary.
 *
 * P(Up) = P(S_end >= S_start) under a driftless lognormal over the remaining
 * window: Phi( ln(S_now/S_start) / (sigma * sqrt(tau)) ), with sigma the
 * per-second EWMA volatility of observed log returns.
 *
 * Two deliberate departures from the naive diffusion model, both anti-overclaim:
 *  - JUMP REGIME: after a |return| > jump_z sigmas move, sigma is inflated by
 *    jump_mult for jump_hold seconds. A pure diffusion model overprices mean
 *    reversion right after a jump — exactly the miscalibration that makes
 *    "fading a jump" long shots look EV-positive when they are not. Inflating
 *    sigma pushes FV toward 0.5 and shrinks phantom edge in that regime.
 *  - NUMERIC SAFETY: every input is clamped/guarded (no NaN/INF propagation,
 *    variance floor, z clamp) per the AAEOS numeric-safety lesson.
 */
final class FairValueEngine
{
    private float $ewmaVariancePerSecond = 0.0;

    private bool $seeded = false;

    private ?float $lastPrice = null;

    private ?float $lastTsSeconds = null;

    private float $jumpRegimeUntil = 0.0;

    public function __construct(
        private readonly float $lambda = 0.97,
        private readonly float $variancePerSecondFloor = 1.0e-11,
        private readonly float $jumpZ = 5.0,
        private readonly float $jumpHoldSeconds = 60.0,
        private readonly float $jumpSigmaMultiplier = 2.0,
    ) {}

    /**
     * Seed the variance estimator from equally-spaced (1s) closes.
     *
     * @param  list<float>  $secondCloses
     */
    public function seed(array $secondCloses): void
    {
        $squares = [];
        $previous = null;
        foreach ($secondCloses as $close) {
            $close = (float) $close;
            if (! is_finite($close) || $close <= 0.0) {
                continue;
            }
            if ($previous !== null) {
                $r = log($close / $previous);
                if (is_finite($r)) {
                    $squares[] = $r * $r;
                }
            }
            $previous = $close;
        }

        if ($squares !== []) {
            $this->ewmaVariancePerSecond = max(
                array_sum($squares) / count($squares),
                $this->variancePerSecondFloor,
            );
            $this->seeded = true;
        }

        if ($previous !== null) {
            $this->lastPrice = $previous;
        }
    }

    public function observe(float $price, float $tsSeconds): void
    {
        if (! is_finite($price) || $price <= 0.0 || ! is_finite($tsSeconds)) {
            return;
        }

        if ($this->lastPrice !== null && $this->lastTsSeconds !== null && $tsSeconds > $this->lastTsSeconds) {
            $dt = min(max($tsSeconds - $this->lastTsSeconds, 0.05), 60.0);
            $r = log($price / $this->lastPrice);
            if (is_finite($r)) {
                $sigmaDt = sqrt(max($this->currentVariance(), $this->variancePerSecondFloor) * $dt);
                if ($sigmaDt > 0.0 && abs($r) / $sigmaDt >= $this->jumpZ) {
                    $this->jumpRegimeUntil = $tsSeconds + $this->jumpHoldSeconds;
                }

                $perSecondSquare = ($r * $r) / $dt;
                if (is_finite($perSecondSquare)) {
                    $this->ewmaVariancePerSecond = $this->seeded
                        ? $this->lambda * $this->ewmaVariancePerSecond + (1.0 - $this->lambda) * $perSecondSquare
                        : $perSecondSquare;
                    $this->seeded = true;
                }
            }
        }

        $this->lastPrice = $price;
        $this->lastTsSeconds = $tsSeconds;
    }

    /**
     * @return array{fv_up: float, sigma_per_second: float, regime: string}|null
     */
    public function fairValueUp(float $sNow, float $sStart, float $tauSeconds, ?float $nowSeconds = null): ?array
    {
        if (! is_finite($sNow) || ! is_finite($sStart) || $sNow <= 0.0 || $sStart <= 0.0) {
            return null;
        }
        if (! $this->seeded) {
            return null;
        }

        $now = $nowSeconds ?? $this->lastTsSeconds ?? 0.0;
        $inJump = $now < $this->jumpRegimeUntil;

        $sigma = sqrt(max($this->currentVariance(), $this->variancePerSecondFloor));
        if ($inJump) {
            $sigma *= max(1.0, $this->jumpSigmaMultiplier);
        }

        $tau = max($tauSeconds, 0.001);
        $denominator = $sigma * sqrt($tau);
        if (! is_finite($denominator) || $denominator <= 0.0) {
            return null;
        }

        $z = log($sNow / $sStart) / $denominator;
        if (! is_finite($z)) {
            return null;
        }
        $z = max(-12.0, min(12.0, $z));

        $fv = self::standardNormalCdf($z);
        $fv = max(0.001, min(0.999, $fv));

        return [
            'fv_up' => $fv,
            'sigma_per_second' => $sigma,
            'regime' => $inJump ? 'jump' : 'diffusion',
        ];
    }

    public function isSeeded(): bool
    {
        return $this->seeded;
    }

    private function currentVariance(): float
    {
        return max($this->ewmaVariancePerSecond, $this->variancePerSecondFloor);
    }

    /**
     * Abramowitz–Stegun 7.1.26 erf approximation (|error| < 1.5e-7), PHP has no erf().
     */
    public static function standardNormalCdf(float $z): float
    {
        $x = $z / M_SQRT2;
        $sign = $x < 0.0 ? -1.0 : 1.0;
        $x = abs($x);

        $t = 1.0 / (1.0 + 0.3275911 * $x);
        $poly = $t * (0.254829592 + $t * (-0.284496736 + $t * (1.421413741 + $t * (-1.453152027 + $t * 1.061405429))));
        $erf = 1.0 - $poly * exp(-$x * $x);

        return 0.5 * (1.0 + $sign * $erf);
    }
}
