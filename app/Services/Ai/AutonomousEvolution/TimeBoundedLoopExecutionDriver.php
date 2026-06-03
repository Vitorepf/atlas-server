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
        $deadline = max(1, $this->hardSeconds);

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
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () use (&$timedOut, $self): void {
            $timedOut = true;
            $self->killChildren();
            throw new LoopAttemptTimedOut;
        });

        $start = microtime(true);
        pcntl_alarm($deadline);
        try {
            $result = $this->inner->attempt($surfaceId, $workspace, $intent, $userConstraints, $surfaceHints);
        } catch (LoopAttemptTimedOut) {
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
     * Best-effort kill of the hung provider subprocess tree spawned under this PHP
     * process. Direct children are reaped by ppid; the parallel worker additionally
     * runs in its own process group (posix_setsid) so a group kill catches grandchildren.
     */
    public function killChildren(): void
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
}
