<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Half-open [fromTsUtc, toTsUtc) UTC ISO-8601 window. A degenerate window (to <= from) is empty by
 * construction; the composer MUST short-circuit it without invoking any ledger source.
 */
final readonly class TimelineWindow
{
    public function __construct(
        public string $fromTsUtc,
        public string $toTsUtc,
    ) {}

    public function isDegenerate(): bool
    {
        return strcmp($this->toTsUtc, $this->fromTsUtc) <= 0;
    }

    public function contains(string $tsUtc): bool
    {
        return strcmp($tsUtc, $this->fromTsUtc) >= 0 && strcmp($tsUtc, $this->toTsUtc) < 0;
    }
}
