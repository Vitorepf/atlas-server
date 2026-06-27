<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH YIELD EWMA CROSSOVER — compounding organ. Per path, compares two EWMAs at different alphas
 * (short vs long horizon) and returns short - long. Positive = short-term outperforms long-term
 * (compounding ramping up); negative = short-term underperforms (decay starting); near-zero =
 * trend reversal point.
 *
 * Pure composition over already-built EWMA outputs at two alphas. No IO. Pétreo.
 */
final class AtlasBrainPathYieldEwmaCrossover
{
    public const SCHEMA = 'atlas.brain.path_yield_ewma_crossover.v1';

    /**
     * @param  array<string, array{ewma:float}>  $shortEwma
     * @param  array<string, array{ewma:float}>  $longEwma
     * @return array{schema:string, by_path:array<string, array{short:float, long:float, delta:float, label:string}>}
     */
    public function compute(array $shortEwma, array $longEwma): array
    {
        $paths = array_unique(array_merge(array_keys($shortEwma), array_keys($longEwma)));
        $out = [];
        foreach ($paths as $path) {
            $s = (float) ($shortEwma[$path]['ewma'] ?? 0.0);
            $l = (float) ($longEwma[$path]['ewma'] ?? 0.0);
            $delta = round($s - $l, 4);
            $label = abs($delta) < 0.05 ? 'crossover' : ($delta > 0 ? 'short_above_long' : 'short_below_long');
            $out[$path] = ['short' => round($s, 4), 'long' => round($l, 4), 'delta' => $delta, 'label' => $label];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }
}
