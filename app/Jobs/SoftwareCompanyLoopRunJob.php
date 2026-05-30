<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Atlas Loop Command Surface · queued launcher for the AP-790 reliable 24h loop.
 *
 * The Loop Command Surface MUST NOT shell a detached process from a request: a
 * request-tied child (e.g. under `php artisan serve`) dies with the request, so
 * claiming "running" would be dishonest about durability. Instead the surface
 * enqueues THIS job on the dedicated `software_company_loop` queue and returns
 * status=enqueued — the loop is only ever reported "started" by the live lock
 * (run_state.lock.held), which flips true the moment a worker picks this up.
 *
 * This job never reimplements selection/execution/merge. It calls the EXISTING
 * Reliable24hLoopRunnerService::run() with the SAME input map the
 * AtlasSoftwareCompanyReliable24hLoopCommand CLI builds, so the queued path and
 * the CLI path are byte-identical to the runner. The runner's exclusive per
 * area/focus lock is the real guard: if a second run is enqueued while one
 * holds the lock, run() returns STATUS_LOCK_HELD and this job no-ops cleanly.
 * WithoutOverlapping adds a second, queue-level guard keyed by area:focus.
 *
 * Honesty/governance invariants preserved end-to-end:
 *   - real-or-blocked: the runner emits honest merge_performed/provider booleans;
 *     this job fabricates nothing.
 *   - dry_run is the default; execute (the destructive real path) only when the
 *     surface set execute=true explicitly (operator-confirmed).
 *   - RSI/EarnedAutonomy default-off and the forge owner-flow gates live inside
 *     the runner/AP-786 chain and are untouched here.
 */
final class SoftwareCompanyLoopRunJob implements ShouldQueue
{
    use Queueable;

    /** The dedicated queue a long-lived worker must consume (NOT the 90-min transcription queue). */
    public const QUEUE = 'software_company_loop';

    /** One attempt only — the runner is durable/resumable on its own ledger; never blind-retry a 24h loop. */
    public int $tries = 1;

    /**
     * Wall-clock ceiling for the worker process. The runner also honors its own
     * max_runtime_minutes budget; this is the hard SIGTERM backstop. Defaults to
     * 24h + a 10-min grace so a full-budget loop is never killed mid-cycle.
     */
    public int $timeout = 87000;

    /**
     * @param  array<string,mixed>  $input  the exact runner input map (area_id, focus, scope_profile,
     *                                       provider, model, repo_root, actor, execute, auto_merge,
     *                                       dry_run, budgets, continue_on_blocked, …).
     */
    public function __construct(
        public readonly array $input,
        public readonly string $areaId,
        public readonly string $focus,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Queue-level exclusivity per area/focus. dontRelease() means a duplicate
     * dispatch while one is running is dropped (not requeued) — the surface already
     * told the operator "queued"; we never pile up redundant 24h runs. The runner's
     * own lock remains the authoritative guard regardless of this middleware.
     *
     * @return array<int,object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('software_company_loop:'.$this->areaId.':'.$this->focus))
                ->dontRelease()
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(Reliable24hLoopRunnerService $runner): void
    {
        // The runner is the single owner of selection/execution/merge + the exclusive
        // lock, crash recovery, budgets and the append-only ledger. A STATUS_LOCK_HELD
        // return is a clean no-op (another worker already holds the area/focus lock).
        $runner->run($this->input);
    }
}
