<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Linear-interpolation percentile over a pre-sorted numeric list (q in [0,1]).
 *
 * Full-pass reuse: de-duplicates private percentile() in latency/pack ledgers.
 *
 * @param  list<float|int>  $sortedValues
 */
final class ArrayPercentile
{
    public static function ofSorted(array $sortedValues, float $q): ?float
    {
        if ($sortedValues === []) {
            return null;
        }
        if (count($sortedValues) === 1) {
            return (float) $sortedValues[0];
        }

        $rank = $q * (count($sortedValues) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sortedValues[$low];
        }

        return $sortedValues[$low] + (($sortedValues[$high] - $sortedValues[$low]) * ($rank - $low));
    }
}
