<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Console\Commands\AtlasLoopHungSupervisorGuardCommand;
use Tests\TestCase;

/**
 * The hung-supervisor verdict must be honest: kill ONLY a supervisor whose completed-work has been frozen
 * past the grace window WHILE real work waits. A slow-but-live supervisor (still completing tasks) and a
 * genuinely-idle one (nothing to do) must never be killed — otherwise the guard storm-restarts a healthy
 * loop. These pin that decision table on the pure logic (no clock, no processes).
 */
final class AtlasLoopHungSupervisorGuardCommandTest extends TestCase
{
    private function guard(): AtlasLoopHungSupervisorGuardCommand
    {
        return new AtlasLoopHungSupervisorGuardCommand();
    }

    public function test_first_sighting_starts_the_clock(): void
    {
        $d = $this->guard()->decideVerdict(5, null, true, 360, 1000);
        $this->assertSame('progressing', $d['verdict']);
        $this->assertSame(['progress' => 5, 'ts' => 1000], $d['marker']);
    }

    public function test_real_progress_resets_the_clock(): void
    {
        $d = $this->guard()->decideVerdict(8, ['progress' => 5, 'ts' => 500], true, 360, 1000);
        $this->assertSame('progressing', $d['verdict']);
        $this->assertSame(['progress' => 8, 'ts' => 1000], $d['marker'], 'completing a task must reset the freeze clock');
    }

    public function test_frozen_without_work_is_never_hung(): void
    {
        // nothing waiting => a quiet supervisor is legitimately idle, not wedged.
        $d = $this->guard()->decideVerdict(5, ['progress' => 5, 'ts' => 100], false, 360, 1000);
        $this->assertSame('idle_no_work', $d['verdict']);
        $this->assertNull($d['marker']);
    }

    public function test_frozen_with_work_within_grace_is_watching(): void
    {
        // frozen 200s, stall threshold 360s => still inside grace, do not kill yet.
        $d = $this->guard()->decideVerdict(5, ['progress' => 5, 'ts' => 800], true, 360, 1000);
        $this->assertSame('watching', $d['verdict']);
        $this->assertNull($d['marker'], 'the freeze clock must keep running (ts not reset) while watching');
    }

    public function test_frozen_with_work_beyond_grace_is_hung(): void
    {
        // frozen 600s >= 360s stall, work waiting => wedged.
        $d = $this->guard()->decideVerdict(5, ['progress' => 5, 'ts' => 400], true, 360, 1000);
        $this->assertSame('hung', $d['verdict']);
        $this->assertSame(['progress' => 5, 'ts' => 1000], $d['marker'], 'clock resets after a kill so the respawn gets room');
    }
}
