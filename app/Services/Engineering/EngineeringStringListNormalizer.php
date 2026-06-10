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
}
