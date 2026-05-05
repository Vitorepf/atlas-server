<?php

namespace App\Services\Ai\Kernel\Repair;

final class RepairPayloadNormalizer
{
    public static function boolean(mixed $value, bool $default = true): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off', '' => false,
                default => $default,
            };
        }

        return (bool) $value;
    }

    /**
     * @return array<string,mixed>
     */
    public static function arrayPayload(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    public static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function string(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    public static function boundedFloat(mixed $value, float $default, float $min, float $max): float
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (float) $value));
    }
}
