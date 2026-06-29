<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerCountPlanner;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see LoopWorkerCountPlanner::plan()} at the operator surface: for a requested worker count it
 * emits the planned SAFE worker count (clamped by available CPU and the configured ceiling) as a deterministic
 * fact. Read-only.
 */
final class AtlasLoopWorkerCountPlanCommand extends Command
{
    protected $signature = 'atlas:loop:worker-count-plan {--requested=} {--json}';

    protected $description = 'Read-only safe worker-count plan for a requested fan-out (CPU- and ceiling-clamped).';

    public function handle(LoopWorkerCountPlanner $planner): int
    {
        $requested = $this->option('requested');
        if ($requested === null || ! is_numeric($requested)) {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'worker-count-plan requires a numeric --requested',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $requestedInt = (int) $requested;

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.worker_count_plan.v1',
            'requested' => $requestedInt,
            'planned_workers' => $planner->plan($requestedInt),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
