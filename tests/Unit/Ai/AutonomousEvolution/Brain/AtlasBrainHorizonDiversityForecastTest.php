<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainGreedyRotationProjector;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHorizonDiversityForecast;
use Tests\TestCase;

final class AtlasBrainHorizonDiversityForecastTest extends TestCase
{
    private function organ(): AtlasBrainHorizonDiversityForecast
    {
        return new AtlasBrainHorizonDiversityForecast(new AtlasBrainGreedyRotationProjector);
    }

    public function test_skewed_current_more_uniform_after_k(): void
    {
        $r = $this->organ()->forecast(['a' => 10, 'b' => 0, 'c' => 0], 20);
        // greedy fills b and c first; final HHI should be less concentrated
        self::assertLessThan(1.0, $r['projected_hhi']);
        self::assertSame(20, $r['k']);
    }

    public function test_empty_input(): void
    {
        $r = $this->organ()->forecast([], 5);
        self::assertSame(0.0, $r['projected_hhi']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHorizonDiversityForecast.php',
                true
            )
        );
    }
}
