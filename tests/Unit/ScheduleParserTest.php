<?php

namespace Tests\Unit;

use App\Services\Ai\Scheduling\ScheduleParser;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduleParserTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-04-30T12:00:00Z');
    }

    public function test_parses_once_delta_minutes_hours_and_days(): void
    {
        $parser = app(ScheduleParser::class);

        $minutes = $parser->parse('30m', $this->now);
        $hours = $parser->parse('2h', $this->now);
        $days = $parser->parse('1d', $this->now);

        $this->assertSame('once', $minutes['kind']);
        $this->assertSame(30, $minutes['interval_minutes']);
        $this->assertSame('2026-04-30T12:30:00.000000Z', $minutes['next_run_at']->toJSON());
        $this->assertSame('2026-04-30T14:00:00.000000Z', $hours['next_run_at']->toJSON());
        $this->assertSame('2026-05-01T12:00:00.000000Z', $days['next_run_at']->toJSON());
    }

    public function test_parses_recurring_intervals(): void
    {
        $parsed = app(ScheduleParser::class)->parse('every 2h', $this->now);

        $this->assertSame('interval', $parsed['kind']);
        $this->assertSame(120, $parsed['interval_minutes']);
        $this->assertSame('2026-04-30T14:00:00.000000Z', $parsed['next_run_at']->toJSON());
    }

    public function test_parses_five_field_cron_expression(): void
    {
        $parsed = app(ScheduleParser::class)->parse('0 9 * * 1-5', CarbonImmutable::parse('2026-04-30T08:00:00Z'));

        $this->assertSame('cron', $parsed['kind']);
        $this->assertSame('0 9 * * 1-5', $parsed['expression']);
        $this->assertSame('2026-04-30T09:00:00.000000Z', $parsed['next_run_at']->toJSON());
    }

    public function test_parses_iso_timestamp(): void
    {
        $parsed = app(ScheduleParser::class)->parse('2026-05-01T09:15:00Z', $this->now);

        $this->assertSame('once', $parsed['kind']);
        $this->assertSame('2026-05-01T09:15:00.000000Z', $parsed['next_run_at']->toJSON());
    }

    public function test_next_run_at_uses_previous_run_for_intervals_and_cron(): void
    {
        $parser = app(ScheduleParser::class);
        $interval = $parser->parse('every 30m', $this->now);
        $cron = $parser->parse('*/15 * * * *', $this->now);

        $this->assertSame('2026-04-30T12:45:00.000000Z', $parser->nextRunAt($interval, CarbonImmutable::parse('2026-04-30T12:15:00Z'))->toJSON());
        $this->assertSame('2026-04-30T12:30:00.000000Z', $parser->nextRunAt($cron, CarbonImmutable::parse('2026-04-30T12:15:00Z'))->toJSON());
    }

    public function test_rejects_invalid_or_ambiguous_formats(): void
    {
        $parser = app(ScheduleParser::class);

        foreach (['', '0m', 'every soon', '30 minutes', '* * * * * *'] as $schedule) {
            try {
                $parser->parse($schedule, $this->now);
                $this->fail("Schedule [{$schedule}] should be invalid.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
