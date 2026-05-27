<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-749 owner-specific consumption gate seam. Implemented by
 * AreaFocusOwnerQueueConsumptionGateService.
 */
interface OwnerQueueConsumptionGate
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array;
}
