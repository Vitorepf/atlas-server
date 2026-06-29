<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the process-topology probe is live at the operator surface and emits deterministic facts. A fixed
 * ps payload is parsed into the loop roles (supervisor/grind), non-loop processes are dropped, and the
 * parent→child tree is reconstructed.
 */
final class AtlasLoopProcessTopologyCommandTest extends TestCase
{
    public function test_emits_deterministic_topology_from_injected_ps(): void
    {
        $psPayload = implode("\n", [
            // pid ppid etime etimes pcpu pmem command
            '1000 1 00:10 600 1.5 2.0 php artisan atlas:loop:campaign --campaign-id=abc',
            '1001 1000 00:05 300 5.0 3.0 php artisan atlas:loop:run-scenario',
            '1002 1 00:01 60 0.1 0.1 /usr/sbin/cupsd', // non-loop ⇒ dropped
        ]);

        $this->app->instance(
            AtlasLoopProcessTopologyProbe::class,
            new AtlasLoopProcessTopologyProbe($psPayload, 'test-host', static fn (): string => '2026-06-29T00:00:00+00:00'),
        );

        $exit = Artisan::call('atlas:loop:process-topology', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.process_topology.v1', $decoded['schema_version']);
        $this->assertSame('test-host', $decoded['host']);
        $this->assertSame('2026-06-29T00:00:00+00:00', $decoded['captured_at_ts']);

        $this->assertCount(2, $decoded['processes']); // cupsd dropped
        $byPid = array_column($decoded['processes'], null, 'pid');
        $this->assertSame('supervisor', $byPid[1000]['role']);
        $this->assertSame('abc', $byPid[1000]['campaign_id_or_null']);
        $this->assertSame('grind', $byPid[1001]['role']);
        $this->assertSame(600, $byPid[1000]['etime_s']);

        // parent 1000 owns child 1001
        $this->assertSame([1001], $decoded['tree']['1000']);
    }
}
