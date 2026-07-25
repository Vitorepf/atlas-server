<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\Support\SelfImprovementScheduleMath;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SelfImprovementScheduleMathTest extends TestCase
{
    #[Test]
    public function normalize_flow_strips_prefix_and_rejects_unknown(): void
    {
        $this->assertSame('nightly_review', SelfImprovementScheduleMath::normalizeFlow('nightly_review'));
        $this->assertSame(
            'repair_loop_review',
            SelfImprovementScheduleMath::normalizeFlow('self_improvement.repair_loop_review'),
        );
        $this->assertNull(SelfImprovementScheduleMath::normalizeFlow(''));
        $this->assertNull(SelfImprovementScheduleMath::normalizeFlow('   '));
        $this->assertNull(SelfImprovementScheduleMath::normalizeFlow('unknown_flow'));
        $this->assertSame(
            'custom_flow',
            SelfImprovementScheduleMath::normalizeFlow(
                'self_improvement.custom_flow',
                ['self_improvement.custom_flow'],
            ),
        );
    }

    #[Test]
    public function cadence_helpers_map_weekly_architecture_audit(): void
    {
        $this->assertSame('weekly', SelfImprovementScheduleMath::cadenceForFlow('weekly_architecture_audit'));
        $this->assertSame(1, SelfImprovementScheduleMath::weekDayForFlow('weekly_architecture_audit'));
        $this->assertSame('daily', SelfImprovementScheduleMath::cadenceForFlow('nightly_review'));
        $this->assertNull(SelfImprovementScheduleMath::weekDayForFlow('nightly_review'));
        $this->assertSame(
            ['daily' => 2, 'weekly' => 1],
            SelfImprovementScheduleMath::cadenceCounts([
                ['cadence' => 'daily'],
                ['cadence' => 'weekly'],
                ['cadence' => 'daily'],
            ]),
        );
    }

    #[Test]
    public function is_valid_time_and_timezone(): void
    {
        $this->assertTrue(SelfImprovementScheduleMath::isValidTime('00:00'));
        $this->assertTrue(SelfImprovementScheduleMath::isValidTime('23:59'));
        $this->assertTrue(SelfImprovementScheduleMath::isValidTime('02:00'));
        $this->assertFalse(SelfImprovementScheduleMath::isValidTime('25:99'));
        $this->assertFalse(SelfImprovementScheduleMath::isValidTime('2:00'));
        $this->assertFalse(SelfImprovementScheduleMath::isValidTime(''));

        $this->assertTrue(SelfImprovementScheduleMath::isValidTimezone('UTC'));
        $this->assertTrue(SelfImprovementScheduleMath::isValidTimezone('America/Sao_Paulo'));
        $this->assertFalse(SelfImprovementScheduleMath::isValidTimezone('Mars/Olympus_Mons'));
        $this->assertFalse(SelfImprovementScheduleMath::isValidTimezone(''));
    }

    #[Test]
    public function is_schedulable_requires_enabled_count_and_valid_clock_fields(): void
    {
        $base = [
            'enabled' => true,
            'time' => '02:00',
            'timezone' => 'UTC',
            'count' => 1,
        ];
        $this->assertTrue(SelfImprovementScheduleMath::isSchedulable($base));
        $this->assertFalse(SelfImprovementScheduleMath::isSchedulable([...$base, 'enabled' => false]));
        $this->assertFalse(SelfImprovementScheduleMath::isSchedulable([...$base, 'count' => 0]));
        $this->assertFalse(SelfImprovementScheduleMath::isSchedulable([...$base, 'time' => '25:00']));
        $this->assertFalse(SelfImprovementScheduleMath::isSchedulable([...$base, 'timezone' => 'Not/A/Zone']));
    }

    #[Test]
    public function next_run_at_for_command_uses_injected_now(): void
    {
        $now = CarbonImmutable::parse('2026-05-05 01:30:00', 'America/Sao_Paulo');

        $daily = SelfImprovementScheduleMath::nextRunAtForCommand(
            '02:00',
            'America/Sao_Paulo',
            'daily',
            null,
            $now,
        );
        $this->assertSame('2026-05-05T05:00:00.000000Z', $daily);

        $after = SelfImprovementScheduleMath::nextRunAtForCommand(
            '02:00',
            'America/Sao_Paulo',
            'daily',
            null,
            CarbonImmutable::parse('2026-05-05 03:00:00', 'America/Sao_Paulo'),
        );
        $this->assertSame('2026-05-06T05:00:00.000000Z', $after);

        // 2026-05-05 is Tuesday (dayOfWeek=2); weekly weekDay=1 (Monday) => 2026-05-11
        $weekly = SelfImprovementScheduleMath::nextRunAtForCommand(
            '02:00',
            'America/Sao_Paulo',
            'weekly',
            1,
            $now,
        );
        $this->assertSame('2026-05-11T05:00:00.000000Z', $weekly);

        $this->assertNull(SelfImprovementScheduleMath::nextRunAtForCommand(
            '25:99',
            'UTC',
            'daily',
            null,
            $now,
        ));
        $this->assertNull(SelfImprovementScheduleMath::nextRunAtForCommand(
            '02:00',
            'Mars/Olympus_Mons',
            'daily',
            null,
            $now,
        ));
    }

    #[Test]
    public function hashable_commands_strip_next_run_at_and_plan_hash_is_stable(): void
    {
        $commands = [
            [
                'flow' => 'nightly_review',
                'command' => 'atlas:ai:self-improve --flow=nightly_review --json',
                'time' => '02:00',
                'cadence' => 'daily',
                'week_day' => null,
                'next_run_at' => '2026-05-05T05:00:00.000000Z',
            ],
        ];
        $hashable = SelfImprovementScheduleMath::hashableCommands($commands);
        $this->assertArrayNotHasKey('next_run_at', $hashable[0]);
        $this->assertSame('nightly_review', $hashable[0]['flow']);

        $plan = [
            'schema_version' => 1,
            'enabled' => true,
            'schedulable' => true,
            'scheduler_registration' => [
                'status' => 'registered',
                'registered_command_count' => 1,
                'skipped_reason' => null,
            ],
            'time' => '02:00',
            'timezone' => 'America/Sao_Paulo',
            'configured_flows' => ['nightly_review'],
            'invalid_flows' => [],
            'defaulted' => false,
            'flows' => ['nightly_review'],
            'commands' => $commands,
            'count' => 1,
            'cadence_counts' => ['daily' => 1],
            'emit' => false,
            'health' => [
                'status' => 'healthy',
                'issues' => [],
                'actions' => [],
            ],
        ];

        $first = SelfImprovementScheduleMath::planHash($plan);
        $plan['commands'][0]['next_run_at'] = '2026-05-06T05:00:00.000000Z';
        $second = SelfImprovementScheduleMath::planHash($plan);
        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);

        $plan['emit'] = true;
        $this->assertNotSame($first, SelfImprovementScheduleMath::planHash($plan));
    }
}
