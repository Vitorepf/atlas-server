<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge;

/**
 * Governed provider lifecycle seam for a real Forge cycle.
 *
 * Lifecycle operations are deliberately distinct from Kernel execution:
 * implementations may prepare/observe/cancel a provider run, but only the
 * shared Engineering Kernel execution port may mutate the workspace.
 */
interface ForgeProviderLifecyclePort
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function start(array $request): array;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function poll(array $request): array;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function heartbeat(array $request): array;

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function cancel(array $request): array;
}
