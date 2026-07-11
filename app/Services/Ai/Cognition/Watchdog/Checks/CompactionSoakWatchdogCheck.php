<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;

final readonly class CompactionSoakWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private AtlasAcosWatchdogHealthService $health) {}

    public function id(): string
    {
        return 'cpt-09.compaction_soak';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return $this->health->toCheckResult(
            $this->health->compactionSoakWatchReport(),
            'compaction_soak_not_ready',
            'CPT-09 compaction soak is not ready for enforce.',
        );
    }
}
