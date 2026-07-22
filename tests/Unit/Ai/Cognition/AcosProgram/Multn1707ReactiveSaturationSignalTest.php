<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\ReactiveSaturationSignal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multn1707ReactiveSaturationSignalTest extends TestCase
{
    #[Test]
    public function falling_yield_with_enough_windows_saturates_even_when_queue_is_non_empty(): void
    {
        $out = ReactiveSaturationSignal::classify([
            ['n' => 12, 'yield' => 0.62],
            ['n' => 12, 'yield' => 0.48],
            ['n' => 12, 'yield' => 0.31],
        ], ['queue_depth' => 50]);

        $this->assertTrue($out['reactive_saturated']);
        $this->assertSame('falling_yield_with_hysteresis', $out['basis']);
        $this->assertSame('prefer_originated', $out['pick_hint']);
    }

    #[Test]
    public function stable_yield_is_not_saturated(): void
    {
        $out = ReactiveSaturationSignal::classify([
            ['n' => 12, 'yield' => 0.40],
            ['n' => 12, 'yield' => 0.41],
            ['n' => 12, 'yield' => 0.40],
        ]);

        $this->assertFalse($out['reactive_saturated']);
        $this->assertSame('stable_or_recovering_yield', $out['basis']);
        $this->assertSame('byte_identical_pick', $out['pick_hint']);
    }

    #[Test]
    public function insufficient_tail_never_saturates(): void
    {
        $out = ReactiveSaturationSignal::classify([
            ['n' => 12, 'yield' => 0.62],
            ['n' => 2, 'yield' => 0.20],
            ['n' => 12, 'yield' => 0.10],
        ]);

        $this->assertFalse($out['reactive_saturated']);
        $this->assertSame('insufficient_n', $out['basis']);
    }
}
