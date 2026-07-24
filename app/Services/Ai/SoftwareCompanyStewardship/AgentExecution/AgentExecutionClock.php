<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (now).
 */
trait AgentExecutionClock
{
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
