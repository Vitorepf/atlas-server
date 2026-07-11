<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;

final readonly class EnforceReadinessWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private AtlasAcosWatchdogHealthService $health) {}

    public function id(): string
    {
        return 'eng-11.enforce_readiness';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return $this->health->toCheckResult(
            $this->health->engineeringEnforceReadinessReport(),
            'engineering_enforce_readiness_not_ready',
            'ENG-11 enforcement flips are not ready for promotion.',
        );
    }
}
