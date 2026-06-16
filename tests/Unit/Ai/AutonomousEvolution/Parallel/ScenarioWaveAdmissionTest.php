<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Parallel;

use App\Services\Ai\AutonomousEvolution\Parallel\ScenarioWaveDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * ACDE T1 — the within-wave workspace-cap accounting. The resource gate's /tmp glob lags the children
 * already started this wave, so the dispatcher must subtract the in-flight count itself. maxLive<=0 (the
 * config default) means unlimited => byte-identical to before (cap 0, no inline throttle).
 */
final class ScenarioWaveAdmissionTest extends TestCase
{
    public function test_unlimited_cap_never_throttles_within_a_wave(): void
    {
        // maxLive <= 0 => the default "unlimited"; effective cap stays 0 (the gate's own behaviour) for any
        // in-flight count => byte-identical to the pre-lever dispatcher.
        $this->assertSame(['run_inline' => false, 'effective_cap' => 0], ScenarioWaveDispatcher::withinWaveAdmission(0, 0));
        $this->assertSame(['run_inline' => false, 'effective_cap' => 0], ScenarioWaveDispatcher::withinWaveAdmission(0, 9));
    }

    public function test_cap_shrinks_by_the_in_flight_count(): void
    {
        $this->assertSame(['run_inline' => false, 'effective_cap' => 2], ScenarioWaveDispatcher::withinWaveAdmission(2, 0));
        $this->assertSame(['run_inline' => false, 'effective_cap' => 1], ScenarioWaveDispatcher::withinWaveAdmission(2, 1));
    }

    public function test_runs_inline_once_this_wave_alone_reaches_the_cap(): void
    {
        $this->assertSame(['run_inline' => true, 'effective_cap' => 0], ScenarioWaveDispatcher::withinWaveAdmission(2, 2));
        $this->assertSame(['run_inline' => true, 'effective_cap' => 0], ScenarioWaveDispatcher::withinWaveAdmission(2, 5));
    }
}
