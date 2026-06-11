<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Services\Ai\Support\AiStringListNormalizer;

final class AtlasAaeosStringListNormalizer
{
    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function trimmedStrings(mixed $values): array
    {
        return AiStringListNormalizer::trimmedStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function trimmedScalarValues(mixed $values): array
    {
        return AiStringListNormalizer::trimmedScalarValues($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function trimmedStringOrIntValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function nonBlankStringOrIntValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;
            } elseif (is_int($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function strings(mixed $values): array
    {
        return AiStringListNormalizer::strings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function nonEmptyArrayStrings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return AiStringListNormalizer::nonEmptyStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function nonEmptyStrings(mixed $values): array
    {
        return AiStringListNormalizer::nonEmptyStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function uniqueNonEmptyStrings(mixed $values): array
    {
        return AiStringListNormalizer::uniqueNonEmptyStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function nonBlankStrings(mixed $values): array
    {
        return AiStringListNormalizer::nonBlankStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function uniqueTrimmedStrings(mixed $values): array
    {
        return AiStringListNormalizer::uniqueTrimmedStrings($values);
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function lowerTrimmedStrings(mixed $values): array
    {
        $strings = [];
        foreach (self::trimmedStrings($values) as $value) {
            $strings[] = strtolower($value);
        }

        return $strings;
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function uniqueLowerTrimmedStrings(mixed $values): array
    {
        return array_values(array_unique(self::lowerTrimmedStrings($values)));
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    public static function stringsFromArtifactRefs(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value !== '') {
                    $strings[] = $value;
                }

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $ref = $value['kind'] ?? ($value['type'] ?? ($value['id'] ?? ($value['name'] ?? null)));
            if (is_string($ref) && trim($ref) !== '') {
                $strings[] = trim($ref);
            }
        }

        return $strings;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function uniqueSortedStrings(array $values): array
    {
        return AiStringListNormalizer::uniqueSortedStrings($values);
    }
}
