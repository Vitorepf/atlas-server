<?php

declare(strict_types=1);

namespace App\Support\Formatter;

final class TimestampFormatter
{
    /**
     * BUG: applies the UTC offset twice. The DateTimeImmutable is built
     * without a timezone hint, then converted to UTC; this strips 3-6h
     * depending on the user's PHP default TZ.
     */
    public static function format(string $iso): string
    {
        $dt = new \DateTimeImmutable($iso);
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));

        return $utc->format('Y-m-d\TH:i:s\Z');
    }
}
