<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Programming\Forge\Execution\ForgeObraSupervisor;
use Mockery;
use Tests\TestCase;

final class AtlasForgeSupervisorCommandTest extends TestCase
{
    public function test_command_delegates_to_unattended_supervisor(): void
    {
        $this->app->instance(ForgeObraSupervisor::class, Mockery::mock(ForgeObraSupervisor::class, function ($mock): void {
            $mock->shouldReceive('run')->once()->with([], 900)->andReturn([
                'schema' => 'atlas.forge.supervisor.v1',
                'status' => 'ok',
                'reaped' => ['reaped_count' => 0],
                'heartbeats' => [],
                'active_obra_count' => 0,
                'stale_heartbeat_count' => 0,
            ]);
        }));

        $this->artisan('atlas:forge:supervise', ['--json' => true])
            ->expectsOutputToContain('atlas.forge.supervisor.v1')
            ->assertExitCode(0);
    }
}
