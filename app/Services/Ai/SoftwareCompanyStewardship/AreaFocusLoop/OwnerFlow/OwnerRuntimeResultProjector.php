<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-750 owner runtime result bridge seam. Implemented by
 * StewardshipOwnerRuntimeResultBridgeService.
 */
interface OwnerRuntimeResultProjector
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array;
}
