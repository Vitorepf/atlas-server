<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

/**
 * AP-759 owner sandbox runtime runner seam. Implemented by
 * StewardshipOwnerSandboxRuntimeRunnerService. This is the only component
 * allowed to run an allowlisted owner CLI command inside the AP-756 worktree.
 */
interface OwnerSandboxRuntimeRunner
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function project(array $input): array;
}
