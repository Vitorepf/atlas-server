<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * FewShotConfidenceMeter — the "how much data do I actually have / still need" primitive (pillar 3).
 *
 * Elite media buyers act on little data WITHOUT fooling themselves on noise. This turns any (successes,
 * trials) pair — sales/clicks, checkouts/visits, watch-through/starts — into a Wilson 95% credible
 * interval, a verdict (no_data / noise / weak_signal / conclusive), and the n still needed to separate the
 * rate from a floor at 95%. It replaces hard-coded folk rules ("~50 conv + 7d") with a number computed for
 * the concrete case. Pure deterministic math, provider-free; consumed by BayesianKillScaleDecider and any
 * diagnostic that must say "this is signal" vs "this is noise".
 */
class FewShotConfidenceMeter
{
    private const Z = 1.96; // 95%

    /**
     * @return array{rate:float,ci_low:float,ci_high:float,width:float,verdict:string,n_needed:int,beats_floor:?bool}
     */
    public function assess(int $successes, int $trials, float $floor = 0.0): array
    {
        $successes = max(0, $successes);
        $trials = max(0, $trials);
        if ($trials === 0) {
            return ['rate' => 0.0, 'ci_low' => 0.0, 'ci_high' => 1.0, 'width' => 1.0, 'verdict' => 'no_data', 'n_needed' => $this->nNeeded(max($floor, 0.01), $floor), 'beats_floor' => null];
        }

        $p = min(1.0, $successes / $trials);
        [$lo, $hi] = $this->wilson($p, $trials);
        $width = $hi - $lo;

        $beatsFloor = null;
        if ($floor > 0) {
            if ($lo > $floor) {
                $beatsFloor = true;
            } elseif ($hi < $floor) {
                $beatsFloor = false;
            }
        }

        $verdict = match (true) {
            $floor > 0 && $beatsFloor !== null => 'conclusive', // interval excludes the floor → decided
            $width > 0.25 => 'noise',
            $width > 0.12 => 'weak_signal',
            default => 'tight',
        };

        return [
            'rate' => round($p, 4),
            'ci_low' => round($lo, 4),
            'ci_high' => round($hi, 4),
            'width' => round($width, 4),
            'verdict' => $verdict,
            'n_needed' => $this->nNeeded($p > 0 ? $p : max($floor, 0.01), $floor),
            'beats_floor' => $beatsFloor,
        ];
    }

    /** Wilson score interval for a proportion (robust at small n / extreme p — unlike normal approx). */
    private function wilson(float $p, int $n): array
    {
        $z2 = self::Z ** 2;
        $denom = 1 + $z2 / $n;
        $center = ($p + $z2 / (2 * $n)) / $denom;
        $margin = (self::Z / $denom) * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n ** 2));

        return [max(0.0, $center - $margin), min(1.0, $center + $margin)];
    }

    /** Trials needed so the 95% half-width separates the rate from the floor (or a tight band if no floor). */
    private function nNeeded(float $p, float $floor): int
    {
        $p = min(max($p, 0.001), 0.999);
        // Target half-width: half the gap to the floor, or a tight absolute band when no/near floor.
        $gap = $floor > 0 ? abs($p - $floor) : $p * 0.5;
        $target = max($gap / 2, 0.01);
        $n = (self::Z ** 2) * $p * (1 - $p) / ($target ** 2);

        return (int) ceil($n);
    }
}
