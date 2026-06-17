<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNextWorkDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSelectAdjuster as SA;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ARBOR-GRAFT SEL1 — the deterministic SELECT term: pure logic + the single-shared-clamp floor invariant.
 */
final class AtlasLoopSelectAdjusterTest extends TestCase
{
    public function test_band_width_matches_the_decider(): void
    {
        // The within-band clamp is only sound if the adjuster shares the decider's band geometry.
        $deciderBandWidth = (new ReflectionClass(AtlasLoopNextWorkDecider::class))->getConstant('BAND_WIDTH');
        $this->assertSame($deciderBandWidth, SA::BAND_WIDTH);
    }

    public function test_tokens_normalize_path_and_objective(): void
    {
        $t = SA::tokens('app/Services/Ai/Payment/RefundCalculator.php', ['objective' => 'reduce refund rounding error']);
        $this->assertContains('payment', $t);
        $this->assertContains('refundcalculator', $t);
        $this->assertContains('rounding', $t);
        // stop-segments dropped
        $this->assertNotContains('app', $t);
        $this->assertNotContains('services', $t);
        $this->assertNotContains('php', $t);
    }

    public function test_jaccard_and_max_similarity(): void
    {
        $this->assertSame(1.0, SA::jaccard(['a', 'b'], ['a', 'b']));
        $this->assertSame(0.0, SA::jaccard(['a'], ['b']));
        $this->assertSame(0.0, SA::maxSimilarity(['a'], []));                 // nothing to clash with -> novel
        // jaccard(['a','b'],['b','c']) = |{b}| / |{a,b,c}| = 1/3; jaccard(['a','b'],['x']) = 0; max = 1/3.
        $this->assertEqualsWithDelta(1 / 3, SA::maxSimilarity(['a', 'b'], [['b', 'c'], ['x']]), 1e-9);
    }

    public function test_penalty_is_high_for_duplicates_and_zero_for_novel(): void
    {
        // near-duplicate (sim 1) -> up to maxFraction*offset
        $this->assertSame(80, SA::penalty(1.0, 100, 0.8));
        // novel (sim 0) -> no penalty
        $this->assertSame(0, SA::penalty(0.0, 100, 0.8));
        // bounded to offset
        $this->assertLessThanOrEqual(100, SA::penalty(1.0, 100, 2.0));
    }

    public function test_within_band_clamp_never_crosses_the_band(): void
    {
        $bw = SA::BAND_WIDTH;
        $band = 5 * $bw; // an arbitrary shape band
        // Exhaustive-ish sweep: ANY offset in [0,bw-1] and ANY penalty must stay within [band, band+bw-1].
        foreach ([0, 1, 250, 500, 999, $bw - 1] as $offset) {
            foreach ([0, 1, 50, 999, $bw, 100000] as $penalty) {
                $p = SA::applyWithinBand($band, $offset, $penalty, $bw);
                $this->assertGreaterThanOrEqual($band, $p, "offset=$offset penalty=$penalty underflowed band");
                $this->assertLessThan($band + $bw, $p, "offset=$offset penalty=$penalty crossed into next band");
            }
        }
    }

    public function test_three_within_band_terms_compose_without_crossing(): void
    {
        // Simulate: leverage offset, then work-class prior lowering, then SEL1 lowering — all on one offset.
        $bw = SA::BAND_WIDTH;
        $band = 3 * $bw;
        $offset = $bw - 1;                              // max leverage offset
        $afterWorkClass = max(0, $offset - 400);        // work-class prior only lowers
        $p = SA::applyWithinBand($band, $afterWorkClass, /* select penalty */ 700, $bw);
        $this->assertGreaterThanOrEqual($band, $p);
        $this->assertLessThan($band + $bw, $p);
        // SHAPE dominates: a candidate in a higher band always outranks one in a lower band regardless of terms.
        $lowerBandMax = ($band - $bw) + ($bw - 1);
        $this->assertGreaterThan($lowerBandMax, $p);
    }

    public function test_only_lowers_never_raises(): void
    {
        $bw = SA::BAND_WIDTH;
        $band = 2 * $bw;
        $offset = 500;
        $this->assertSame($band + 500, SA::applyWithinBand($band, $offset, 0, $bw));   // no penalty -> unchanged
        $this->assertSame($band + 300, SA::applyWithinBand($band, $offset, 200, $bw)); // penalty only lowers
    }
}
