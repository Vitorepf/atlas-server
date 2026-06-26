<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Canonicalizes a nested array structure restricted to arrays only: lists keep
 * their order, associative maps are ksort'd, and recursion descends into both
 * for nested arrays only (scalars never appear at this layer). The body is
 * byte-identical to the canonicalization GROUP 1 in the god-class services it
 * replaces; extracted as a trait so future collaborators can drop it in
 * without depending on any concrete service's constants or properties.
 */
trait RecursivelyCanonicalizesArrays
{
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                if (is_array($item)) {
                    $value[$index] = $this->canonicalize($item);
                }
            }

            return $value;
        }
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
