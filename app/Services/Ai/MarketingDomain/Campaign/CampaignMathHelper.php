<?php

declare(strict_types=1);

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * Shared byte-identical helper de-duplicated across this family (clamp01).
 */
trait CampaignMathHelper
{
    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
