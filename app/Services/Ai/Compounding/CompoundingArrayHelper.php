<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (array).
 */
trait CompoundingArrayHelper
{
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
