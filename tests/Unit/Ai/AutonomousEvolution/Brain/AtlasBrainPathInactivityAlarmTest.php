<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathInactivityAlarm;
use Tests\TestCase;

final class AtlasBrainPathInactivityAlarmTest extends TestCase
{
    public function test_no_alarms_when_all_under_threshold(): void
    {
        $r = (new AtlasBrainPathInactivityAlarm)->check(['a' => 3, 'b' => 5], 10);
        self::assertSame([], $r['alarms']);
    }

    public function test_alarms_at_or_above_threshold(): void
    {
        $r = (new AtlasBrainPathInactivityAlarm)->check(['a' => 10, 'b' => 25, 'c' => 5], 10);
        $paths = array_column($r['alarms'], 'path');
        self::assertSame(['b', 'a'], $paths);  // sorted desc by cycles_since_last
    }

    public function test_threshold_clamped_to_one(): void
    {
        $r = (new AtlasBrainPathInactivityAlarm)->check(['a' => 1], 0);
        self::assertSame(1, $r['threshold']);
        self::assertCount(1, $r['alarms']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathInactivityAlarm.php',
                true
            )
        );
    }
}
