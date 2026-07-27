<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

/**
 * Classifies the temporal relationship between two timestamped assertions that
 * may or may not share the same logical key. Pure and deterministic: the verdict
 * is computed entirely from strict ordering of the epoch-second inputs.
 *
 * Verdicts:
 *  - coexist             two different keys; neither supersedes the other.
 *  - a_supersedes_b      same key and A is strictly newer than B.
 *  - b_supersedes_a      same key and B is strictly newer than A.
 *  - tie_same_timestamp  same key and identical timestamps.
 */
final class TemporalSupersessionClassifier
{
    public const RELATION_COEXIST = 'coexist';

    public const RELATION_A_SUPERSEDES_B = 'a_supersedes_b';

    public const RELATION_B_SUPERSEDES_A = 'b_supersedes_a';

    public const RELATION_TIE_SAME_TIMESTAMP = 'tie_same_timestamp';

    /**
     * @param  int  $tsA  epoch seconds for assertion A
     * @param  int  $tsB  epoch seconds for assertion B
     * @param  bool  $sameKey  whether A and B address the same logical key
     */
    public function classify(int $tsA, int $tsB, bool $sameKey): string
    {
        if ($sameKey === false) {
            return self::RELATION_COEXIST;
        }

        if ($tsA > $tsB) {
            return self::RELATION_A_SUPERSEDES_B;
        }

        if ($tsB > $tsA) {
            return self::RELATION_B_SUPERSEDES_A;
        }

        return self::RELATION_TIE_SAME_TIMESTAMP;
    }
}
