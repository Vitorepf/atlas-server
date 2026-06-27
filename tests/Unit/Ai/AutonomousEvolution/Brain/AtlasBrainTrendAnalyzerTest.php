<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTrendAnalyzer;
use Tests\TestCase;

/**
 * FROZEN proof of the trend analyzer — split-window starvation_pct delta.
 */
final class AtlasBrainTrendAnalyzerTest extends TestCase
{
    public function test_worsening_trend_is_detected(): void
    {
        // Older half (4): 0% blocked. Newer half (4): 100% blocked. Delta = +100.
        $rows = array_merge(
            array_fill(0, 4, ['result_kind' => 'note']),
            array_fill(0, 4, ['result_kind' => 'blocked']),
        );
        $t = (new AtlasBrainTrendAnalyzer)->starvation($rows, 4);

        self::assertSame(0, $t['older_starvation_pct']);
        self::assertSame(100, $t['newer_starvation_pct']);
        self::assertSame(100, $t['delta_pct']);
        self::assertSame('worsening', $t['direction']);
    }

    public function test_recovering_trend_is_detected(): void
    {
        $rows = array_merge(
            array_fill(0, 4, ['result_kind' => 'blocked']),
            array_fill(0, 4, ['result_kind' => 'note']),
        );
        $t = (new AtlasBrainTrendAnalyzer)->starvation($rows, 4);
        self::assertSame('recovering', $t['direction']);
    }

    public function test_insufficient_data_returns_flat_direction(): void
    {
        $rows = array_fill(0, 5, ['result_kind' => 'note']);
        $t = (new AtlasBrainTrendAnalyzer)->starvation($rows, 4);
        self::assertSame('insufficient_data', $t['direction']);
    }

    public function test_trend_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTrendAnalyzer.php',
            true
        );
        self::assertSame('forbidden', $verdict);
    }
}
