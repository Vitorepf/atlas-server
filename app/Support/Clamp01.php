<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Clamp a numeric value into [0.0, 1.0].
 *
 * Full-pass reuse for scattered private clamp01() helpers.
 */
final class Clamp01
{
    public static function of(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    public static function fromMixed(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return self::of((float) $value);
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return self::of((float) trim($value));
        }

        return 0.0;
    }
}
