<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;

final readonly class MemoryQualityWatchdogCheck implements AtlasWatchdogCheck
{
    public function __construct(private AtlasAcosWatchdogHealthService $health) {}

    public function id(): string
    {
        return 'mem-09.memory_quality';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $report = $this->health->memoryQualityCheck();

        return $this->health->toCheckResult($report, 'memory_quality_check_failed', 'MEM-09 memory quality watchdog is not green.');
    }
}
