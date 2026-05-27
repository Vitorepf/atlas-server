<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-748/AP-740 outcome bridge seam. Implemented by
 * StewardshipOutcomeEvidenceBridgeService.
 */
interface StewardshipOutcomeProjector
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input = []): array;
}
