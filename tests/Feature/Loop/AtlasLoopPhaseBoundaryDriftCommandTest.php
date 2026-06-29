<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the phase-boundary fact-drift detector is live at the operator surface: the command emits the
 * deterministic per-boundary drift facts over the cycle window.
 */
final class AtlasLoopPhaseBoundaryDriftCommandTest extends TestCase
{
    public function test_phase_boundary_drift_emits_boundaries(): void
    {
        $exit = Artisan::call('atlas:loop:phase-boundary-drift', ['--window' => 20, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.phase_boundary_drift.v1', $decoded['schema_version']);
        $this->assertArrayHasKey('boundaries', $decoded);
        $this->assertIsArray($decoded['boundaries']);
    }
}
