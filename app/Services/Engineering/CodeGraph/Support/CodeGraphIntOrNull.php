<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph\Support;

/**
 * Coerce mixed values to int for CodeGraph planners/views.
 *
 * Full-pass reuse: de-duplicates private intOrNull() across CodeGraph classes.
 */
final class CodeGraphIntOrNull
{
    public static function coerce(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }

            return (int) $float;
        }

        return null;
    }
}
