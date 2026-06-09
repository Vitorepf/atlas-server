<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * AP-815 · W-10 — per-workspace index lock.
 *
 * Prevents two index/build runs from racing on the SAME workspace (the autonomous loop
 * and a manual `index-code` firing together would otherwise tear the graph). Uses
 * Laravel's atomic cache lock (cross-process when the cache driver supports it) and
 * holds the lock instance so release() targets the same owner. A lock factory can be
 * injected for deterministic, driver-free tests.
 *
 * Fail-closed: if the lock backend errors, acquire() returns false (we never pretend to
 * hold a lock we don't). [php] Kernel concern — orchestration, not data.
 */
class CodeGraphIndexLock
{
    /** @var Closure(string,int):object */
    private Closure $lockFactory;

    /** @var array<string,object> workspaceId => held lock instance (owner-scoped release). */
    private array $held = [];

    /**
     * @param  (Closure(string,int):object)|null  $lockFactory  name,ttl => object with get():bool + release():void
     */
    public function __construct(?Closure $lockFactory = null)
    {
        $this->lockFactory = $lockFactory ?? static fn (string $name, int $ttl): object => Cache::lock($name, $ttl);
    }

    public function acquire(string $workspaceId, int $ttlSeconds = 600): bool
    {
        if (isset($this->held[$workspaceId])) {
            return true; // re-entrant within this instance/process
        }

        try {
            $lock = ($this->lockFactory)($this->key($workspaceId), max(1, $ttlSeconds));
            $ok = (bool) $lock->get();
        } catch (Throwable) {
            return false; // fail-closed
        }

        if ($ok) {
            $this->held[$workspaceId] = $lock;
        }

        return $ok;
    }

    public function release(string $workspaceId): void
    {
        $lock = $this->held[$workspaceId] ?? null;
        if ($lock === null) {
            return;
        }

        try {
            $lock->release();
        } catch (Throwable) {
            // best-effort — TTL will reap it anyway.
        }

        unset($this->held[$workspaceId]);
    }

    public function isHeld(string $workspaceId): bool
    {
        return isset($this->held[$workspaceId]);
    }

    /**
     * Run $fn while holding the workspace lock. $fn NEVER runs unless the lock was
     * acquired (that is the whole point — no concurrent indexing).
     *
     * @return array{acquired:bool,result:mixed}
     */
    public function withLock(string $workspaceId, callable $fn, int $ttlSeconds = 600): array
    {
        if (! $this->acquire($workspaceId, $ttlSeconds)) {
            return ['acquired' => false, 'result' => null];
        }

        try {
            return ['acquired' => true, 'result' => $fn()];
        } finally {
            $this->release($workspaceId);
        }
    }

    private function key(string $workspaceId): string
    {
        return 'atlas:code-graph:index-lock:'.($workspaceId !== '' ? $workspaceId : 'default');
    }
}
