<?php

declare(strict_types=1);

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Support\Clamp01;
/**
 * Shared byte-identical helper de-duplicated across this family (clamp01).
 */
trait CampaignMathHelper
{
    private function clamp01(float $v): float
    {
        return Clamp01::of($v);
    }
}
