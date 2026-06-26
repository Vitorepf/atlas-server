<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Concerns;

/**
 * Canonical recursive ksort: lists (empty or sequential-int keys) preserve
 * element order; associative maps are key-sorted with ksort recursively
 * (depth-first). De-duplicates the body copy-pasted across the
 * SelfConstruction certification / orchestrator services.
 */
trait RecursivelyKsortsArrays
{
    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
