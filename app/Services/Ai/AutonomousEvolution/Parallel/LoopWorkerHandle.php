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

    public function __construct(
        private readonly Process $process,
        public readonly string $taskId,
        public readonly string $workerId,
    ) {
        $this->startedAt = microtime(true);
    }

    public function isFinished(): bool
    {
        return ! $this->process->isRunning();
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function timedOut(): bool
    {
        try {
            $this->process->checkTimeout();

            return false;
        } catch (Throwable) {
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
