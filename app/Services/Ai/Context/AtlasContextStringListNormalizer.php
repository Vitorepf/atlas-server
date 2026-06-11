<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Support\AiStringListNormalizer;

final class AtlasContextStringListNormalizer
{
    /**
     * @return array<int,string>
     */
    public static function stringsFromArrayCast(mixed $value): array
    {
        return AiStringListNormalizer::stringsFromArrayCast($value);
    }

    /**
     * @return array<int,string>
     */
    public static function uniqueTrimmedStrings(mixed $value, bool $lowercase = false): array
    {
        $values = is_array($value) ? $value : [];

        return AiStringListNormalizer::uniqueMappedScalarStrings(
            $values,
            static fn (mixed $item): mixed => $item,
            $lowercase,
        );
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueMappedStrings(array $values, callable $map, bool $lowercase = false): array
    {
        return AiStringListNormalizer::uniqueMappedScalarStrings($values, $map, $lowercase);
    }
}
