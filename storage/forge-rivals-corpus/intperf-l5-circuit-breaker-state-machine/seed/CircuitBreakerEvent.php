<?php

declare(strict_types=1);

namespace App\Domain\Resilience;

final class CircuitBreakerEvent
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $trigger,
        public readonly float $atEpoch,
    ) {}
}
