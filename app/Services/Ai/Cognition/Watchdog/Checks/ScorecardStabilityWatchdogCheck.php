<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;

final readonly class ScorecardStabilityWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private AtlasAcosWatchdogHealthService $health) {}

    public function id(): string
    {
        return 'pip-08.scorecard_stability';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return $this->health->toCheckResult(
            $this->health->pipelineStabilityReport(),
            'scorecard_stability_failed',
            'PIP-08 scorecard stability has not reached a green pipeline series.',
        );
    }
}
