<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\MergeActuator;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;

/**
 * Engineering Kernel adapter proving the MergeActuator interface is real: pure delegation to
 * the already-proven task-lane actuator {@see AtlasTaskMergeActuator} — zero behavior change,
 * zero new validation, zero new safety logic. The caller supplies the concrete actuator
 * instance so it (not this adapter) chooses git repo root / ledger / allowed-files resolver.
 */
final class TaskLaneMergeActuatorAdapter implements MergeActuator
{
    public function __construct(private readonly AtlasTaskMergeActuator $actuator) {}

    /**
     * @return array<string,mixed>
     */
    public function revert(string $taskPacketId, bool $dryRun = true): array
    {
        return $this->actuator->revert($taskPacketId, $dryRun);
    }
}
