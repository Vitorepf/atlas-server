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
     * Preserve the legacy `(array) $value` + `is_string` loop contract used by
     * blocker/status payloads: scalar strings become one-item lists, empty
     * strings stay visible, and non-string values are rejected.
     *
     * @return list<string>
     */
    public static function coercedStringValues(mixed $value): array
    {
        return array_values(array_filter(
            (array) $value,
            static fn (mixed $item): bool => is_string($item),
        ));
    }

    /**
     * Preserve raw string payloads, including empty strings, while removing
     * duplicate entries in first-seen order.
     *
     * @return list<string>
     */
    public static function uniqueStringValues(mixed $value): array
    {
        return array_values(array_unique(self::coercedStringValues($value)));
    }

    /**
     * Merge raw string payload lists and remove duplicate entries in first-seen
     * order, preserving the same string-only contract as uniqueStringValues().
     *
     * @return list<string>
     */
    public static function uniqueMergedStringValues(mixed ...$values): array
    {
        $strings = [];

        foreach ($values as $value) {
            foreach (self::coercedStringValues($value) as $item) {
                $strings[] = $item;
            }
        }

        return self::uniqueStringValues($strings);
    }

    /**
     * Preserve legacy PHP truthiness filtering for blocker/list-presence checks.
     *
     * @return list<mixed>
     */
    public static function truthyValues(mixed $value): array
    {
        return array_values(array_filter((array) $value));
    }

    /**
     * Preserve legacy scalar-to-string mapping followed by PHP truthiness filtering.
     *
     * @return list<string>
     */
    public static function truthyStringifiedScalarValues(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            (array) $value,
        )));
    }

    /**
     * Preserve the legacy `(array)` + string-cast + unique contract used by
     * blocker payloads. Non-scalar entries become empty strings instead of
     * leaking array-to-string warnings into governance flows.
     *
     * @return list<string>
     */
    public static function uniqueStringifiedValues(mixed $value): array
    {
        $strings = [];

        foreach ((array) $value as $item) {
            $strings[] = is_scalar($item) || $item === null ? (string) $item : '';
        }

        return array_values(array_unique($strings));
    }

    /**
     * Preserve the legacy string-cast + PHP truthiness blocker contract while
     * removing duplicate entries.
     *
     * @return list<string>
     */
    public static function uniqueTruthyStringifiedValues(mixed $value): array
    {
        return array_values(array_filter(self::uniqueStringifiedValues($value)));
    }

    /**
     * Preserve raw string payloads but reject empty/blank entries using trim.
     * Use this only when surrounding whitespace is meaningful to the caller.
     *
     * @return list<string>
     */
    public static function preserveNonBlankStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? $item : '',
            $value,
        ), static fn (string $item): bool => trim($item) !== ''));
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

    /**
     * Split command output or text blocks into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    public static function trimmedLines(string $value): array
    {
        return self::trimmedStrings(explode("\n", $value));
    }

    /**
     * Normalize lists where the runtime contract treats duplicate entries as the
     * same boundary or evidence declaration.
     *
     * @return list<string>
     */
    public static function trimmedUniqueStrings(mixed $value): array
    {
        return array_values(array_unique(self::trimmedStrings(is_array($value) ? $value : [])));
    }

    /**
     * Cast list payload values to strings and drop values that become empty.
     *
     * @return list<string>
     */
    public static function stringifiedNonEmptyValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => (string) $item,
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * Normalize scalar payload values to trimmed strings and drop empty results.
     *
     * @return list<string>
     */
    public static function trimmedScalarValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    public static function normalizedId(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * Extract non-empty string fields from row-like payloads.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    public static function nonEmptyFieldValues(array $rows, string $field): array
    {
        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row[$field] ?? ''),
            $rows,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * Normalize mixed id lists whose contract accepts strings and numeric ids,
     * preserving first-seen order while removing empty values and duplicates.
     *
     * @return list<string>
     */
    public static function trimmedUniqueStringOrNumberValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $candidate = self::normalizedId($item);

            if ($candidate !== '') {
                $strings[] = $candidate;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * Normalize proof/theorem/invariant identifier lists. IDs use lexicographic
     * order so numeric-looking but textually distinct ids stay deterministic.
     *
     * @return list<string>
     */
    public static function normalizedUniqueSortedIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            $candidate = self::normalizedId($item);

            if ($candidate !== '') {
                $ids[] = $candidate;
            }
        }

        $unique = array_unique($ids);
        sort($unique, SORT_STRING);

        return array_values($unique);
    }
}
