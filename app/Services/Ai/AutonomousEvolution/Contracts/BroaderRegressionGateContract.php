<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Contracts;

/**
 * Broader-regression gate seam (restored — the cd018c6b3f sweep deleted this interface while
 * FOUR live consumers still reference it: the AppServiceProvider binding, both auto-merge
 * services, and the production gate itself; without it, resolving anything that pulls
 * AtlasLoopOperatorReviewQueueService — including the LIVE InboxActionRegistry — fatals).
 *
 * Contract: read-only, fail-CLOSED verdict over a repo tree — never touches git/main.
 */
interface BroaderRegressionGateContract
{
    /**
     * @param  list<string>  $changedFiles  repo-relative changed paths
     * @return array<string,mixed>  verdict envelope; `passed` false on ANY red or on inability to run
     */
    public function evaluate(string $repoRoot, array $changedFiles): array;
}
