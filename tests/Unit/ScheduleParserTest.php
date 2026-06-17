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

    // ---- M-final delivery: weeks unit (frozen acceptance, VAL-MFINAL-001..008) ----

    public function test_parses_every_2w_as_interval_20160_minutes(): void
    {
        $parsed = app(ScheduleParser::class)->parse('every 2w', $this->now);

        $this->assertSame('interval', $parsed['kind']);
        $this->assertSame(20160, $parsed['interval_minutes']);
        $this->assertSame('2026-05-14T12:00:00.000000Z', $parsed['next_run_at']->toJSON());
    }

    public function test_parses_bare_1w_as_once_10080_minutes(): void
    {
        $parsed = app(ScheduleParser::class)->parse('1w', $this->now);

        $this->assertSame('once', $parsed['kind']);
        $this->assertSame(10080, $parsed['interval_minutes']);
        $this->assertArrayHasKey('run_at', $parsed);
        $this->assertSame('2026-05-07T12:00:00.000000Z', $parsed['run_at']->toJSON());
        $this->assertSame($parsed['run_at']->toJSON(), $parsed['next_run_at']->toJSON());
    }

    public function test_parses_every_spelled_weeks_as_interval(): void
    {
        $parser = app(ScheduleParser::class);

        $twoWeeks = $parser->parse('every 2 weeks', $this->now);
        $this->assertSame('interval', $twoWeeks['kind']);
        $this->assertSame(20160, $twoWeeks['interval_minutes']);

        $oneWeek = $parser->parse('every 1 week', $this->now);
        $this->assertSame('interval', $oneWeek['kind']);
        $this->assertSame(10080, $oneWeek['interval_minutes']);
    }

    public function test_bare_spelled_weeks_without_every_throws(): void
    {
        $parser = app(ScheduleParser::class);

        foreach (['2 weeks', '1 week'] as $schedule) {
            try {
                $parser->parse($schedule, $this->now);
                $this->fail("Schedule [{$schedule}] should be invalid.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_max_interval_minutes_preserved_strict_inequality(): void
    {
        $parser = app(ScheduleParser::class);

        // Exactly at MAX (527040 minutes, 366 days) is accepted.
        $parser->parse('366d', $this->now);
        $parser->parse('every 366d', $this->now);

        $this->expectException(InvalidArgumentException::class);
        $parser->parse('367d', $this->now);
    }

    public function test_above_max_weeks_value_throws_not_widened(): void
    {
        // Anti-gaming: MAX is not widened for the new weeks unit.
        // 53w = 53 * 7 * 24 * 60 = 534240 minutes > 527040 (MAX).
        $this->expectException(InvalidArgumentException::class);
        app(ScheduleParser::class)->parse('every 53w', $this->now);
    }

    public function test_abbreviation_vs_spelled_weeks_asymmetry(): void
    {
        $parser = app(ScheduleParser::class);

        $abbreviated = $parser->parse('2w', $this->now);
        $this->assertSame('once', $abbreviated['kind']);
        $this->assertSame(20160, $abbreviated['interval_minutes']);

        $this->expectException(InvalidArgumentException::class);
        $parser->parse('2 weeks', $this->now);
    }
}
