<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTopChurnHintDetector;
use Tests\TestCase;

final class AtlasBrainTopChurnHintDetectorTest extends TestCase
{
    public function test_picks_hint_with_highest_refused_count(): void
    {
        $r = (new AtlasBrainTopChurnHintDetector)->detect([
            'by_hint' => [
                ['hint' => 'a', 'refused' => 3, 'served' => 1, 'total' => 4],
                ['hint' => 'b', 'refused' => 18, 'served' => 2, 'total' => 20],
                ['hint' => 'c', 'refused' => 5, 'served' => 5, 'total' => 10],
            ],
        ]);
        self::assertSame('b', $r['top_hint']);
        self::assertSame(18, $r['refused_count']);
        self::assertSame(20, $r['total']);
    }

    public function test_returns_null_when_no_refused(): void
    {
        $r = (new AtlasBrainTopChurnHintDetector)->detect(['by_hint' => []]);
        self::assertNull($r['top_hint']);
        self::assertSame(0, $r['refused_count']);
    }

    public function test_detector_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTopChurnHintDetector.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
