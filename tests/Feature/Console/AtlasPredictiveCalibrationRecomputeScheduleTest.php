<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * L6-11 follow-up · The daily predictive-failure calibration recompute is wired into the
 * schedule and is governed exactly like the bridge that feeds it: flag-gated, DEFAULT OFF,
 * and only firing when the loop predictive outcome bridge is itself ON (no bridge data =>
 * nothing to recompute).
 */
final class AtlasPredictiveCalibrationRecomputeScheduleTest extends TestCase
{
    private const RECOMPUTE_COMMAND = 'atlas:predict metrics --domain=programming --window=60 --json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.loop.predictive_outcome_bridge.enabled' => true,
            'atlas.loop.predictive_outcome_bridge.domain' => 'programming',
            'atlas.loop.predictive_outcome_bridge.metrics_recompute.enabled' => true,
            'atlas.loop.predictive_outcome_bridge.metrics_recompute.schedule_enabled' => true,
            'atlas.loop.predictive_outcome_bridge.metrics_recompute.schedule_time' => '07:45',
            'atlas.loop.predictive_outcome_bridge.metrics_recompute.window_days' => 60,
        ]);
    }

    public function test_schedule_contains_daily_predictive_failure_calibration_recompute(): void
    {
        $exit = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString(self::RECOMPUTE_COMMAND, $output);
    }

    public function test_recompute_runs_daily_at_the_configured_time(): void
    {
        $event = $this->recomputeEvent();

        $this->assertNotNull($event, 'predictive-failure calibration recompute is not registered in the schedule');
        // 07:45 daily => cron "45 7 * * *".
        $this->assertSame('45 7 * * *', $event->expression);
    }

    public function test_recompute_fires_only_when_bridge_and_recompute_are_enabled(): void
    {
        $event = $this->recomputeEvent();
        $this->assertNotNull($event);

        // Both flags ON (set in setUp) => the event passes its filters and would run.
        $this->assertTrue($event->filtersPass($this->app));

        // DEFAULT OFF, consistent with the bridge: turning the bridge off skips the recompute.
        config(['atlas.loop.predictive_outcome_bridge.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['atlas.loop.predictive_outcome_bridge.enabled' => true]);
        config(['atlas.loop.predictive_outcome_bridge.metrics_recompute.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        // The schedule kill-switch independently parks the recompute without disabling the bridge.
        config(['atlas.loop.predictive_outcome_bridge.metrics_recompute.enabled' => true]);
        config(['atlas.loop.predictive_outcome_bridge.metrics_recompute.schedule_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
    }

    private function recomputeEvent(): ?Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, self::RECOMPUTE_COMMAND)) {
                return $event;
            }
        }

        return null;
    }
}
