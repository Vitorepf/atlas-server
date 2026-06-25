<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Canonical recursive ksort — the single source of truth for the deterministic
 * canonicalization that feeds evidence-hashes. Body byte-identical to the 30+
 * AgentControlPlane* copies it consolidates: detects associative arrays via
 * array_keys !== range(0..n-1), recurses into sub-arrays, ksorts only on
 * associative arrays, preserves list ordering.
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
