<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

final class NumericRangeOverlapContradictionDetector
{
    /**
     * Classify the relationship between two inclusive numeric ranges
     * [aMin, aMax] and [bMin, bMax].
     *
     * Ordered rules (evaluated top to bottom, first match wins):
     *  (1) aMin > aMax OR bMin > bMax            -> invalid
     *  (2) aMin === bMin AND aMax === bMax       -> equal
     *  (3) aMax < bMin OR bMax < aMin            -> disjoint
     *  (4) aMax === bMin OR bMax === aMin        -> touching   (not disjoint)
     *  (5) aMin <= bMin AND aMax >= bMax         -> a_contains_b
     *  (6) bMin <= aMin AND bMax >= aMax         -> b_contains_a
     *  (7) otherwise                             -> overlap
     *
     * @return string one of: invalid, disjoint, touching, overlap, a_contains_b, b_contains_a, equal
     */
    public function detect(float $aMin, float $aMax, float $bMin, float $bMax): string
    {
        if ($aMin > $aMax || $bMin > $bMax) {
            return 'invalid';
        }

        if ($aMin === $bMin && $aMax === $bMax) {
            return 'equal';
        }

        if ($aMax < $bMin || $bMax < $aMin) {
            return 'disjoint';
        }

        if ($aMax === $bMin || $bMax === $aMin) {
            return 'touching';
        }

        if ($aMin <= $bMin && $aMax >= $bMax) {
            return 'a_contains_b';
        }

        if ($bMin <= $aMin && $bMax >= $aMax) {
            return 'b_contains_a';
        }

        return 'overlap';
    }
}
