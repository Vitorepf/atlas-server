<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-758 owner runtime execution adapter seam. Implemented by
 * StewardshipOwnerRuntimeExecutionAdapterService.
 */
interface OwnerRuntimeExecutionAdapter
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array;
}
