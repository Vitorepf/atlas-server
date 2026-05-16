<?php

declare(strict_types=1);

namespace App\Support;

final class StringNormalizer
{
    /**
     * SEED IMPLEMENTATION (intentionally incomplete).
     *
     * The arm must replace the body so that:
     *   - leading and trailing whitespace are trimmed
     *   - internal whitespace runs collapse to a single space
     *   - ASCII letters are lowercased
     *   - non-ASCII codepoints survive byte-for-byte
     *   - the function is idempotent and pure.
     */
    public static function canonical(string $value): string
    {
        return $value;
    }
}
