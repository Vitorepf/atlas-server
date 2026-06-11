<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship;

use App\Services\Ai\Support\AiStringListNormalizer;

final class StewardshipStringListNormalizer
{
    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function uniqueStrings(array $values): array
    {
        return AiStringListNormalizer::uniqueStrings($values);
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
     * @return list<string>
     */
    public static function nonEmptyStrings(mixed $value): array
    {
        return AiStringListNormalizer::nonEmptyStrings($value);
    }

    /**
     * @return list<string>
     */
    public static function uniqueNonEmptyStrings(mixed $value): array
    {
        return AiStringListNormalizer::uniqueNonEmptyStrings($value);
    }

    /**
     * @return list<string>
     */
    public static function trimmedStrings(mixed $value): array
    {
        return AiStringListNormalizer::trimmedStrings((array) $value);
    }

    /**
     * @return list<string>
     */
    public static function trimmedUniqueStrings(mixed $value): array
    {
        return AiStringListNormalizer::uniqueTrimmedStrings((array) $value);
    }

    /**
     * @return list<string>
     */
    public static function mappedNonEmptyStrings(mixed $value, callable $mapper): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            $mapped = (string) $mapper($item);
            if ($mapped !== '') {
                $strings[] = $mapped;
            }
        }

        return $strings;
    }

    /**
     * Mirrors array_filter(array_map('strval', ...)): falsey strings, including
     * "0", are intentionally removed for legacy projection compatibility.
     *
     * @return list<string>
     */
    public static function uniqueTruthyStringifiedValues(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) $value))));
    }

    /**
     * @return list<string>
     */
    public static function uniqueMappedTruthyStringValues(mixed $value, callable $mapper): array
    {
        $strings = [];
        foreach ((array) $value as $item) {
            $mapped = (string) $mapper($item);
            if ($mapped !== '' && $mapped !== '0') {
                $strings[] = $mapped;
            }
        }

        return self::uniqueStrings($strings);
    }
}
