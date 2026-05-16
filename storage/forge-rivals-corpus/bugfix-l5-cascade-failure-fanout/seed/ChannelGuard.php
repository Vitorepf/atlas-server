<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Per-channel guard for fan-out delivery. Holds the budget, the
 * cumulative failure count, and decides whether a channel is currently
 * usable. Time is injected so tests stay deterministic.
 */
final class ChannelGuard
{
    private int $failures = 0;

    private bool $opened = false;

    public function __construct(
        public readonly string $channel,
        public readonly int $budgetMillis,
        public readonly int $tripAfter = 3,
    ) {}

    public function isOpen(): bool
    {
        return $this->opened;
    }

    public function recordSuccess(): void
    {
        $this->failures = 0;
    }

    public function recordFailure(): void
    {
        $this->failures++;
        if ($this->failures >= $this->tripAfter) {
            $this->opened = true;
        }
    }
}
