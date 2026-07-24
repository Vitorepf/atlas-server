<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Support\NonEmptyStringOrFallback;
/**
 * Shared byte-identical helper de-duplicated across this family (nonEmpty).
 */
trait ProductModeStringHelper
{
    private function nonEmpty(string $value, string $fallback): string
    {
        return NonEmptyStringOrFallback::of($value, $fallback);
    }
}
