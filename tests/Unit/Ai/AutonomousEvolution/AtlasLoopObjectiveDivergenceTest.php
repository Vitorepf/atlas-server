<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObjectiveDivergence;
use PHPUnit\Framework\TestCase;

/**
 * ACDE U3 — mean pairwise Jaccard DISTANCE over the K sampled objectives is the ambiguity signal. Pure +
 * deterministic; it measures DISAGREEMENT, never which reading is correct.
 */
final class AtlasLoopObjectiveDivergenceTest extends TestCase
{
    private function svc(): AtlasLoopObjectiveDivergence
    {
        return new AtlasLoopObjectiveDivergence;
    }

    public function test_identical_readings_have_zero_distance(): void
    {
        $d = $this->svc()->meanPairwiseJaccardDistance([
            'Make subject_val return 2',
            'make SUBJECT_VAL return 2',
            'Make  subject_val   return 2',
        ]);
        $this->assertSame(0.0, $d, 'case/space-insensitive identical objectives fully agree');
    }

    public function test_disjoint_readings_have_distance_one(): void
    {
        $d = $this->svc()->meanPairwiseJaccardDistance(['alpha beta gamma', 'delta epsilon zeta']);
        $this->assertSame(1.0, $d, 'no shared words => maximal divergence');
    }

    public function test_partial_overlap_is_between_zero_and_one(): void
    {
        // {a,b,c} vs {a,b,d}: intersection 2, union 4 => jaccard 0.5 => distance 0.5.
        $this->assertSame(0.5, $this->svc()->meanPairwiseJaccardDistance(['a b c', 'a b d']));
    }

    public function test_fewer_than_two_readings_never_diverge(): void
    {
        $this->assertSame(0.0, $this->svc()->meanPairwiseJaccardDistance(['only one reading']));
        $this->assertSame(0.0, $this->svc()->meanPairwiseJaccardDistance([]));
        $this->assertFalse($this->svc()->diverges(['only one'], 0.5));
    }

    public function test_diverges_respects_the_threshold_and_disabled_zero(): void
    {
        $svc = $this->svc();
        $disjoint = ['alpha beta', 'gamma delta'];           // distance 1.0
        $this->assertTrue($svc->diverges($disjoint, 0.85));
        $this->assertFalse($svc->diverges($disjoint, 0.0), 'threshold 0 disables the gate');

        $similar = ['make val return two', 'make val return two now']; // low distance
        $this->assertFalse($svc->diverges($similar, 0.85), 'agreeing readings do not trip the threshold');
    }
}
