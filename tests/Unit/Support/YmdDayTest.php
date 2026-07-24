<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\YmdDay;
use PHPUnit\Framework\TestCase;

final class YmdDayTest extends TestCase
{
    public function test_valid_day_passthrough(): void
    {
        $this->assertSame('2026-07-24', YmdDay::normalize('2026-07-24'));
    }

    public function test_invalid_defaults_to_today_utc(): void
    {
        $this->assertSame(gmdate('Y-m-d'), YmdDay::normalize('nope'));
    }
}
