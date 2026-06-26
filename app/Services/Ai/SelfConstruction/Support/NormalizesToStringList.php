<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Trims a list of mixed values, drops empty strings, and reindexes the result
 * as a list. Extracted from the duplicated $v-variant helper present in
 * multiple god-class collaborators; centralised here so any class can drop
 * the trait in without bringing any pre-existing constants, properties, or
 * collaborators.
 */
trait NormalizesToStringList
{
    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== ''));
    }
}
