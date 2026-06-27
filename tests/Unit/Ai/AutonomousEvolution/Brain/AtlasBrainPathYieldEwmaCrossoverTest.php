<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathYieldEwmaCrossover;
use Tests\TestCase;

final class AtlasBrainPathYieldEwmaCrossoverTest extends TestCase
{
    public function test_short_above_long(): void
    {
        $r = (new AtlasBrainPathYieldEwmaCrossover)->compute(
            ['a' => ['ewma' => 0.8]],
            ['a' => ['ewma' => 0.5]],
        );
        self::assertSame('short_above_long', $r['by_path']['a']['label']);
        self::assertSame(0.3, $r['by_path']['a']['delta']);
    }

    public function test_short_below_long(): void
    {
        $r = (new AtlasBrainPathYieldEwmaCrossover)->compute(
            ['a' => ['ewma' => 0.3]],
            ['a' => ['ewma' => 0.7]],
        );
        self::assertSame('short_below_long', $r['by_path']['a']['label']);
    }

    public function test_near_zero_is_crossover(): void
    {
        $r = (new AtlasBrainPathYieldEwmaCrossover)->compute(
            ['a' => ['ewma' => 0.50]],
            ['a' => ['ewma' => 0.52]],
        );
        self::assertSame('crossover', $r['by_path']['a']['label']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathYieldEwmaCrossover.php',
                true
            )
        );
    }
}
