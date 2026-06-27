<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainHealthScore;
use Tests\TestCase;

/**
 * FROZEN proof of the brain health score — 0..100 composite over the perception suite.
 */
final class AtlasBrainHealthScoreTest extends TestCase
{
    public function test_perfect_inputs_yield_100(): void
    {
        $r = (new AtlasBrainHealthScore)->compute(true, 100, 0, 1.0, 'recovering');
        // 40 (gates) + 20 (ratio) + 20 (starv) + 10 (entropy) + 10 (trend) = 100
        self::assertSame(100, $r['score']);
        self::assertSame(40, $r['breakdown']['gates']);
        self::assertSame(20, $r['breakdown']['ratio']);
        self::assertSame(20, $r['breakdown']['starvation']);
        self::assertSame(10, $r['breakdown']['entropy']);
        self::assertSame(10, $r['breakdown']['trend']);
    }

    public function test_gate_regression_zeroes_gates_component(): void
    {
        $r = (new AtlasBrainHealthScore)->compute(false, 100, 0, 1.0, 'recovering');
        self::assertSame(0, $r['breakdown']['gates']);
        // 0 + 20 + 20 + 10 + 10 = 60
        self::assertSame(60, $r['score']);
    }

    public function test_worsening_trend_subtracts_full_trend_band(): void
    {
        $airtight = (new AtlasBrainHealthScore)->compute(true, 100, 0, 1.0, 'flat');
        $worse = (new AtlasBrainHealthScore)->compute(true, 100, 0, 1.0, 'worsening');
        self::assertSame(5, $airtight['breakdown']['trend']);
        self::assertSame(0, $worse['breakdown']['trend']);
        self::assertSame(95, $airtight['score']);
        self::assertSame(90, $worse['score']);
    }

    public function test_score_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHealthScore.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
