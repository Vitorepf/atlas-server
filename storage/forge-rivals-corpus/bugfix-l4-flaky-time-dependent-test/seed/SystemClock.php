<?php

declare(strict_types=1);

namespace App\Support\Clock;

final class SystemClock implements ClockInterface
{
    public function nowEpochSeconds(): float
    {
        return microtime(true);
    }
}
