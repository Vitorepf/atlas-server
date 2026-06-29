<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the unattended supervisor cycle is live at the operator surface and emits a deterministic, strictly
 * advisory decision: with empty callbacks and no apply, the tick is dry_run with NO applied actions, while
 * still surfacing the classification and planned recovery actions. A missing --facts is a usage error.
 */
final class AtlasLoopSupervisorTickCommandTest extends TestCase
{
    public function test_requires_facts(): void
    {
        $exit = Artisan::call('atlas:loop:supervisor-tick', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_tick_is_advisory_dry_run_with_no_applied_actions(): void
    {
        $exit = Artisan::call('atlas:loop:supervisor-tick', [
            '--facts' => json_encode(['heartbeat' => ['age_seconds' => 99999]]),
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.unattended_supervisor_cycle.v1', $decoded['schema']);
        $this->assertSame('ok', $decoded['status']);
        $this->assertTrue($decoded['dry_run']);
        $this->assertSame([], $decoded['applied_actions']); // advisory: nothing executed
        $this->assertIsArray($decoded['planned_actions']);
        $this->assertIsString($decoded['classification']);
        $this->assertNotSame('', $decoded['supervisor_cycle_hash']);
    }
}
