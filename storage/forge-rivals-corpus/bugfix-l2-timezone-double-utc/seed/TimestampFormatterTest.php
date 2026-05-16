<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Formatter\TimestampFormatter;
use PHPUnit\Framework\TestCase;

final class TimestampFormatterTest extends TestCase
{
    public function test_format_does_not_apply_offset_twice_for_utc_input(): void
    {
        $this->assertSame(
            '2026-05-16T12:00:00Z',
            TimestampFormatter::format('2026-05-16T12:00:00Z'),
        );
    }

    public function test_format_converts_sao_paulo_input_to_utc_once(): void
    {
        // -03:00 → 15:00 UTC
        $this->assertSame(
            '2026-05-16T15:00:00Z',
            TimestampFormatter::format('2026-05-16T12:00:00-03:00'),
        );
    }

    public function test_format_converts_berlin_input_to_utc_once(): void
    {
        // +02:00 (CEST) → 10:00 UTC
        $this->assertSame(
            '2026-05-16T10:00:00Z',
            TimestampFormatter::format('2026-05-16T12:00:00+02:00'),
        );
    }

    public function test_format_is_pure(): void
    {
        $before = $GLOBALS;
        TimestampFormatter::format('2026-05-16T12:00:00Z');
        $this->assertSame($before, $GLOBALS, 'format() must not mutate globals');
    }
}
