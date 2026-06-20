<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * CRITIC GUARD — the per-attempt wall-clock kill that closes the unguarded hang.
 *
 * {@see LoopExecutionDriver::attempt()} takes no timeout: a single wedged provider
 * subprocess would hang the entire 24h run forever. This DECORATOR runs the inner
 * attempt under a hard deadline. If it overruns, it kills the hung child processes
 * and returns ['status' => 'timed_out'] so the scenario is counted as a failed
 * attempt and the loop advances instead of stalling.
 *
 * Provider-agnostic: it decorates whatever {@see LoopExecutionDriver} is bound and
 * names no provider. It composes with any inner driver. With pcntl available it is a
 * true interrupt+kill; without it, it degrades to an honest post-hoc timeout (the
 * attempt is marked timed_out if it returns past the deadline) — never worse than the
 * unguarded status quo.
 */
final class TimeBoundedLoopExecutionDriver implements LoopExecutionDriver
{
    public function __construct(
        private readonly LoopExecutionDriver $inner,
        private readonly int $hardSeconds,
    ) {}

    public function attempt(
        string $surfaceId,
        string $workspace,
        string $intent,
        array $userConstraints,
        array $surfaceHints,
    ): array {
        $deadline = $this->deadlineSeconds($surfaceHints);
        $progress = $this->progressCallback($surfaceHints);

        if (! function_exists('pcntl_async_signals')) {
            // No pcntl: honest post-hoc timeout. We cannot interrupt a synchronous call,
            // but we can refuse to let a slow attempt count as a success.
            $start = microtime(true);
            $result = $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
            if ((microtime(true) - $start) >= $deadline) {
                $result['status'] = 'timed_out';
                $result['timed_out'] = true;
            }

            return $result;
        }

        $self = $this;
        $timedOut = false;
        $previous = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGALRM) : SIG_DFL;
        $start = microtime(true);
        $progressEvery = $this->progressTickSeconds($surfaceHints);
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use (&$timedOut, $self, $start, $deadline, $progressEvery, $progress): void {
            $elapsed = microtime(true) - $start;
            if ($elapsed >= $deadline) {
                $timedOut = true;
                $self->terminateChildren();
                throw new LoopAttemptTimedOut;
            }

            $self->emitProgress($progress, 'attempt_heartbeat', [
                'elapsed_seconds' => (int) ceil($elapsed),
                'remaining_seconds' => max(0, $deadline - (int) floor($elapsed)),
            ]);
            $self->scheduleAlarm($deadline, $start, $progressEvery);
        });

        $this->scheduleAlarm($deadline, $start, $progressEvery);
        try {
            $result = $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
        } catch (LoopAttemptTimedOut) {
            $this->killChildren();
            $result = ['status' => 'timed_out', 'timed_out' => true];
            $timedOut = true;
        } catch (Throwable $e) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, is_callable($previous) ? $previous : SIG_DFL);
            throw $e;
        } finally {
            pcntl_alarm(0);
        }
        pcntl_signal(SIGALRM, is_callable($previous) ? $previous : SIG_DFL);

        // The hard interrupt (pcntl alarm + killChildren) bounds a truly-infinite inner
        // call WHERE signal delivery fires; the post-hoc check is the always-on backstop
        // so an overrun is NEVER silently reported as a success, even if the signal was
        // not delivered (some sandboxes restart the syscall). Either path => timed_out.
        if ($timedOut || (microtime(true) - $start) >= $deadline) {
            $result['status'] = 'timed_out';
            $result['timed_out'] = true;
        }

        return $result;
    }

    /**
     * The configured hard limit is the ceiling; a caller may pass a smaller
     * per-attempt budget when it is carrying the remaining task/campaign time.
     *
     * @param  array<string,mixed>  $surfaceHints
     */
    private function deadlineSeconds(array $surfaceHints): int
    {
        $deadline = max(1, $this->hardSeconds);
        $requested = $surfaceHints['attempt_timeout_seconds'] ?? null;

        if (is_numeric($requested) && (int) $requested > 0) {
            return max(1, min($deadline, (int) $requested));
        }

        return $deadline;
    }

    /**
     * @param  array<string,mixed>  $surfaceHints
     */
    private function progressCallback(array $surfaceHints): ?callable
    {
        $callback = $surfaceHints['_progress_callback'] ?? null;

        return is_callable($callback) ? $callback : null;
    }

    /**
     * @param  array<string,mixed>  $surfaceHints
     */
    private function progressTickSeconds(array $surfaceHints): int
    {
        $requested = $surfaceHints['_progress_tick_seconds'] ?? null;
        if (is_numeric($requested) && (int) $requested > 0) {
            return max(1, (int) $requested);
        }

        try {
            return max(5, min(30, (int) config('atlas.loop.campaign.heartbeat_seconds', 30)));
        } catch (Throwable) {
            return 30;
        }
    }

    private function scheduleAlarm(int $deadline, float $start, int $progressEvery): void
    {
        $remaining = $deadline - (int) floor(microtime(true) - $start);
        pcntl_alarm(max(1, min($progressEvery, $remaining)));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function emitProgress(?callable $progress, string $stage, array $context): void
    {
        if ($progress === null) {
            return;
        }

        try {
            $progress(['stage' => $stage] + $context);
        } catch (Throwable) {
            // Progress is advisory: never change attempt correctness.
        }
    }

    /**
     * Best-effort kill of the hung provider subprocess tree spawned under this PHP
     * process. Direct children are reaped by ppid; the parallel worker additionally
     * runs in its own process group (posix_setsid) so a group kill catches grandchildren.
     */
    public function killChildren(): void
    {
        $this->terminateChildren();
        $this->reapChildren();
    }

    private function terminateChildren(): void
    {
        $pid = function_exists('getmypid') ? getmypid() : false;
        if ($pid === false) {
            return;
        }
        foreach (['TERM', 'KILL'] as $sig) {
            try {
                (new Process(['pkill', '-'.$sig, '-P', (string) $pid]))->setTimeout(5.0)->run();
            } catch (Throwable) {
                // pkill absent or nothing to kill — best effort only.
            }
        }
    }

    private function reapChildren(): void
    {
        if (! function_exists('pcntl_waitpid')) {
            return;
        }

        $deadline = microtime(true) + 1.0;
        do {
            $reaped = false;
            do {
                $pid = @pcntl_waitpid(-1, $status, WNOHANG);
                if ($pid > 0) {
                    $reaped = true;
                }
            } while ($pid > 0);

            if (! $reaped) {
                usleep(50_000);
            }
        } while (microtime(true) < $deadline);
    }
}
