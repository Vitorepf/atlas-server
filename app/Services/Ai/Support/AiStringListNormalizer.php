<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

final class AiStringListNormalizer
{
    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    public static function uniqueStrings(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * @return array<int,string>
     */
    public static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function stringsFromArrayCast(mixed $value): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @param  array<int,string>  ...$values
     * @return array<int,string>
     */
    public static function uniqueMergedStrings(array ...$values): array
    {
        return self::uniqueStrings(array_merge(...$values));
    }

    /**
     * @param  array<int,string>  ...$values
     * @return array<int,string>
     */
    public static function uniqueSortedStrings(array ...$values): array
    {
        $unique = self::uniqueMergedStrings(...$values);
        sort($unique, SORT_STRING);

        return $unique;
    }

    /**
     * @return array<int,string>
     */
    public static function nonEmptyStrings(mixed $value): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function nonBlankStrings(mixed $value): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueNonEmptyStrings(mixed $value): array
    {
        return self::uniqueStrings(self::nonEmptyStrings($value));
    }

    /**
     * Array-only legacy helper: preserve raw string values, drop only '', then dedupe.
     *
     * @return array<int,string>
     */
    public static function uniqueNonEmptyArrayStrings(mixed $value): array
    {
        return self::uniqueStrings(array_values(array_filter(
            self::strings($value),
            static fn (string $item): bool => $item !== '',
        )));
    }

    /**
     * @return array<int,string>
     */
    public static function trimmedStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function trimmedStringsFromArrayCast(mixed $value): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);
            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function castItemsToStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
    }

    /**
     * @return array<int,string>
     */
    public static function trimmedCastItemsToStrings(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), self::arrayItems($value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTrimmedCastItemsToStrings(mixed $value): array
    {
        return self::uniqueStrings(self::trimmedCastItemsToStrings($value));
    }

    /**
     * @return array<int,string>
     */
    public static function trimmedCastValues(mixed $value): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), (array) $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Preserves legacy collect((array) $value)->map(trim cast)->filter()->unique() semantics.
     *
     * @return array<int,string>
     */
    public static function uniqueTruthyTrimmedCastValues(mixed $value): array
    {
        return self::uniqueStrings(array_values(array_filter(self::trimmedCastValues($value))));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTrimmedStrings(mixed $value): array
    {
        return self::uniqueStrings(self::trimmedStrings($value));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTrimmedStringsFromArrayCast(mixed $value): array
    {
        return self::uniqueStrings(self::trimmedStringsFromArrayCast($value));
    }

    /**
     * Preserves PHP array_filter truthiness semantics used by older string-only list helpers.
     *
     * @return array<int,string>
     */
    public static function truthyTrimmedStrings(mixed $value): array
    {
        return array_values(array_filter(self::trimmedStrings($value)));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTruthyTrimmedStrings(mixed $value): array
    {
        return self::uniqueStrings(self::truthyTrimmedStrings($value));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueRecursiveTrimmedStrings(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $strings = array_merge($strings, self::uniqueRecursiveTrimmedStrings($item));
        }

        return self::uniqueTrimmedStrings($strings);
    }

    /**
     * @return array<int,string>
     */
    public static function trimmedScalarValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function trimmedScalarValuesFromArrayCast(mixed $value): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTrimmedScalarValues(mixed $value): array
    {
        return self::uniqueStrings(self::trimmedScalarValues($value));
    }

    /**
     * Preserves PHP array_filter truthiness semantics used by older list helpers.
     *
     * @return array<int,string>
     */
    public static function truthyTrimmedScalarValues(mixed $value): array
    {
        return array_values(array_filter(self::trimmedScalarValues($value)));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTruthyTrimmedScalarValues(mixed $value): array
    {
        return self::uniqueStrings(self::truthyTrimmedScalarValues($value));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueMappedStrings(array $values, callable $map): array
    {
        return self::uniqueStrings(array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $map($value)), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueMappedScalarStrings(array $values, callable $map, bool $lowercase = false): array
    {
        $strings = [];
        foreach ($values as $value) {
            $mapped = $map($value);
            if (! is_scalar($mapped)) {
                continue;
            }

            $string = trim((string) $mapped);
            if ($string === '') {
                continue;
            }

            $strings[] = $lowercase ? strtolower($string) : $string;
        }

        return self::uniqueStrings($strings);
    }

    /**
     * Preserves array_filter truthiness semantics for mapped scalar values.
     *
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueTruthyMappedScalarStrings(array $values, callable $map, bool $lowercase = false): array
    {
        $strings = [];
        foreach ($values as $value) {
            $mapped = $map($value);
            if (! is_scalar($mapped)) {
                continue;
            }

            $string = trim((string) $mapped);
            if (! $string) {
                continue;
            }

            $strings[] = $lowercase ? strtolower($string) : $string;
        }

        return self::uniqueStrings($strings);
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueSingleLineStrings(mixed $value): array
    {
        return array_values(array_filter(
            self::uniqueTrimmedScalarValues($value),
            static fn (string $line): bool => ! str_contains($line, "\n"),
        ));
    }

    /**
     * @return array<int,string>
     */
    public static function csvOrArray(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
        }

        return self::uniqueTrimmedStrings($value);
    }

    /**
     * @param  array<int,string>  ...$values
     * @return array<int,string>
     */
    public static function uniqueMergedTrimmedStrings(array ...$values): array
    {
        return self::uniqueTrimmedStrings(array_merge(...$values));
    }

    /**
     * @return array<int,mixed>
     */
    private static function arrayItems(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
