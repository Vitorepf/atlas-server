<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

use Symfony\Component\Process\Process;

/**
 * Spawns ONE bounded `atlas:loop:grind-task` worker process per task. NOT pcntl_fork
 * (forking a booted kernel duplicates PDO/container singletons) and NOT the Laravel
 * queue (no daemon runs, and it would fight the supervisor's lease logic) — a plain
 * Symfony Process, each its own OS process, posix_setsid'd inside the worker for
 * process-group isolation so a kill reaps the whole provider subtree.
 */
final class LoopWorkerSpawner implements LoopWorkerSpawnerContract
{
    public function spawn(
        string $campaignId,
        string $taskId,
        string $workerId,
        int $leaseSeconds,
        string $workspaceRoot,
        int $timeoutSeconds,
        int $scenarios,
    ): LoopWorkerHandle
    {
        $argv = [
            PHP_BINARY, 'artisan', 'atlas:loop:grind-task',
            '--task-id='.$taskId,
            '--worker='.$workerId,
            '--lease-seconds='.$leaseSeconds,
            '--json',
        ];
        if ($scenarios > 0) {
            $argv[] = '--scenarios='.$scenarios;
        }
        if ($workspaceRoot !== '') {
            $argv[] = '--workspace-root='.$workspaceRoot;
        }

        $process = new Process($argv, base_path(), null, null, max(30.0, (float) $timeoutSeconds));
        $process->start();

        return new LoopWorkerHandle($process, $taskId, $workerId);
    }
}
