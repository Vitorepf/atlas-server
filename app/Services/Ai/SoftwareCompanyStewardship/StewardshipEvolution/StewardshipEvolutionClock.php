<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Shared byte-identical helper de-duplicated across this family (now).
 */
trait StewardshipEvolutionClock
{
    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
