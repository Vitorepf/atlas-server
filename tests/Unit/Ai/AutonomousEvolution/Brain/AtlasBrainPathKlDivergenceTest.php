<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathKlDivergence;
use Tests\TestCase;

final class AtlasBrainPathKlDivergenceTest extends TestCase
{
    public function test_uniform_vs_uniform_zero(): void
    {
        $r = (new AtlasBrainPathKlDivergence)->compute(['a' => 1, 'b' => 1, 'c' => 1]);
        self::assertSame(0.0, $r['kl']);
    }

    public function test_concentrated_vs_uniform_high(): void
    {
        $r = (new AtlasBrainPathKlDivergence)->compute(['a' => 100, 'b' => 0, 'c' => 0]);
        self::assertGreaterThan(1.0, $r['kl']);
    }

    public function test_missing_target_key_infinite(): void
    {
        $r = (new AtlasBrainPathKlDivergence)->compute(['a' => 1, 'b' => 1], ['a' => 1.0]);
        self::assertSame(INF, $r['kl']);
    }

    public function test_empty(): void
    {
        $r = (new AtlasBrainPathKlDivergence)->compute([]);
        self::assertSame(0.0, $r['kl']);
        self::assertSame(0, $r['total']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathKlDivergence.php',
                true
            )
        );
    }
}
