<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainPathSignalAggregator;
use Tests\TestCase;

final class AtlasBrainPathSignalAggregatorTest extends TestCase
{
    public function test_signals_agree_improving(): void
    {
        $r = (new AtlasBrainPathSignalAggregator)->aggregate(
            ['compounding' => ['trend' => 'improving']],
            ['compounding' => ['label' => 'accelerating']],
            ['detected' => false, 'pair' => null],
        );
        self::assertSame('signals_agree_improving', $r['by_path']['compounding']['agreement']);
    }

    public function test_signals_agree_declining(): void
    {
        $r = (new AtlasBrainPathSignalAggregator)->aggregate(
            ['frontier-harvest' => ['trend' => 'declining']],
            ['frontier-harvest' => ['label' => 'decelerating']],
            ['detected' => false, 'pair' => null],
        );
        self::assertSame('signals_agree_declining', $r['by_path']['frontier-harvest']['agreement']);
    }

    public function test_signals_disagree(): void
    {
        $r = (new AtlasBrainPathSignalAggregator)->aggregate(
            ['pattern-design' => ['trend' => 'improving']],
            ['pattern-design' => ['label' => 'decelerating']],
            ['detected' => false, 'pair' => null],
        );
        self::assertSame('signals_disagree', $r['by_path']['pattern-design']['agreement']);
    }

    public function test_oscillation_pin_overrides(): void
    {
        $r = (new AtlasBrainPathSignalAggregator)->aggregate(
            ['frontier-harvest' => ['trend' => 'improving']],
            ['frontier-harvest' => ['label' => 'accelerating']],
            ['detected' => true, 'pair' => ['frontier-harvest', 'compounding']],
        );
        self::assertSame('oscillation_pin', $r['by_path']['frontier-harvest']['agreement']);
        self::assertTrue($r['by_path']['frontier-harvest']['oscillating']);
    }

    public function test_insufficient_signal_when_one_axis_missing(): void
    {
        $r = (new AtlasBrainPathSignalAggregator)->aggregate(
            ['compounding' => ['trend' => 'improving']],
            [],
            ['detected' => false, 'pair' => null],
        );
        self::assertSame('insufficient_signal', $r['by_path']['compounding']['agreement']);
    }

    public function test_organ_is_petreo(): void
    {
        self::assertSame(
            'forbidden',
            app(AtlasLoopHarnessGuard::class)->admit(
                'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathSignalAggregator.php',
                true
            )
        );
    }
}
