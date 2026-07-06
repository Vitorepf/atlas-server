<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Concerns;

trait ExtractsScalarFields
{
    /** @param array<string, mixed> $fields */
    private function intValue(array $fields, string $key): int
    {
        $value = $fields[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    /** @param array<string, mixed> $fields */
    private function floatValue(array $fields, string $key): float
    {
        $value = $fields[$key] ?? 0.0;

        return is_float($value) || is_int($value) ? (float) $value : (float) $value;
    }

    /** @param array<string, mixed> $fields */
    private function stringValue(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $fields */
    private function boolValue(array $fields, string $key): bool
    {
        return ($fields[$key] ?? false) === true;
    }
}
