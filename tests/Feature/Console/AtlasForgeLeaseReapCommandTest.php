<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Programming\Forge\ForgeScopeReservationService;
use Mockery;
use Tests\TestCase;

final class AtlasForgeLeaseReapCommandTest extends TestCase
{
    public function test_command_delegates_to_canonical_reaper_and_emits_json(): void
    {
        $this->app->instance(ForgeScopeReservationService::class, Mockery::mock(ForgeScopeReservationService::class, function ($mock): void {
            $mock->shouldReceive('reapExpired')->once()->andReturn([
                'reaped_count' => 2,
                'reservations' => [],
            ]);
        }));

        $this->artisan('atlas:forge:reap-leases', ['--json' => true])
            ->expectsOutputToContain('"schema":"atlas.forge.lease_reap.v1"')
            ->assertExitCode(0);
    }
}
