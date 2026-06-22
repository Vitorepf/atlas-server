<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * The seam between the reconciler's PURE governance logic and the messy real world (pgrep, kill, launchctl,
 * nohup). The reconciler decides WHAT must happen toward desired-state; the driver makes it happen. Swapping
 * a fake driver in tests lets the invariant ("OFF resists respawn; ON keeps alive; FREIO brakes") be proven
 * deterministically — never by starting a real campaign that would burn provider quota.
 */
interface FleetDriver
{
    /** Is at least one real process for this agent currently alive? */
    public function isAlive(string $agentKey): bool;

    /** PIDs of this agent's real processes (for status/registry). @return list<int> */
    public function pids(string $agentKey): array;

    /** Process-start epoch of the oldest live process (uptime), or null if none. */
    public function startedAtEpoch(string $agentKey): ?int;

    /**
     * Measured provider spend (USD) for this agent's current run, or 0.0 when not metered. Feeds the budget
     * FREIO; honest 0.0 means the budget brake is inert and the TTL brake governs.
     */
    public function spentUsd(string $agentKey): float;

    /** Start the agent toward desired-ON. $targetRef = the exact thing to (re)start (e.g. a campaign uuid). */
    public function start(string $agentKey, ?string $targetRef): void;

    /** Stop the agent (precise kill of its own processes / unload its launchd job). Never touches other agents. */
    public function stop(string $agentKey): void;
}
