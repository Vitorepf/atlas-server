<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasAeosValueNormalizer
{
    public const FIELD_MEDIUM = 'medium';
    public const FIELD_HIGH = 'high';
    public const FIELD_LOW = 'low';
    public const FIELD_R0 = 'R0';
    public const FIELD_R1 = 'R1';
    public const FIELD_R2 = 'R2';
    public const FIELD_R3 = 'R3';
    public const FIELD_R4 = 'R4';
    public const FIELD_R5 = 'R5';
    public static function stringOrNull(mixed $value): ?string
    {
        return AiValueNormalizer::trimmedStringOrNull($value);
    }

    public static function lowerStringOrNull(mixed $value): ?string
    {
        $string = self::stringOrNull($value);

        return $string === null ? null : AiValueNormalizer::lowerTrimmedString($string);
    }

    public static function trimmedString(mixed $value): string
    {
        return AiValueNormalizer::trimmedStringOrNull($value) ?? '';
    }

    public static function lowerString(mixed $value): string
    {
        return AiValueNormalizer::lowerTrimmedString(self::trimmedString($value));
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
            if (($trimmed = AiValueNormalizer::trimmedStringOrNull($item)) !== null) {
                $out[] = $trimmed;
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
        $risk = is_string($value) ? AiValueNormalizer::upperTrimmedString($value) : '';

        return in_array($risk, [self::FIELD_R0, self::FIELD_R1, self::FIELD_R2, self::FIELD_R3, self::FIELD_R4, self::FIELD_R5], true) ? $risk : $fallback;
    }

    public static function lowMediumHighRisk(string $value, string $fallback = self::FIELD_MEDIUM): string
    {
        return self::lowercaseAllowed($value, [self::FIELD_LOW, self::FIELD_MEDIUM, self::FIELD_HIGH], $fallback);
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function lowercaseAllowed(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = is_string($value) ? AiValueNormalizer::lowerTrimmedString($value) : '';

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function trimmedAllowed(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = AiValueNormalizer::trimmedStringOrNull($value) ?? '';

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }
}
