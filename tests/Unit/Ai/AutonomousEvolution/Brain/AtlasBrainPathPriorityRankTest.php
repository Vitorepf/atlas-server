<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathPriorityRank;
use Tests\TestCase;

final class AtlasBrainPathPriorityRankTest extends TestCase
{
    public function test_oscillation_pin_outranks_improving(): void
    {
        $r = (new AtlasBrainPathPriorityRank)->rank(
            [
                'compounding' => ['agreement' => 'signals_agree_improving'],
                'frontier-harvest' => ['agreement' => 'oscillation_pin'],
            ],
            [],
        );
        self::assertSame('frontier-harvest', $r['ranked'][0]['path']);
        self::assertSame(90, $r['ranked'][0]['score']);
    }

    public function test_starvation_boosts_score(): void
    {
        $r = (new AtlasBrainPathPriorityRank)->rank(
            [
                'compounding' => ['agreement' => 'signals_agree_improving'],  // base 10
                'pattern-design' => ['agreement' => 'signals_agree_improving'],  // base 10 + starv
            ],
            ['pattern-design' => 6],
        );
        self::assertSame('pattern-design', $r['ranked'][0]['path']);
        self::assertSame(40, $r['ranked'][0]['score']);  // 10 + min(40, 30) = 40
    }

    public function test_declining_signals_high_score(): void
    {
        $r = (new AtlasBrainPathPriorityRank)->rank(
            ['adversarial-critique' => ['agreement' => 'signals_agree_declining']],
            [],
        );
        self::assertSame(80, $r['ranked'][0]['score']);
    }

    public function test_unknown_path_defaults_to_insufficient_signal(): void
    {
        $r = (new AtlasBrainPathPriorityRank)->rank([], ['mystery-path' => 2]);
        self::assertSame(30 + 10, $r['ranked'][0]['score']);
        self::assertSame('insufficient_signal', $r['ranked'][0]['agreement']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathPriorityRank.php',
                true
            )
        );
    }
}
