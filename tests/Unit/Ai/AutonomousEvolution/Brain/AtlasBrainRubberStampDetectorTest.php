<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainRubberStampDetector;
use Tests\TestCase;

final class AtlasBrainRubberStampDetectorTest extends TestCase
{
    public function test_always_support_critic_flagged(): void
    {
        $votes = array_fill(0, 10, ['critic' => 'cheerleader', 'vote' => true]);
        $r = (new AtlasBrainRubberStampDetector)->detect($votes);
        self::assertCount(1, $r['suspect_critics']);
        self::assertSame('rubber_stamp_support', $r['suspect_critics'][0]['direction']);
    }

    public function test_always_refute_critic_flagged(): void
    {
        $votes = array_fill(0, 10, ['critic' => 'cynic', 'vote' => false]);
        $r = (new AtlasBrainRubberStampDetector)->detect($votes);
        self::assertSame('rubber_stamp_refute', $r['suspect_critics'][0]['direction']);
    }

    public function test_balanced_critic_not_flagged(): void
    {
        $votes = array_merge(
            array_fill(0, 5, ['critic' => 'fair', 'vote' => true]),
            array_fill(0, 5, ['critic' => 'fair', 'vote' => false]),
        );
        $r = (new AtlasBrainRubberStampDetector)->detect($votes);
        self::assertSame([], $r['suspect_critics']);
    }

    public function test_below_min_votes_skipped(): void
    {
        $votes = array_fill(0, 3, ['critic' => 'newbie', 'vote' => true]);
        $r = (new AtlasBrainRubberStampDetector)->detect($votes);
        self::assertSame([], $r['suspect_critics']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainRubberStampDetector.php',
                true
            )
        );
    }
}
