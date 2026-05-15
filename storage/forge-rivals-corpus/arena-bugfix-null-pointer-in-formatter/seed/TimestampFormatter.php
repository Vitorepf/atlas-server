<?php

declare(strict_types=1);

namespace App\Support\Formatter;

/**
 * Initial state with the null bug.
 */
final class TimestampFormatter
{
    public static function format(?string $iso): string
    {
        // Bug: explodes when $iso is null.
        return (new \DateTimeImmutable($iso))->format('Y-m-d H:i');
    }
}
