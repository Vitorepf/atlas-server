<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

final class AiValueNormalizer
{
    public static function trimmedStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function trimmedScalarStringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Clamp a float into the closed unit interval [0, 1]. */
    public static function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /**
     * Coerce int/float/numeric-string into a finite float, else null.
     */
    public static function finiteFloatOrNull(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || ! is_numeric($value)) {
                return null;
            }
            $value = (float) $value;
        } elseif (is_int($value) || is_float($value)) {
            $value = (float) $value;
        } else {
            return null;
        }

        return is_finite($value) ? $value : null;
    }

    /**
     * @return array<mixed>
     */
    public static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Coerce a present value with PHP (bool) cast semantics; null stays null.
     */
    public static function boolOrNull(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    public static function trimmedString(mixed $value): string
    {
        return trim((string) $value);
    }

    public static function lowerTrimmedString(mixed $value): string
    {
        return strtolower(self::trimmedString($value));
    }

    public static function upperTrimmedString(mixed $value): string
    {
        return strtoupper(self::trimmedString($value));
    }
}
