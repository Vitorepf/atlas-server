<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Support\AiStringListNormalizer;
use InvalidArgumentException;

final class AtlasDevStringListNormalizer
{
    /**
     * @return list<string>
     */
    public static function uniqueStrings(array $value): array
    {
        return AiStringListNormalizer::uniqueStrings($value);
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return AiStringListNormalizer::strings($value);
    }

    /**
     * @return list<string>
     */
    public static function nonEmptyStrings(mixed $value): array
    {
        return AiStringListNormalizer::nonEmptyStrings($value);
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<string>
     */
    public static function requireNonBlankStrings(array $raw, string $field): array
    {
        $out = [];
        foreach ($raw as $i => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$field}[{$i}] must be a non-empty string.");
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $raw
     * @return list<string>
     */
    public static function requireNonEmptyStrings(array $raw, string $field): array
    {
        $out = [];
        foreach ($raw as $i => $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("{$field}[{$i}] must be a non-empty string.");
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function requireNonEmptyStringArray(mixed $raw, string $field, string $owner = ''): array
    {
        if (! is_array($raw)) {
            $prefix = $owner !== '' ? "{$owner}: " : '';

            throw new InvalidArgumentException("{$prefix}{$field} must be an array.");
        }

        return self::requireNonEmptyStrings(array_values($raw), $field);
    }

    /**
     * @return list<string>
     */
    public static function realStringsWithoutGeneratedPrefix(mixed $value, string $generatedPrefix): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item)
                && $item !== ''
                && ! str_starts_with($item, $generatedPrefix),
        ));
    }

    /**
     * @return list<string>
     */
    public static function trimmedStrings(mixed $value): array
    {
        return AiStringListNormalizer::trimmedStrings($value);
    }

    /**
     * @return list<string>
     */
    public static function uniqueTrimmedStrings(mixed $value): array
    {
        return AiStringListNormalizer::uniqueTrimmedStrings($value);
    }

    /**
     * @return list<string>
     */
    public static function uniqueRecursiveTrimmedStrings(mixed $value): array
    {
        return AiStringListNormalizer::uniqueRecursiveTrimmedStrings($value);
    }

    /**
     * @return list<string>
     */
    public static function uniqueTrimmedScalarValues(mixed $value): array
    {
        return AiStringListNormalizer::uniqueTrimmedScalarValues($value);
    }

    /**
     * @param  list<string>  ...$values
     * @return list<string>
     */
    public static function uniqueMergedStrings(array ...$values): array
    {
        return AiStringListNormalizer::uniqueMergedStrings(...$values);
    }

    /**
     * @param  list<string>  ...$values
     * @return list<string>
     */
    public static function uniqueSortedStrings(array ...$values): array
    {
        return AiStringListNormalizer::uniqueSortedStrings(...$values);
    }
}
