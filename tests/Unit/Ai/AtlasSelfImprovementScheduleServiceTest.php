<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRuntime;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AtlasSelfImprovementScheduleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_default_schedule_runs_nightly_architecture_repair_and_kernel_pipeline_reviews(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05 01:30:00', 'America/Sao_Paulo'));
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review', 'agent_behavior_review']);
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.emit', false);

        $plan = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();
        $commands = $plan['commands'];

        $this->assertSame(['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review', 'agent_behavior_review'], $plan['configured_flows']);
        $this->assertSame([], $plan['invalid_flows']);
        $this->assertFalse($plan['defaulted']);
        $this->assertSame('healthy', $plan['health']['status']);
        $this->assertSame([], $plan['health']['issues']);
        $this->assertTrue($plan['schedulable']);
        $this->assertSame('registered', $plan['scheduler_registration']['status']);
        $this->assertSame(5, $plan['scheduler_registration']['registered_command_count']);
        $this->assertNull($plan['scheduler_registration']['skipped_reason']);
        $this->assertSame('America/Sao_Paulo', $plan['timezone']);
        $this->assertSame('2026-05-05T05:00:00.000000Z', $plan['next_run_at']);
        $this->assertSame('sha256', $plan['plan_hash_algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['plan_hash']);
        $this->assertSame(['daily' => 4, 'weekly' => 1], $plan['cadence_counts']);
        $this->assertSame(['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review', 'agent_behavior_review'], array_column($commands, 'flow'));
        $this->assertSame('atlas:ai:self-improve --flow=nightly_review --hours=24 --limit=5 --json', $commands[0]['command']);
        $this->assertSame('atlas:ai:self-improve --flow=weekly_architecture_audit --hours=24 --limit=5 --json', $commands[1]['command']);
        $this->assertSame('atlas:ai:self-improve --flow=repair_loop_review --hours=24 --limit=5 --json', $commands[2]['command']);
        $this->assertSame('atlas:ai:self-improve --flow=kernel_pipeline_review --hours=24 --limit=5 --json', $commands[3]['command']);
        $this->assertSame('atlas:ai:self-improve --flow=agent_behavior_review --hours=24 --limit=5 --json', $commands[4]['command']);
        $this->assertSame('02:00', $commands[0]['time']);
        $this->assertSame('02:00', $commands[1]['time']);
        $this->assertSame('02:00', $commands[2]['time']);
        $this->assertSame('02:00', $commands[3]['time']);
        $this->assertSame('02:00', $commands[4]['time']);
        $this->assertSame('daily', $commands[0]['cadence']);
        $this->assertSame('2026-05-05T05:00:00.000000Z', $commands[0]['next_run_at']);
        $this->assertSame('weekly', $commands[1]['cadence']);
        $this->assertSame(1, $commands[1]['week_day']);
        $this->assertSame('2026-05-11T05:00:00.000000Z', $commands[1]['next_run_at']);
        $this->assertSame('daily', $commands[2]['cadence']);
        $this->assertSame('2026-05-05T05:00:00.000000Z', $commands[2]['next_run_at']);
        $this->assertSame('daily', $commands[3]['cadence']);
        $this->assertSame('2026-05-05T05:00:00.000000Z', $commands[3]['next_run_at']);
        $this->assertSame('daily', $commands[4]['cadence']);
        $this->assertSame('2026-05-05T05:00:00.000000Z', $commands[4]['next_run_at']);
    }

    public function test_scheduled_commands_carry_timezone_and_plan_hash_for_scheduler_registration(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review', 'kernel_pipeline_review']);
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.emit', false);

        $service = app(AtlasSelfImprovementScheduleService::class);
        $plan = $service->schedulePlan();
        $commands = $service->scheduledCommands();

        $this->assertSame('America/Sao_Paulo', $commands[0]['timezone']);
        $this->assertSame('America/Sao_Paulo', $commands[1]['timezone']);
        $this->assertSame($plan['plan_hash'], $commands[0]['plan_hash']);
        $this->assertSame($plan['plan_hash'], $commands[1]['plan_hash']);
        $this->assertSame('daily', $commands[0]['cadence']);
        $this->assertSame('daily', $commands[1]['cadence']);
        $this->assertArrayHasKey('next_run_at', $commands[0]);
        $this->assertArrayHasKey('next_run_at', $commands[1]);
    }

    public function test_scheduled_commands_mark_weekly_architecture_audit_as_weekly(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['weekly_architecture_audit']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $commands = app(AtlasSelfImprovementScheduleService::class)->scheduledCommands();

        $this->assertCount(1, $commands);
        $this->assertSame('weekly_architecture_audit', $commands[0]['flow']);
        $this->assertSame('weekly', $commands[0]['cadence']);
        $this->assertSame(1, $commands[0]['week_day']);
        $this->assertSame('02:00', $commands[0]['time']);
        $this->assertSame('America/Sao_Paulo', $commands[0]['timezone']);
        $this->assertArrayHasKey('next_run_at', $commands[0]);
    }

    public function test_schedule_normalizes_dedupes_and_filters_configured_flows(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', [
            'self_improvement.repair_loop_review',
            'repair_loop_review',
            'provider_performance_review',
            'unknown_flow',
            '',
        ]);
        config()->set('atlas_ai.self_improvement.hours', 999);
        config()->set('atlas_ai.self_improvement.limit', 0);
        config()->set('atlas_ai.self_improvement.emit', true);

        $plan = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();
        $commands = $plan['commands'];

        $this->assertSame([
            'self_improvement.repair_loop_review',
            'repair_loop_review',
            'provider_performance_review',
            'unknown_flow',
            '',
        ], $plan['configured_flows']);
        $this->assertSame(['unknown_flow'], $plan['invalid_flows']);
        $this->assertFalse($plan['defaulted']);
        $this->assertSame('warning', $plan['health']['status']);
        $this->assertSame(['invalid_self_improvement_flows_configured'], $plan['health']['issues']);
        $this->assertSame(['repair_loop_review', 'provider_performance_review'], array_column($commands, 'flow'));
        $this->assertSame(
            'atlas:ai:self-improve --flow=repair_loop_review --hours='.AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS.' --limit=1 --json --emit',
            $commands[0]['command'],
        );
        $this->assertSame(
            'atlas:ai:self-improve --flow=provider_performance_review --hours='.AtlasSelfImprovementRuntime::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS.' --limit=1 --json --emit',
            $commands[1]['command'],
        );
    }

    public function test_schedule_falls_back_when_config_contains_no_valid_flows(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown', '']);

        $plan = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();
        $commands = $plan['commands'];

        $this->assertSame(['unknown', ''], $plan['configured_flows']);
        $this->assertSame(['unknown'], $plan['invalid_flows']);
        $this->assertTrue($plan['defaulted']);
        $this->assertSame('warning', $plan['health']['status']);
        $this->assertSame([
            'invalid_self_improvement_flows_configured',
            'self_improvement_schedule_defaulted',
        ], $plan['health']['issues']);
        $this->assertSame(['nightly_review', 'weekly_architecture_audit', 'repair_loop_review', 'kernel_pipeline_review', 'agent_behavior_review'], array_column($commands, 'flow'));
    }

    public function test_schedule_health_reports_disabled_state(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', false);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review']);

        $plan = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();

        $this->assertSame('disabled', $plan['health']['status']);
        $this->assertSame(['self_improvement_schedule_disabled'], $plan['health']['issues']);
        $this->assertNotSame([], $plan['health']['actions']);
    }

    public function test_schedule_health_returns_compact_operational_summary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05 03:00:00', 'America/Sao_Paulo'));
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['unknown', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.emit', false);

        $health = app(AtlasSelfImprovementScheduleService::class)->scheduleHealth();

        $this->assertSame(1, $health['flow_count']);
        $this->assertSame(1, $health['invalid_flow_count']);
        $this->assertTrue($health['schedulable']);
        $this->assertSame('registered', $health['scheduler_registration']['status']);
        $this->assertSame(1, $health['scheduler_registration']['registered_command_count']);
        $this->assertSame('America/Sao_Paulo', $health['timezone']);
        $this->assertSame('2026-05-06T05:00:00.000000Z', $health['next_run_at']);
        $this->assertSame('sha256', $health['plan_hash_algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $health['plan_hash']);
        $this->assertSame(['daily' => 1], $health['cadence_counts']);
        $this->assertFalse($health['defaulted']);
        $this->assertSame('warning', $health['health']['status']);
        $this->assertSame(['invalid_self_improvement_flows_configured'], $health['health']['issues']);
    }

    public function test_schedule_reports_invalid_time_without_next_run(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '25:99');

        $plan = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();

        $this->assertNull($plan['next_run_at']);
        $this->assertFalse($plan['schedulable']);
        $this->assertSame('skipped', $plan['scheduler_registration']['status']);
        $this->assertSame(0, $plan['scheduler_registration']['registered_command_count']);
        $this->assertSame('invalid_self_improvement_schedule_time', $plan['scheduler_registration']['skipped_reason']);
        $this->assertSame('warning', $plan['health']['status']);
        $this->assertSame(['invalid_self_improvement_schedule_time'], $plan['health']['issues']);
        $this->assertNotSame([], $plan['health']['actions']);
        $this->assertSame([], app(AtlasSelfImprovementScheduleService::class)->scheduledCommands());
    }

    public function test_schedule_reports_invalid_timezone_without_next_run(): void
    {
        config()->set('app.timezone', 'Mars/Olympus_Mons');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $plan = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();

        $this->assertSame('Mars/Olympus_Mons', $plan['timezone']);
        $this->assertNull($plan['next_run_at']);
        $this->assertFalse($plan['schedulable']);
        $this->assertSame('skipped', $plan['scheduler_registration']['status']);
        $this->assertSame(0, $plan['scheduler_registration']['registered_command_count']);
        $this->assertSame('invalid_self_improvement_schedule_timezone', $plan['scheduler_registration']['skipped_reason']);
        $this->assertSame('warning', $plan['health']['status']);
        $this->assertSame(['invalid_self_improvement_schedule_timezone'], $plan['health']['issues']);
        $this->assertNotSame([], $plan['health']['actions']);
        $this->assertSame([], app(AtlasSelfImprovementScheduleService::class)->scheduledCommands());
    }

    public function test_disabled_schedule_is_not_schedulable_and_returns_no_scheduled_commands(): void
    {
        config()->set('atlas_ai.self_improvement.enabled', false);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');

        $service = app(AtlasSelfImprovementScheduleService::class);
        $plan = $service->schedulePlan();

        $this->assertFalse($plan['schedulable']);
        $this->assertSame('skipped', $plan['scheduler_registration']['status']);
        $this->assertSame('self_improvement_schedule_disabled', $plan['scheduler_registration']['skipped_reason']);
        $this->assertSame([], $service->scheduledCommands());
    }

    public function test_schedule_plan_hash_is_stable_across_next_run_changes_and_changes_with_config(): void
    {
        config()->set('app.timezone', 'America/Sao_Paulo');
        config()->set('atlas_ai.self_improvement.enabled', true);
        config()->set('atlas_ai.self_improvement.flows', ['nightly_review', 'repair_loop_review']);
        config()->set('atlas_ai.self_improvement.time', '02:00');
        config()->set('atlas_ai.self_improvement.hours', 24);
        config()->set('atlas_ai.self_improvement.limit', 5);
        config()->set('atlas_ai.self_improvement.emit', false);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05 01:30:00', 'America/Sao_Paulo'));
        $first = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05 03:30:00', 'America/Sao_Paulo'));
        $second = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();

        $this->assertNotSame($first['next_run_at'], $second['next_run_at']);
        $this->assertNotSame($first['commands'][0]['next_run_at'], $second['commands'][0]['next_run_at']);
        $this->assertSame($first['plan_hash'], $second['plan_hash']);

        config()->set('atlas_ai.self_improvement.emit', true);
        $changed = app(AtlasSelfImprovementScheduleService::class)->schedulePlan();

        $this->assertNotSame($first['plan_hash'], $changed['plan_hash']);
    }
}
