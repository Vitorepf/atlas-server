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
}
