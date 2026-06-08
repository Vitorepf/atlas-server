<?php

namespace App\Services\Ai\Hermes\Acp;

use Closure;

/**
 * Per-worker pool of warm `hermes acp` sessions.
 *
 * An Atlas queue worker is a long-lived process. Without this pool every job
 * cold-starts `hermes acp` (~5s: proc spawn + ACP `initialize` + MCP server
 * registration), so the robust transport pays its worst cost on every single
 * call. The pool keeps ONE warm process per key (binary|cwd|HERMES_HOME) alive
 * and reuses it, so only the FIRST job in a worker pays the cold start; every
 * later job draws a fresh `session/new` on the warm process (≈sub-second).
 *
 * Safety invariants (a half-consumed ACP stream must never bleed into the next
 * job, and no `hermes acp` process may be orphaned):
 *  - REUSE-ONLY-AFTER-CLEAN-SUCCESS: {@see AtlasHermesAcpRuntime::runPooled}
 *    calls {@see release} only after a clean result; ANY failure/timeout calls
 *    {@see discard} (stop + drop), so the next job re-warms a fresh process.
 *  - DEAD-PROCESS DETECTION: {@see lease} drops + recreates a session whose
 *    channel is no longer running.
 *  - BOUNDED LIFETIME: a session is recycled after `maxServed` prompts so the
 *    long-lived process's memory cannot grow unbounded.
 *  - CLEAN SHUTDOWN: every channel is stopped on worker exit (registered once),
 *    so a killed/finished worker leaves no orphaned `hermes acp`.
 *
 * Single worker ⇒ sequential jobs ⇒ no locking required. This object is bound as
 * a container singleton so it survives across the jobs of one worker process.
 */
class HermesAcpSessionPool
{
    /** @var array<string,HermesAcpWarmSession> */
    private array $sessions = [];

    private bool $shutdownRegistered = false;

    public function __construct(private readonly int $maxServed = 50) {}

    /**
     * Return the warm session for $key — reusing the live one, or building a fresh
     * (un-initialized) session via $factory when none exists or the held one died.
     * The caller (the runtime) is responsible for initializing + using it, then
     * calling {@see release} on clean success or {@see discard} on any failure.
     *
     * @param  Closure():HermesAcpChannel  $factory
     */
    public function lease(string $key, Closure $factory): HermesAcpWarmSession
    {
        $this->registerShutdown();

        $existing = $this->sessions[$key] ?? null;
        if ($existing !== null) {
            if ($existing->channel->isRunning()) {
                return $existing;
            }
            $this->discard($key); // process died between jobs → drop + rebuild below
        }

        return $this->sessions[$key] = new HermesAcpWarmSession($factory(), $key);
    }

    /**
     * Keep the session warm for the next job, or recycle it once it has served the
     * configured number of prompts. Only ever call this after a CLEAN success.
     */
    public function release(HermesAcpWarmSession $session): void
    {
        if ($this->maxServed > 0 && $session->served >= $this->maxServed) {
            $this->discard($session->key);
        }
        // otherwise it is already held in $this->sessions and stays warm.
    }

    /** Stop + forget the session for $key (failure, death, or recycle). Idempotent. */
    public function discard(string $key): void
    {
        $session = $this->sessions[$key] ?? null;
        if ($session === null) {
            return;
        }
        unset($this->sessions[$key]);
        $session->channel->stop();
    }

    /** Stop every warm session (worker shutdown / explicit cleanup). */
    public function shutdown(): void
    {
        foreach (array_keys($this->sessions) as $key) {
            $this->discard($key);
        }
    }

    /** Number of warm sessions currently held (observability/tests). */
    public function activeCount(): int
    {
        return count($this->sessions);
    }

    private function registerShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(fn () => $this->shutdown());
    }
}
