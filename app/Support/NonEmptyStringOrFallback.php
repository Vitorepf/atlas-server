<?php

declare(strict_types=1);

namespace App\Support;

/** Return trimmed string or fallback when empty. */
final class NonEmptyStringOrFallback
{
    public static function of(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }
}
