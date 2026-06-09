<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusStringListNormalizer
{
    /**
     * Preserve the loop contract used by evidence/path/command payloads:
     * string values only, source order, no trim, no dedupe.
     *
     * @return list<string>
     */
    public static function preserveStrings(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item): string => is_string($item) ? $item : '',
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * Normalize operator/runtime string lists where surrounding whitespace is not
     * part of the payload contract.
     *
     * @return list<string>
     */
    public static function trimmedStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
