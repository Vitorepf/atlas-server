<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog;

interface AtlasWatchdogCheck
{
    public function id(): string;

    public function run(): AtlasWatchdogCheckResult;
}
