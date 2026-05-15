<?php

declare(strict_types=1);

namespace App\Support\Clock;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
