<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

final class AtlasContextStringListNormalizer
{
    /**
     * @return array<int,string>
     */
    public static function uniqueTrimmedStrings(mixed $value, bool $lowercase = false): array
    {
        $values = is_array($value) ? $value : [];

        return self::uniqueMappedStrings($values, static fn (mixed $item): mixed => $item, $lowercase);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    public static function uniqueMappedStrings(array $values, callable $map, bool $lowercase = false): array
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

        return array_values(array_unique($strings));
    }
}
