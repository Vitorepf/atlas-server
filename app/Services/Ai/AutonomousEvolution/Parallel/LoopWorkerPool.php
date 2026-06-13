<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;

/**
 * A bounded, NON-BLOCKING worker pool — the "massive agent orchestration" width.
 *
 * SHIPS CONFIG-GATED OFF (`atlas.loop.parallel.enabled=false`): the serial single-worker
 * path is the proven default. The structure is built + tested so "go parallel" is a
 * measured flip, not a rebuild. {@see tick()} never blocks the supervisor: it harvests
 * finished workers, then spawns up to the free slots — subject to disk admission
 * (backpressure) — by atomically claiming tasks via the supplied callback. A crashed
 * worker never aborts the others (its task's lease simply expires and is reclaimed).
 */
final class LoopWorkerPool
{
    /** @var array<string, LoopWorkerHandle> taskId => handle */
    private array $handles = [];

    public function __construct(
        private readonly LoopWorkerSpawnerContract $spawner,
        private readonly AtlasLoopResourceGate $resourceGate,
    ) {}

    /**
     * Advance the pool by one non-blocking tick.
     *
     * @param  callable():?AtlasLoopTask  $claimNext  atomically claims the next task (already lease-stamped) or null
     * @return array{in_flight:int, spawned:int, settled:list<array<string,mixed>>, backpressured:bool}
     */
    public function tick(
        int $maxSlots,
        string $campaignId,
        callable $claimNext,
        int $leaseSeconds,
        int $timeoutSeconds,
        string $workspaceRootBase = '',
        int $scenarios = 0,
    ): array {
        $maxSlots = max(1, $maxSlots);

        // 1. Harvest finished workers.
        $settled = [];
        foreach ($this->handles as $taskId => $handle) {
            if ($handle->isFinished()) {
                $settled[] = $handle->summary();
                unset($this->handles[$taskId]);
            }
        }

        // 2. Spawn up to the free slots, subject to disk admission (never crash).
        $spawned = 0;
        $backpressured = false;
        while (count($this->handles) < $maxSlots) {
            $admit = $this->resourceGate->admitScenario(
                sys_get_temp_dir(),
                (int) config('atlas.loop.campaign.min_free_mb', 512),
                (int) config('atlas.loop.campaign.max_live_workspaces', 0),
            );
            if (! $admit['admit']) {
                $backpressured = true;
                break;
            }

            $task = $claimNext();
            if (! $task instanceof AtlasLoopTask) {
                break; // queue momentarily empty
            }

            $wsRoot = $workspaceRootBase !== '' ? rtrim($workspaceRootBase, '/').'/'.($task->claimed_by ?: 'w') : '';
            $this->handles[$task->id] = $this->spawner->spawn(
                $campaignId,
                $task->id,
                (string) ($task->claimed_by ?: 'pool-worker'),
                $leaseSeconds,
                $wsRoot,
                $timeoutSeconds,
                $scenarios,
            );
            $spawned++;
        }

        return [
            'in_flight' => count($this->handles),
            'spawned' => $spawned,
            'settled' => $settled,
            'backpressured' => $backpressured,
        ];
    }

    public function inFlight(): int
    {
        return count($this->handles);
    }

    /**
     * SIGTERM every in-flight worker for a kill-switch drain; their tasks' leases expire
     * and are reclaimed cleanly.
     *
     * @return list<array<string,mixed>>
     */
    public function drain(float $graceSeconds = 5.0): array
    {
        $drained = [];
        foreach ($this->handles as $taskId => $handle) {
            $handle->kill($graceSeconds);
            $drained[] = $handle->summary();
            unset($this->handles[$taskId]);
        }

        return $drained;
    }
}
