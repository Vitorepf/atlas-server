<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Tests\TestCase;

final class AtlasTaskServableHeartbeatIntegrationTest extends TestCase
{
    public function test_command_is_registered_in_artisan_kernel(): void
    {
        $commands = array_keys($this->app->make('Illuminate\Contracts\Console\Kernel')->all());
        $this->assertContains('atlas:task:servable-heartbeat', $commands);
    }

    public function test_routes_console_php_contains_heartbeat_schedule_entry_with_required_idioms(): void
    {
        $routes = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('atlas:task:servable-heartbeat', $routes);
        $this->assertStringContainsString('everyFiveMinutes()', $routes);
        $this->assertStringContainsString('withoutOverlapping(5)', $routes);
        $this->assertStringContainsString('AtlasLoopMasterSwitch::enabled()', $routes);
    }

    public function test_schedule_lookup_finds_the_heartbeat_command_under_the_correct_cadence(): void
    {
        // Force-load the schedule definitions
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
        $events = $schedule->events();
        $matched = array_filter(
            $events,
            static fn ($event): bool => str_contains((string) $event->command ?: (string) $event->description, 'atlas:task:servable-heartbeat'),
        );
        $this->assertNotEmpty($matched, 'servable-heartbeat must appear in the loaded schedule');
        $event = array_values($matched)[0];
        $this->assertSame('*/5 * * * *', $event->expression, 'cadence must be everyFiveMinutes');
    }
}
