<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignCostGovernor;

/**
 * Pure cost/spend/timeout arithmetic for the Atlas loop campaign supervisor.
 *
 * Extracted from AtlasLoopCampaignSupervisor to reduce the god-class. All
 * methods are pure — the cost governor instance is passed in for delegation.
 */
final class AtlasLoopCampaignCostMath
{
    /**
     * @return array<string,mixed>
     */
    public static function costGovernorDecision(AtlasLoopCampaignCostGovernor $costGovernor, AtlasLoopCampaign $campaign, ?int $scenarios): array
    {
        return $costGovernor->costGovernorDecision($campaign, $scenarios);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    public static function spendCentsFromResult(AtlasLoopCampaignCostGovernor $costGovernor, array $result): int
    {
        return $costGovernor->spendCentsFromResult($result);
    }

    /**
     * @param  list<array<string,mixed>>  $settled
     */
    public static function spendCentsFromWorkerSummaries(AtlasLoopCampaignCostGovernor $costGovernor, array $settled): int
    {
        return $costGovernor->spendCentsFromWorkerSummaries($settled);
    }

    /**
     * Compute the effective timeout for the next grind pass. The lesser of the
     * remaining budget and the per-task cap (with 60s safety floor on the cap
     * and 5s floor on the min). Without a budget, only the cap is used.
     */
    public static function grindTimeout(?int $budgetLeft, int $taskCap): int
    {
        $taskCap = max(60, $taskCap);

        return $budgetLeft === null ? $taskCap : max(5, min($budgetLeft, $taskCap));
    }
}