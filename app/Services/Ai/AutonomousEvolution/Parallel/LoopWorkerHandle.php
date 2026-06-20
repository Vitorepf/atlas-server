<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * A non-blocking handle over ONE `atlas:loop:grind-task` worker subprocess. Carries no
 * raw provider output (provider-safe) — only the task id, exit status and timing the
 * pool needs to harvest and account for the worker.
 */
final class LoopWorkerHandle
{
    private readonly float $startedAt;

    private readonly ?int $pid;

    private bool $timedOut = false;

    public function __construct(
        private readonly Process $process,
        public readonly string $taskId,
        public readonly string $workerId,
    ) {
        $this->startedAt = microtime(true);
        $this->pid = $process->getPid();
    }

    public function isFinished(): bool
    {
        if (! $this->process->isRunning()) {
            return true;
        }

        try {
            $this->process->checkTimeout();
        } catch (Throwable) {
            $this->timedOut = true;
            $this->terminateTree(0.2);

            return true;
        }

        return ! $this->process->isRunning();
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function timedOut(): bool
    {
        if ($this->timedOut) {
            return true;
        }

        try {
            $this->process->checkTimeout();

            return false;
        } catch (Throwable) {
            $this->timedOut = true;
            $this->terminateTree(0.2);

            return true;
        }
    }

    public function durationMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    /** SIGTERM the worker (and its process group via posix_setsid in the worker) for kill-switch drain. */
    public function kill(float $graceSeconds = 5.0): void
    {
        $this->terminateTree($graceSeconds);
    }

    private function terminateTree(float $graceSeconds = 5.0): void
    {
        $pid = $this->pid;
        if (is_int($pid) && $pid > 0 && function_exists('posix_kill')) {
            @posix_kill(-$pid, SIGTERM);
            @posix_kill($pid, SIGTERM);
            if ($graceSeconds > 0) {
                usleep((int) min(5_000_000, max(0, $graceSeconds * 1_000_000)));
            }
            @posix_kill(-$pid, SIGKILL);
            @posix_kill($pid, SIGKILL);
        }

        try {
            $this->process->stop($graceSeconds, SIGTERM);
        } catch (Throwable) {
            // already gone
        }
    }

    /**
     * @return array{task_id:string, worker_id:string, exit_code:?int, duration_ms:int, timed_out:bool, result:?array<string,mixed>}
     */
    public function summary(): array
    {
        return [
            'task_id' => $this->taskId,
            'worker_id' => $this->workerId,
            'exit_code' => $this->exitCode(),
            'duration_ms' => $this->durationMs(),
            'timed_out' => $this->timedOut(),
            'result' => $this->jsonResult(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function jsonResult(): ?array
    {
        $output = trim($this->process->getOutput());
        if ($output === '') {
            return null;
        }

        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
