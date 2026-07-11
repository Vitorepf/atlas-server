<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;

final readonly class LearningCadenceWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private AtlasAcosWatchdogHealthService $health) {}

    public function id(): string
    {
        return 'fee-13.learning_cadence';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return $this->health->toCheckResult(
            $this->health->learningCadenceReport(),
            'learning_cadence_stalled',
            'FEE-13 learning cadence is stalled or under-evidenced.',
        );
    }
}
