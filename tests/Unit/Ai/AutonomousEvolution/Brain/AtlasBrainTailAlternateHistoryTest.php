<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTailAlternateHistory;
use Tests\TestCase;

final class AtlasBrainTailAlternateHistoryTest extends TestCase
{
    public function test_rewrites_kth_row(): void
    {
        $tail = [
            ['action_hint' => 'compound', 'result_kind' => 'refused'],
            ['action_hint' => 'compound', 'result_kind' => 'refused'],
        ];
        $out = (new AtlasBrainTailAlternateHistory)->rewrite($tail, 1, 'accepted');
        self::assertSame('accepted', $out[1]['result_kind']);
        self::assertTrue($out[1]['counterfactual']);
    }

    public function test_input_unchanged(): void
    {
        $tail = [['action_hint' => 'compound', 'result_kind' => 'refused']];
        $copy = $tail;
        (new AtlasBrainTailAlternateHistory)->rewrite($tail, 0, 'accepted');
        self::assertSame($copy, $tail);
    }

    public function test_out_of_range_returns_unchanged(): void
    {
        $tail = [['result_kind' => 'refused']];
        self::assertSame($tail, (new AtlasBrainTailAlternateHistory)->rewrite($tail, 5, 'accepted'));
        self::assertSame($tail, (new AtlasBrainTailAlternateHistory)->rewrite($tail, -1, 'accepted'));
    }

    public function test_empty_new_kind_returns_unchanged(): void
    {
        $tail = [['result_kind' => 'refused']];
        self::assertSame($tail, (new AtlasBrainTailAlternateHistory)->rewrite($tail, 0, ''));
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTailAlternateHistory.php',
                true
            )
        );
    }
}
