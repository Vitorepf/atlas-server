<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDriftRestartDebounce;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 5 · Slice 13 — drift restart is debounced (≤1/window) AND warranted (only when real
 * self-merges landed), so the loop self-restarts on its own improvements without a restart storm and without
 * churning for nothing.
 */
final class AtlasLoopDriftRestartDebounceTest extends TestCase
{
    private AtlasLoopDriftRestartDebounce $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->d = new AtlasLoopDriftRestartDebounce();
    }

    public function test_within_the_window_is_debounced(): void
    {
        $r = $this->d->decide(secondsSinceLastRestart: 300, selfMergesSinceLastRestart: 5, windowSeconds: 600);
        $this->assertFalse($r['allowed']);
        $this->assertStringContainsString('debounced', $r['reason']);
    }

    public function test_window_elapsed_with_drift_is_allowed(): void
    {
        $r = $this->d->decide(secondsSinceLastRestart: 700, selfMergesSinceLastRestart: 2, windowSeconds: 600);
        $this->assertTrue($r['allowed']);
        $this->assertSame('window_elapsed_and_drift_landed', $r['reason']);
    }

    public function test_window_elapsed_but_no_drift_is_blocked(): void
    {
        // A restart with nothing merged is pointless churn — blocked.
        $r = $this->d->decide(secondsSinceLastRestart: 700, selfMergesSinceLastRestart: 0, windowSeconds: 600, minSelfMerges: 1);
        $this->assertFalse($r['allowed']);
        $this->assertStringContainsString('no_drift', $r['reason']);
    }

    public function test_exact_window_boundary_is_allowed(): void
    {
        $r = $this->d->decide(secondsSinceLastRestart: 600, selfMergesSinceLastRestart: 1, windowSeconds: 600);
        $this->assertTrue($r['allowed'], 'the window boundary is inclusive');
    }

    public function test_inputs_are_coerced_and_window_is_the_decider(): void
    {
        // negative seconds coerced to 0 ⇒ inside any positive window ⇒ debounced.
        $r = $this->d->decide(secondsSinceLastRestart: -50, selfMergesSinceLastRestart: 9, windowSeconds: 600);
        $this->assertFalse($r['allowed']);
        $this->assertSame(0, $r['seconds_since_last']);
    }
}
