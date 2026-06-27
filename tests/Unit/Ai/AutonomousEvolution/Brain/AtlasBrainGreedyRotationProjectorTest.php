<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGreedyRotationProjector;
use Tests\TestCase;

final class AtlasBrainGreedyRotationProjectorTest extends TestCase
{
    public function test_zero_cycles_returns_unchanged(): void
    {
        $r = (new AtlasBrainGreedyRotationProjector)->project(['a' => 5, 'b' => 3], 0);
        self::assertSame(['a' => 5, 'b' => 3], $r['projected_counts']);
        self::assertSame([], $r['pick_order']);
    }

    public function test_picks_least_touched_first(): void
    {
        $r = (new AtlasBrainGreedyRotationProjector)->project(['a' => 5, 'b' => 2, 'c' => 0], 3);
        self::assertSame(['c', 'c', 'b'], $r['pick_order']);
        self::assertSame(['a' => 5, 'b' => 3, 'c' => 2], $r['projected_counts']);
    }

    public function test_tie_breaks_lexicographically(): void
    {
        $r = (new AtlasBrainGreedyRotationProjector)->project(['z' => 0, 'a' => 0], 1);
        self::assertSame(['a'], $r['pick_order']);
    }

    public function test_touched_paths_count(): void
    {
        $r = (new AtlasBrainGreedyRotationProjector)->project(['a' => 5, 'b' => 0, 'c' => 0], 2);
        self::assertSame(2, $r['touched_paths']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainGreedyRotationProjector.php',
                true
            )
        );
    }
}
