<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Normalize a timestamp string to UTC ISO-8601 (c), defaulting to now. */
final class UtcIsoTimestamp
{
    public static function normalize(?string $ts): string
    {
        if ($ts === null || trim($ts) === '') {
            return gmdate('c');
        }

        try {
            return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (Throwable) {
            return gmdate('c');
        }
    }
}
