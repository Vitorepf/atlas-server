<?php

declare(strict_types=1);

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * Shared byte-identical helpers de-duplicated across this family (firstNonEmpty).
 */
trait ContentInputNormalization
{
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $c) {
            if (trim($c) !== '') {
                return trim($c);
            }
        }

        return '';
    }
}
