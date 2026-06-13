<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

/**
 * Spawns ONE bounded `atlas:loop:grind-task` worker process per task. Faked in tests so
 * the pool's spawn/harvest/bound/backpressure logic is provable without real grinds.
 */
interface LoopWorkerSpawnerContract
{
    public function spawn(
        string $campaignId,
        string $taskId,
        string $workerId,
        int $leaseSeconds,
        string $workspaceRoot,
        int $timeoutSeconds,
        int $scenarios,
    ): LoopWorkerHandle;
}
