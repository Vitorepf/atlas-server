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
        $argv = self::buildArgv($taskId, $workerId, $leaseSeconds, $scenarios, $workspaceRoot);

        $process = new Process($argv, base_path(), null, null, max(30.0, (float) $timeoutSeconds));
        $process->start();

        return new LoopWorkerHandle($process, $taskId, $workerId);
    }

    /**
     * The exact argv a grind worker is spawned with. Extracted (and public) so the runtime hardening
     * is pinned by a test instead of only proven by a slow live OOM/wedge.
     *
     * Two non-default php flags are load-bearing:
     *  - memory_limit=2048M: a grind materializes + iterates code through the model; SignalAnalyzer and
     *    ADEP passes routinely exceed PHP's 128M default and OOM-die mid-grind (the worker is "spawned"
     *    but never settles → the pool stalls forever). 2048M matches the sibling phpunit/regression
     *    Processes so a real grind can complete.
     *  - pcov.enabled=0: the dev php.ini loads pcov (coverage) globally, which replaces the Zend execute
     *    hook and instruments EVERY opcode of the whole app/ tree — pure overhead for a worker that
     *    requests no coverage. Left on it crawls the worker and inflates memory (a sampled wedged
     *    supervisor sat 100% inside php_pcov_execute_ex). Disabling it per-spawn restores native speed
     *    without touching the operator's global php.ini (coverage runs still get pcov).
     *
     * @return list<string>
     */
    public static function buildArgv(
        string $taskId,
        string $workerId,
        int $leaseSeconds,
        int $scenarios,
        string $workspaceRoot,
    ): array {
        $argv = [
            PHP_BINARY, '-d', 'memory_limit=2048M', '-d', 'pcov.enabled=0', 'artisan', 'atlas:loop:grind-task',
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

        return $argv;
    }
}
