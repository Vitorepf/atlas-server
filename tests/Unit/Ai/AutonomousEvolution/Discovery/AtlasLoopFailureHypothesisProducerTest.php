<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFailureHypothesisProducer as F;
use PHPUnit\Framework\TestCase;

/**
 * ROADMAP #6 — failure-driven alternative-direction frames (pure, deterministic).
 */
final class AtlasLoopFailureHypothesisProducerTest extends TestCase
{
    public function test_emits_n_frames_one_per_orthogonal_move(): void
    {
        $frames = F::alternativeFrames('reduce refund rounding drift', 'acceptance_not_diff_earned', 4);
        $this->assertCount(4, $frames);
        $this->assertStringContainsString('Assumption-inversion', $frames[0]);
        $this->assertStringContainsString('Backward-from-success', $frames[1]);
        $this->assertStringContainsString('Analogical-transfer', $frames[2]);
        $this->assertStringContainsString('Failure-reverse-engineering', $frames[3]);
        // moves 0-2 are framed around the objective; move 3 (failure-reverse) is framed around the reason.
        foreach ([0, 1, 2] as $i) {
            $this->assertStringContainsString('reduce refund rounding drift', $frames[$i]);
        }
        $this->assertStringContainsString('acceptance_not_diff_earned', $frames[3]);
    }

    public function test_frames_cycle_when_n_exceeds_moves(): void
    {
        $frames = F::alternativeFrames('x', 'y', 6);
        $this->assertCount(6, $frames);
        $this->assertSame($frames[0], $frames[4]); // cycles back to move 0
    }

    public function test_empty_objective_yields_nothing(): void
    {
        $this->assertSame([], F::alternativeFrames('   ', 'reason'));
    }

    public function test_empty_reason_uses_a_default_in_the_failure_reverse_frame(): void
    {
        $frames = F::alternativeFrames('x', '', 4);
        $this->assertStringContainsString('did not earn the metric', $frames[3]);
    }

    public function test_is_deterministic_and_bounded(): void
    {
        $this->assertSame(F::alternativeFrames('x', 'y', 3), F::alternativeFrames('x', 'y', 3));
        $this->assertCount(1, F::alternativeFrames('x', 'y', 0));   // clamped to >=1
        $this->assertCount(8, F::alternativeFrames('x', 'y', 99));  // clamped to <=8
    }
}
