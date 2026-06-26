<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Provides a recursive ksort by reference for arrays (associative and list).
 *
 * Single source of truth for the ksortRecursive pattern used across the
 * self-construction audit services. Consumers call ksortRecursiveByReference
 * to get in-place canonical ordering of an array tree.
 */
trait KsortsArraysByReference
{
    /**
     * Recursively ksort each level of $arr in place. List values are
     * preserved as-is (no reordering). Operates by reference so callers
     * don't have to capture the return value.
     */
    private function ksortRecursiveByReference(array &$arr): void
    {
        foreach ($arr as $key => $entry) {
            if (is_array($entry)) {
                $this->ksortRecursiveByReference($arr[$key]);
            }
        }
        if ($arr !== [] && ! array_is_list($arr)) {
            ksort($arr);
        }
    }
}