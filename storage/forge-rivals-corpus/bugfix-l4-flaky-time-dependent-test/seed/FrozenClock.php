<?php

declare(strict_types=1);

namespace App\Support\Clock;

final class FrozenClock implements ClockInterface
{
    public function __construct(private float $epoch = 1_700_000_000.0) {}

    public function nowEpochSeconds(): float
    {
        return $this->epoch;
    }

    public function advance(int $seconds): void
    {
        $this->epoch += $seconds;
    }
}
