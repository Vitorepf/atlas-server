<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextInjection;

/**
 * Pure string/list normalize helpers for Open Brain injection (full-pass peel).
 */
final class TextNormalizeSupport
{
    public static function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values()
            ->take(24)
            ->all();
    }

    /**
     * @param  list<string>  $strings
     * @return list<string>
     */
    public static function sortedStrings(array $strings): array
    {
        return collect($strings)
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
