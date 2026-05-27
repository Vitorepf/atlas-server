<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

interface AreaFocusBranchSandboxMaterializer
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function materialize(array $input): array;
}
