<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Canonicalizes a nested array structure: leaves scalars intact, preserves list
 * order, ksorts associative maps, and recurses into both. The body is
 * byte-identical to the canonicalization GROUP 2 in the god-class services it
 * replaces; extracted as a trait so future collaborators can drop it in
 * without depending on any concrete service's constants or properties.
 */
trait CanonicalizesNestedValues
{
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->canonicalize($v), $value);
        }
        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
