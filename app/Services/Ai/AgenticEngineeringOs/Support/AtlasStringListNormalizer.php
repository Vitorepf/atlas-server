<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Support;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasStringListNormalizer
{
    public const FIELD_TYPE = 'type';
    public const FIELD_NAME = 'name';
    public const FIELD_ID = 'id';
    public const FIELD_KIND = 'kind';
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
        $strings = [];
        foreach (AiValueNormalizer::arrayOrEmpty($values) as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            $value = AiValueNormalizer::trimmedStringOrNull($value) ?? '';
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
        $strings = [];
        foreach (AiValueNormalizer::arrayOrEmpty($values) as $value) {
            if (AiValueNormalizer::trimmedStringOrNull($value) !== null) {
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
        return AiStringListNormalizer::nonEmptyStrings(AiValueNormalizer::arrayOrEmpty($values));
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
            $strings[] = AiValueNormalizer::lowerTrimmedString($value);
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
        $strings = [];
        foreach (AiValueNormalizer::arrayOrEmpty($values) as $value) {
            if (($ref = AiValueNormalizer::trimmedStringOrNull($value)) !== null) {
                $strings[] = $ref;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $ref = $value[self::FIELD_KIND] ?? ($value[self::FIELD_TYPE] ?? ($value[self::FIELD_ID] ?? ($value[self::FIELD_NAME] ?? null)));
            if (($ref = AiValueNormalizer::trimmedStringOrNull($ref)) !== null) {
                $strings[] = $ref;
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
