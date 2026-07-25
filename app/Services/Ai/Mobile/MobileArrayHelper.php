<?php

declare(strict_types=1);

namespace App\Services\Ai\Mobile;

use Illuminate\Support\Str;

/**
 * Shared byte-identical helper de-duplicated across this family (array/scalars).
 */
trait MobileArrayHelper
{
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || ! preg_match('/^\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private function booleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return is_numeric($value) && (int) $value === 1;
    }

    private function humanLabel(string $value): string
    {
        return Str::of($value)
            ->replace(['_', '-'], ' ')
            ->squish()
            ->title()
            ->toString();
    }
}
