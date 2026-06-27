<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HINT ENTROPY — single-scalar Shannon entropy over the action_hint distribution. Higher = more diverse
 * cascade; lower = perseveration. Returned in BITS (log base 2) AND as a normalized [0..1] ratio against
 * the maximum entropy for the observed alphabet size (so 1.0 = perfectly uniform across hints used).
 *
 * Why a scalar adds leverage on top of the histogram + transition matrix + outcome analyzer: those
 * surface STRUCTURE (which hint, which pair, which rate); entropy compresses the whole distribution to
 * one number an operator/threshold/cron can read at a glance and a future organ can trend over time.
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits the entropy (else it'd dampen the metric
 * to mask perseveration).
 */
final class AtlasBrainHintEntropy
{
    public const SCHEMA = 'atlas.brain.hint_entropy.v1';

    /**
     * @param  array{by_hint?:list<array{hint:string, count:int, pct?:int}>}  $histogram  output of AtlasBrainBriefHistogram
     * @return array{schema:string, total:int, alphabet:int, bits:float, normalized:float}
     */
    public function compute(array $histogram): array
    {
        $rows = (array) ($histogram['by_hint'] ?? []);
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) ($r['count'] ?? 0);
        }

        if ($total <= 0 || $rows === []) {
            return ['schema' => self::SCHEMA, 'total' => 0, 'alphabet' => 0, 'bits' => 0.0, 'normalized' => 0.0];
        }

        $bits = 0.0;
        foreach ($rows as $r) {
            $count = (int) ($r['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $p = $count / $total;
            $bits -= $p * (log($p) / log(2));
        }

        $alphabet = count(array_filter($rows, static fn (array $r): bool => (int) ($r['count'] ?? 0) > 0));
        // Max entropy for alphabet of size k = log2(k); normalize so 1.0 = perfectly uniform across k.
        $max = $alphabet > 1 ? (log($alphabet) / log(2)) : 0.0;
        $normalized = $max > 0 ? round($bits / $max, 4) : 0.0;

        return [
            'schema' => self::SCHEMA,
            'total' => $total,
            'alphabet' => $alphabet,
            'bits' => round($bits, 4),
            'normalized' => $normalized,
        ];
    }
}
