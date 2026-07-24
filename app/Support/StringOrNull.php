<?php

declare(strict_types=1);

namespace App\Support;

/** Trimmed non-empty string or null. */
final class StringOrNull
{
    public static function trimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
