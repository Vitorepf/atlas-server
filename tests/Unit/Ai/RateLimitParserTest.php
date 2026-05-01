<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Concerns\RateLimitParser;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class RateLimitParserTest extends TestCase
{
    public function test_parses_codex_style_absolute_date(): void
    {
        $stderr = "You've hit your usage limit. Visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again at May 5th, 2026 10:24 AM.";
        $result = RateLimitParser::extract('', $stderr);

        $this->assertSame('May 5th, 2026 10:24 AM', $result['reset_hint']);
        $this->assertInstanceOf(CarbonImmutable::class, $result['provider_reset_at']);
        $this->assertSame('2026-05-05 10:24:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
    }

    public function test_parses_iso_8601_reset(): void
    {
        $stderr = 'Rate limited. Reset at 2026-05-05T10:24:00Z.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertNotNull($result['provider_reset_at']);
        $this->assertSame('2026-05-05 10:24:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
    }

    public function test_parses_relative_minutes_using_now(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $stderr = 'Rate limited. Try again in 47 minutes.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertSame('2026-05-01 09:47:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }

    public function test_parses_relative_compact_hours_minutes(): void
    {
        CarbonImmutable::setTestNow('2026-05-01 09:00:00');
        $stderr = 'Try again in 2h 15m.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertSame('2026-05-01 11:15:00', $result['provider_reset_at']->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }

    public function test_returns_null_when_no_reset_present(): void
    {
        $stderr = 'Rate limited. Please slow down.';
        $result = RateLimitParser::extract('', $stderr);

        $this->assertNull($result['provider_reset_at']);
        $this->assertNull($result['reset_hint']);
    }

    public function test_uses_stdout_when_stderr_empty(): void
    {
        $stdout = 'try again at 2026-05-05 10:24';
        $result = RateLimitParser::extract($stdout, '');

        $this->assertNotNull($result['provider_reset_at']);
    }
}
