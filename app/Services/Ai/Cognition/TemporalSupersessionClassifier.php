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
    /**
     * @param  int  $tsA  epoch seconds for assertion A
     * @param  int  $tsB  epoch seconds for assertion B
     * @param  bool  $sameKey  whether A and B address the same logical key
     */
    public function classify(int $tsA, int $tsB, bool $sameKey): string
    {
        if ($sameKey === false) {
            return 'coexist';
        }

        if ($tsA > $tsB) {
            return 'a_supersedes_b';
        }

        if ($tsB > $tsA) {
            return 'b_supersedes_a';
        }

        return 'tie_same_timestamp';
    }
}
