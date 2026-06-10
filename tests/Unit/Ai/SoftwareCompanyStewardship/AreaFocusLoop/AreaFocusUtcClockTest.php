<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use DateTimeImmutable;
use Tests\TestCase;

final class AreaFocusUtcClockTest extends TestCase
{
    public function test_atom_now_returns_utc_atom_timestamp(): void
    {
        $timestamp = AreaFocusUtcClock::atomNow();
        $parsed = new DateTimeImmutable($timestamp);

        $this->assertSame('+00:00', $parsed->format('P'));
    }

    public function test_atom_now_can_shift_seconds(): void
    {
        $base = new DateTimeImmutable(AreaFocusUtcClock::atomNow());
        $shifted = new DateTimeImmutable(AreaFocusUtcClock::atomNow(30));

        $this->assertGreaterThanOrEqual(29, $shifted->getTimestamp() - $base->getTimestamp());
        $this->assertLessThanOrEqual(31, $shifted->getTimestamp() - $base->getTimestamp());
    }
}
