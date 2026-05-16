<?php

declare(strict_types=1);

namespace App\Domain\Resilience;

final class BackoffPolicy
{
    public function __construct(
        public readonly int $baseSeconds = 1,
        public readonly int $capSeconds = 60,
    ) {}

    public function nextDelay(int $attempt): int
    {
        $delay = $this->baseSeconds * (2 ** max(0, $attempt));

        return min($this->capSeconds, $delay);
    }
}
