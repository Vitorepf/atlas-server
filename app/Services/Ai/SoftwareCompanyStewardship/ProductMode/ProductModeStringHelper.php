<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

/**
 * Shared byte-identical helper de-duplicated across this family (nonEmpty).
 */
trait ProductModeStringHelper
{
    private function nonEmpty(string $value, string $fallback): string
    {
        $value = trim($value);

        return $value !== '' ? $value : $fallback;
    }
}
