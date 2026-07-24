<?php

declare(strict_types=1);

namespace App\Services\Ai\MarketingDomain\Content;

use App\Support\FirstNonEmptyString;
/**
 * Shared byte-identical helpers de-duplicated across this family (firstNonEmpty).
 */
trait ContentInputNormalization
{
    private function firstNonEmpty(array $candidates): string
    {
        return FirstNonEmptyString::from($candidates);
    }
}
