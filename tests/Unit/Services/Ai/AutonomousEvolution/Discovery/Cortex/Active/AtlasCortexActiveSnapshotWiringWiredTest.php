<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexActiveSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\CortexPassiveImmutabilityViolation;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasCortexActiveSnapshotWiringWiredTest extends TestCase
{
    public function test_snapshot_mutate_passive_throws_cortex_passive_immutability_violation(): void
    {
        $snapshot = new AtlasCortexActiveSnapshot(['k' => 'v'], []);
        $this->expectException(CortexPassiveImmutabilityViolation::class);
        $snapshot->mutatePassive(['tampered' => true]);
    }

    public function test_probe_command_runs_immutability_assertion_on_live_snapshot_path(): void
    {
        config()->set('atlas.cortex.active.enabled', true);
        // Forcing --json so the master-switch read-only render path is used regardless of env.
        $exit = Artisan::call('atlas:loop:cortex:probe', ['--target' => 'Demo', '--edit' => 'remove', '--depth' => 2, '--flows' => ['Demo'], '--json' => true]);
        $this->assertSame(0, $exit);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(AtlasCortexActiveSnapshot::SCHEMA, $decoded['schema']);
        $this->assertArrayHasKey('passive', $decoded);
        $this->assertArrayHasKey('active', $decoded);
    }

    public function test_probe_command_disabled_when_feature_flag_off(): void
    {
        config()->set('atlas.cortex.active.enabled', false);
        $exit = Artisan::call('atlas:loop:cortex:probe', ['--target' => 'X', '--depth' => 2]);
        $this->assertSame(0, $exit);
        $this->assertSame('cortex.active.disabled', trim(Artisan::output()));
    }
}
