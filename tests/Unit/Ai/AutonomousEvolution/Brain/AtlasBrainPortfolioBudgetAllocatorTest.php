<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPortfolioBudgetAllocator;
use Tests\TestCase;

final class AtlasBrainPortfolioBudgetAllocatorTest extends TestCase
{
    public function test_empty_input(): void
    {
        $r = (new AtlasBrainPortfolioBudgetAllocator)->allocate([]);
        self::assertSame([], $r['allocations']);
        self::assertSame(0, $r['total']);
    }

    public function test_zero_sum_returns_empty(): void
    {
        $r = (new AtlasBrainPortfolioBudgetAllocator)->allocate(['a' => 0, 'b' => 0]);
        self::assertSame(0, $r['total']);
    }

    public function test_allocations_sum_to_100(): void
    {
        $r = (new AtlasBrainPortfolioBudgetAllocator)->allocate(['a' => 30, 'b' => 70]);
        self::assertSame(100, $r['total']);
        self::assertSame(30, $r['allocations']['a']);
        self::assertSame(70, $r['allocations']['b']);
    }

    public function test_residual_added_to_top_path(): void
    {
        // 3 paths, score 1 each → floor(33.33) = 33 each → sum 99; residual +1 to top
        $r = (new AtlasBrainPortfolioBudgetAllocator)->allocate(['a' => 1, 'b' => 1, 'c' => 1]);
        self::assertSame(100, $r['total']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPortfolioBudgetAllocator.php',
                true
            )
        );
    }
}
