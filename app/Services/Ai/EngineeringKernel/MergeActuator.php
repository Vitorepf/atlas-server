<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: perform scoped land/canary/revert operations only when the
 * Governor authorizes them.
 *
 * Owns: the mechanical act of reverting (or landing/canarying) a scoped change once authorized.
 * Must never own: the authorization decision itself — admission, landing, canary and rollback
 * policy belong to the Governor; this mechanism only carries out what it is told.
 */
interface MergeActuator
{
    /**
     * @return array<string,mixed>
     */
    public function act(AuthorizedMergeAction $action): array;

    /**
     * @return array<string,mixed>
     */
    public function revert(string $taskPacketId, bool $dryRun = true): array;
}
