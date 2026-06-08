<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Support;

/**
 * Deliberately-approximate token estimator (AP-813).
 *
 * Uses the well-worn ~4-chars-per-token heuristic. This is NOT a real tokenizer and
 * is only ever used for RELATIVE before/after reporting (ratio, saved) — never for
 * billing or a hard gate. Honest under-claim: it is labelled "approx" everywhere it
 * surfaces, so no measurement built on it is presented as exact.
 */
final class TokenEstimator
{
    public const CHARS_PER_TOKEN = 4;

    public static function estimate(string $text): int
    {
        $len = strlen($text);
        if ($len === 0) {
            return 0;
        }

        return (int) ceil($len / self::CHARS_PER_TOKEN);
    }
}
