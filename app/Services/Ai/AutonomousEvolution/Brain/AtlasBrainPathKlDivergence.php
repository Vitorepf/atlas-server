<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH KL DIVERGENCE — metrics-optimization organ. Computes Kullback-Leibler divergence of the
 * observed path distribution vs a target distribution (defaults to uniform over the keys of the
 * observed dict). Bigger KL = bigger drift from target. Asymmetric: D_KL(observed || target).
 * Returns INF if observed has mass on a path the target excludes.
 *
 * Pure decision over already-built path counts. No IO. Pétreo: réu would clamp KL on its
 * preferred path so drift went unreported.
 */
final class AtlasBrainPathKlDivergence
{
    public const SCHEMA = 'atlas.brain.path_kl_divergence.v1';

    /**
     * @param  array<string, int>  $observedCounts
     * @param  array<string, float>|null  $target  null = uniform over observed keys
     * @return array{schema:string, kl:float, total:int}
     */
    public function compute(array $observedCounts, ?array $target = null): array
    {
        $total = array_sum(array_map(static fn ($c) => max(0, (int) $c), $observedCounts));
        if ($total <= 0) {
            return ['schema' => self::SCHEMA, 'kl' => 0.0, 'total' => 0];
        }
        $observed = [];
        foreach ($observedCounts as $k => $c) {
            $observed[(string) $k] = max(0, (int) $c) / $total;
        }
        if ($target === null) {
            $n = count($observed);
            $target = array_fill_keys(array_keys($observed), 1.0 / max(1, $n));
        }
        $kl = 0.0;
        foreach ($observed as $k => $p) {
            if ($p <= 0.0) {
                continue;
            }
            $q = (float) ($target[$k] ?? 0.0);
            if ($q <= 0.0) {
                $kl = INF;
                break;
            }
            $kl += $p * log($p / $q, 2);
        }

        return ['schema' => self::SCHEMA, 'kl' => is_finite($kl) ? round($kl, 4) : $kl, 'total' => $total];
    }
}
