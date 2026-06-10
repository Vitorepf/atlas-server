<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusScalarNormalizer
{
    public static function clampUnit(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    public static function finiteClampUnit(float $value): float
    {
        if (! is_finite($value) || $value < 0.0) {
            return 0.0;
        }

        return $value > 1.0 ? 1.0 : $value;
    }

    public static function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    public static function finiteNumberOrZero(mixed $value): float
    {
        return self::finiteNumber($value) ? (float) $value : 0.0;
    }

    public static function numberOrDefault(mixed $value, float $default): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadNumberOrDefault(array $payload, string $key, float $default): float
    {
        return self::numberOrDefault($payload[$key] ?? $default, $default);
    }

    public static function finiteNumericOrZero(mixed $value): float
    {
        if (! self::numericValue($value)) {
            return 0.0;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : 0.0;
    }

    public static function numericValue(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    public static function numericIntOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return 0;
    }

    public static function nonNegativeInt(mixed $value): int
    {
        $normalized = (int) $value;

        return $normalized < 0 ? 0 : $normalized;
    }

    public static function intWithDefaultAndMin(mixed $value, int $default, int $min): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = (int) $value;

        return $normalized < $min ? $min : $normalized;
    }

    public static function riskLevelOrMedium(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['critical', 'high', 'medium', 'low'], true) ? $normalized : 'medium';
    }

    public static function severityOrMedium(mixed $value): string
    {
        if (! is_string($value)) {
            return 'medium';
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['critical', 'high', 'medium', 'low'], true) ? $normalized : 'medium';
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function lowerChoice(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function nullableLowerChoice(mixed $value, array $allowed): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function trimmedChoice(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = trim((string) $value);

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadBool(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? $default;

        return $value === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadInt(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadFiniteInt(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? (int) $value : $default;
        }

        if (is_string($value) && is_numeric($value)) {
            return is_finite((float) $value) ? (int) $value : $default;
        }

        return $default;
    }

    public static function saturatingInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return self::saturatingFloatToInt($value, $default);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return self::saturatingFloatToInt((float) trim($value), $default);
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadSaturatingInt(array $payload, string $key, int $default): int
    {
        return self::saturatingInt($payload[$key] ?? $default, $default);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadFloat(array $payload, string $key, float $default): float
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadFiniteFloat(array $payload, string $key, float $default): float
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : $default;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : $default;
        }

        return $default;
    }

    public static function intRepresentableFloat(float $value): bool
    {
        return is_finite($value)
            && $value >= (float) PHP_INT_MIN
            && $value < (float) PHP_INT_MAX;
    }

    private static function saturatingFloatToInt(float $value, int $default): int
    {
        if (! is_finite($value)) {
            return $default;
        }

        if ($value >= 9223372036854775808.0) {
            return PHP_INT_MAX;
        }

        if ($value < -9223372036854775808.0) {
            return PHP_INT_MIN;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    public static function payloadString(array $payload, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadTrimmedString(array $payload, string $key, string $default = ''): string
    {
        return self::payloadString($payload, [$key], $default);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadRawString(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public static function trimmedStringOnly(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    public static function collapsedLowerWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(trim($value))));
    }

    public static function canonicalDocId(string $value): string
    {
        $normalized = strtolower(trim($value));

        return preg_replace('/\.md$/', '', $normalized) ?? $normalized;
    }

    public static function stringOrNumber(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payloadStringOrNumber(array $payload, string $key): string
    {
        return self::stringOrNumber($payload[$key] ?? null);
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function nullableStringOnly(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
