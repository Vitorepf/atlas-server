<?php

namespace App\Services\Ai\Concerns;

use Carbon\CarbonImmutable;
use Throwable;

final class RateLimitParser
{
    /**
     * @return array{provider_reset_at: ?CarbonImmutable, reset_hint: ?string}
     */
    public static function extract(string $stdout, string $stderr): array
    {
        $text = $stdout."\n".$stderr;

        // 1. "May 5th, 2026 10:24 AM" or "May 5th 10:24 AM" (year optional, ordinal optional)
        if (preg_match('/(?:try\s+again\s+at|reset(?:\s+at)?)\s+([A-Z][a-z]+\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\s+\d{1,2}:\d{2}(?:\s*(?:AM|PM))?)/i', $text, $m) === 1) {
            return self::result($m[1], self::parseDate(self::normalizeOrdinals($m[1])));
        }

        // 2. ISO 8601: "2026-05-05T10:24:00Z" or "2026-05-05 10:24"
        if (preg_match('/(?:try\s+again\s+at|reset(?:\s+at)?)\s+(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}(?::\d{2})?(?:Z|[+\-]\d{2}:?\d{2})?)/i', $text, $m) === 1) {
            return self::result($m[1], self::parseDate($m[1]));
        }

        // 3. Relative: "try again in 47 minutes" / "in 2 hours" / "in 30 seconds"
        if (preg_match('/try\s+again\s+in\s+(\d+)\s+(second|minute|hour|day)s?/i', $text, $m) === 1) {
            $delta = (int) $m[1];
            $unit = strtolower($m[2]).'s';
            $reset = CarbonImmutable::now()->add($unit, $delta);

            return self::result($m[0], $reset);
        }

        // 4. Compact relative: "try again in 2h 15m" / "in 1h" / "in 45m"
        if (preg_match('/try\s+again\s+in\s+(?:(\d+)h\s*)?(?:(\d+)m)?(?:\s|\.|$)/i', $text, $m) === 1) {
            $hours = (int) ($m[1] ?? 0);
            $mins = (int) ($m[2] ?? 0);
            if ($hours > 0 || $mins > 0) {
                $reset = CarbonImmutable::now()->addHours($hours)->addMinutes($mins);

                return self::result($m[0], $reset);
            }
        }

        return ['provider_reset_at' => null, 'reset_hint' => null];
    }

    private static function normalizeOrdinals(string $value): string
    {
        return preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $value) ?? $value;
    }

    private static function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{provider_reset_at: ?CarbonImmutable, reset_hint: ?string}
     */
    private static function result(string $hint, ?CarbonImmutable $resetAt): array
    {
        return [
            'provider_reset_at' => $resetAt,
            'reset_hint' => trim($hint) === '' ? null : trim($hint),
        ];
    }
}
