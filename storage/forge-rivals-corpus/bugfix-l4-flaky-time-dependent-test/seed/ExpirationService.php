<?php

declare(strict_types=1);

namespace App\Domain\Expiration;

final class ExpirationService
{
    /**
     * BUG: directly reads system clock, making tests flaky around
     * second boundaries. The arm must accept a ClockInterface and
     * stop calling microtime() inside the service.
     */
    public function isExpired(float $expiresAtEpoch): bool
    {
        return microtime(true) >= $expiresAtEpoch;
    }
}
