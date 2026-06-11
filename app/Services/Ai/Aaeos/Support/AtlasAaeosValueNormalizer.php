<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Support;

final class AtlasAaeosValueNormalizer
{
    public static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function riskCodeR0ToR5(mixed $value, string $fallback): string
    {
        $risk = strtoupper(is_string($value) ? trim($value) : '');

        return in_array($risk, ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'], true) ? $risk : $fallback;
    }

    public static function lowMediumHighRisk(string $value, string $fallback = 'medium'): string
    {
        return self::lowercaseAllowed($value, ['low', 'medium', 'high'], $fallback);
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function lowercaseAllowed(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function trimmedAllowed(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = is_string($value) ? trim($value) : '';

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }
}
