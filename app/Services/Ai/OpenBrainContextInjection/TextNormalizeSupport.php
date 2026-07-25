<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextInjection;

/**
 * Open Brain scalar SSOT (full-pass pure peel).
 *
 * Fuses the historically duplicated helpers:
 * - stringValue / scalarString (non-empty string with default)
 * - nullableString / MCP `string` / Pack stringOpt (trim empty → null)
 * - stringList with optional take limit (injection default 24; expansion 12; MCP unlimited)
 * - sortedStrings for hash-stable policy lists
 *
 * Pure: no I/O, no DB, no config. Callers that intentionally lowercase or
 * accept only is_string keep their own boundary (e.g. ContextInjection::string).
 */
final class TextNormalizeSupport
{
    public static function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
    }

    /**
     * Alias of {@see stringValue} used by ContextExpansion and residual peels.
     */
    public static function scalarString(mixed $value, string $default = ''): string
    {
        return self::stringValue($value, $default);
    }

    public static function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    public static function stringOpt(array $opts, string $key): ?string
    {
        return self::nullableString($opts[$key] ?? null);
    }

    /**
     * Unique trimmed scalar strings.
     *
     * @param  int|null  $limit  Max items after unique (null or <=0 = unlimited). Default 24 preserves injection peel.
     * @return list<string>
     */
    public static function stringList(mixed $value, ?int $limit = 24): array
    {
        if (is_scalar($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $collection = collect($value)
            ->filter(static fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->values();

        if ($limit !== null && $limit > 0) {
            $collection = $collection->take($limit);
        }

        return $collection->all();
    }

    /**
     * @param  list<string>  $strings
     * @return list<string>
     */
    public static function sortedStrings(array $strings): array
    {
        return collect($strings)
            ->filter(static fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
