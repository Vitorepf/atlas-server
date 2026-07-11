<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;

final readonly class LiftCycleClosureWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private AtlasAcosWatchdogHealthService $health) {}

    public function id(): string
    {
        return 'ope-08.lift_cycle_closure';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return $this->health->toCheckResult(
            $this->health->liftCycleClosureReport(),
            'lift_cycle_closure_stalled',
            'OPE-08 lift cycle blockers are not closing.',
        );
    }
}
