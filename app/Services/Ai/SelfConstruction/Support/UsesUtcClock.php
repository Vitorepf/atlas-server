<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Provides the canonical `now()` helper used across the SelfConstruction
 * services: read the injected callable clock when present; otherwise emit
 * a UTC ISO-8601 timestamp via gmdate(DATE_ATOM). De-duplicates the
 * body copy-pasted across the SelfConstruction ledgers.
 *
 * Consumers MUST declare `private $clock` (callable|null).
 */
trait UsesUtcClock
{
    private function now(): string
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (string) $clock();
        }

        return gmdate(DATE_ATOM);
    }
}