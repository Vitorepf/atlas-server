<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\UtcIsoTimestamp;
use PHPUnit\Framework\TestCase;

final class UtcIsoTimestampTest extends TestCase
{
    public function test_empty_defaults_to_now_iso(): void
    {
        $ts = UtcIsoTimestamp::normalize(null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $ts);
    }

    public function test_parses_known_utc(): void
    {
        $ts = UtcIsoTimestamp::normalize('2020-01-01T00:00:00+00:00');
        $this->assertStringStartsWith('2020-01-01T00:00:00', $ts);
    }
}
