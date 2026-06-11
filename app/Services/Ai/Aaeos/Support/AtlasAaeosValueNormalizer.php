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

    public static function lowerStringOrNull(mixed $value): ?string
    {
        $string = self::stringOrNull($value);

        return $string === null ? null : strtolower($string);
    }

    public static function trimmedString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function lowerString(mixed $value): string
    {
        return strtolower(self::trimmedString($value));
    }

    public static function isNonBlankString(mixed $value): bool
    {
        return self::stringOrNull($value) !== null;
    }

    /**
     * @return list<string>
     */
    public static function trimmedStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    public static function uniqueTrimmedStringList(mixed $value): array
    {
        return array_values(array_unique(self::trimmedStringList($value)));
    }

    /**
     * @return list<string>
     */
    public static function castStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
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
