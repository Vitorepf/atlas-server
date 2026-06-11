<?php

namespace App\Services\Engineering;

final class EngineeringStringListNormalizer
{
    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueNonEmptyStrings(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueTruthyStringValues(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null,
            $values,
        ))));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueNonEmptyScalarStrings(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            is_array($value) ? $value : [$value],
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueBulletListStrings(mixed $value): array
    {
        if (! is_array($value)) {
            $value = preg_split('/\r?\n|- /', (string) $value) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item, " \t\n\r\0\x0B-") : '',
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueCommaSeparatedStrings(mixed $value, bool $lowercase = false): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static function (mixed $item) use ($lowercase): string {
                if (! is_scalar($item)) {
                    return '';
                }

                $string = trim((string) $item);

                return $lowercase ? strtolower($string) : $string;
            },
            $value,
        ), static fn (string $item): bool => $item !== '')));
    }
}
