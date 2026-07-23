<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;


final class NumericRangeOverlapContradictionDetector
{
    public const RELATION_INVALID = 'invalid';

    public const RELATION_EQUAL = 'equal';

    public const RELATION_DISJOINT = 'disjoint';

    public const RELATION_TOUCHING = 'touching';

    public const RELATION_A_CONTAINS_B = 'a_contains_b';

    public const RELATION_B_CONTAINS_A = 'b_contains_a';

    public const RELATION_OVERLAP = 'overlap';

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
            return self::RELATION_INVALID;
        }

        if ($aMin === $bMin && $aMax === $bMax) {
            return self::RELATION_EQUAL;
        }

        if ($aMax < $bMin || $bMax < $aMin) {
            return self::RELATION_DISJOINT;
        }

        if ($aMax === $bMin || $bMax === $aMin) {
            return self::RELATION_TOUCHING;
        }

        if ($aMin <= $bMin && $aMax >= $bMax) {
            return self::RELATION_A_CONTAINS_B;
        }

        if ($bMin <= $aMin && $bMax >= $aMax) {
            return self::RELATION_B_CONTAINS_A;
        }

        return self::RELATION_OVERLAP;
    }
}
