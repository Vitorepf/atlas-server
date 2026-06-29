<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the blast-radius reader is live at the operator surface and emits deterministic facts. With the
 * brain flag OFF (provider-safe default), read() yields no consumers, so the command emits a zero-consumer
 * envelope for the target — never throwing, never inventing a dependency.
 */
final class AtlasLoopBlastRadiusReadCommandTest extends TestCase
{
    public function test_requires_a_target(): void
    {
        $exit = Artisan::call('atlas:loop:blast-radius-read', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_emits_deterministic_blast_radius_facts_when_brain_off(): void
    {
        config(['atlas.loop.blast_radius_brain_enabled' => false]);

        $exit = Artisan::call('atlas:loop:blast-radius-read', [
            '--target' => 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBlastRadiusReader.php',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.blast_radius_read.v1', $decoded['schema']);
        $this->assertSame('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopBlastRadiusReader.php', $decoded['target']);
        $this->assertSame(0, $decoded['consumer_count']);
        $this->assertSame([], $decoded['consumers']);
        $this->assertFalse($decoded['truncated']);
    }
}
