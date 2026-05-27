<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-747 release seam. Implemented by AreaFocusDevForgeReleaseService.
 */
interface OwnerQueueReleaseGate
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function release(array $input): array;
}
