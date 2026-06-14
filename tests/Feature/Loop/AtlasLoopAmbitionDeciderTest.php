<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopAmbitionDecider;
use Tests\TestCase;

/**
 * AMBITION DECIDER — frozen proof of the operator's explicit strategy: given a heavy refactor, a safe
 * implementation, and a BIG risky implementation, the loop chooses the BIG risky one — because under a
 * convex/bounded-downside payoff you maximise the MAGNITUDE of the leap, not the probability. The
 * risk-tolerance dial proves that risk-neutral EV would UNDER-bet the big leap (it picks the moderate
 * one), which is exactly why ambition mode exists. Bigger leaps demand proportionally stronger
 * verification (the honest coupling that keeps risk-seeking-on-ambition safe).
 */
final class AtlasLoopAmbitionDeciderTest extends TestCase
{
    private function decider(): AtlasLoopAmbitionDecider
    {
        return new AtlasLoopAmbitionDecider();
    }

    /** The operator's exact three options. */
    private function threeOptions(): array
    {
        return [
            ['candidateId' => 'heavy_refactor', 'leap_magnitude' => 5.0, 'p_land' => 0.7, 'cost' => 0.0],
            ['candidateId' => 'safe_implementation', 'leap_magnitude' => 2.0, 'p_land' => 0.95, 'cost' => 0.0],
            ['candidateId' => 'big_risky_leap', 'leap_magnitude' => 10.0, 'p_land' => 0.3, 'cost' => 0.0],
        ];
    }

    public function test_default_chooses_the_big_risky_leap_max_evolution_per_commit(): void
    {
        $result = $this->decider()->rank($this->threeOptions());
        $this->assertSame('big_risky_leap', $result['winner']['candidateId'], 'ambition: the biggest leap wins even at lower P');
        $this->assertSame('risk_seeking_on_ambition_risk_averse_on_the_gate', $result['strategy']);
    }

    public function test_risk_neutral_EV_would_under_bet_the_big_leap_which_is_why_ambition_mode_exists(): void
    {
        // riskTolerance = 1 is classic EV (magnitude × P). It picks the MODERATE option, not the big leap.
        $result = $this->decider()->rank($this->threeOptions(), 1.0);
        $this->assertSame('heavy_refactor', $result['winner']['candidateId'], 'risk-neutral EV under-bets the giant leap');
        // ...whereas the default (ambition) flips the winner to the big leap.
        $this->assertSame('big_risky_leap', $this->decider()->rank($this->threeOptions())['winner']['candidateId']);
    }

    public function test_a_giant_magnitude_at_low_probability_still_beats_a_small_safe_one(): void
    {
        $result = $this->decider()->rank([
            ['candidateId' => 'giant', 'leap_magnitude' => 20.0, 'p_land' => 0.2],
            ['candidateId' => 'tiny_safe', 'leap_magnitude' => 1.0, 'p_land' => 1.0],
        ]);
        $this->assertSame('giant', $result['winner']['candidateId'], 'bounded downside + convex upside => take the big bet');
    }

    public function test_bigger_leaps_require_proportionally_stronger_verification(): void
    {
        $result = $this->decider()->rank([
            ['candidateId' => 'enormous', 'leap_magnitude' => 12.0, 'p_land' => 0.4],
            ['candidateId' => 'small', 'leap_magnitude' => 1.5, 'p_land' => 0.9],
        ]);
        $enormous = collect($result['ranked'])->firstWhere('candidateId', 'enormous');
        $small = collect($result['ranked'])->firstWhere('candidateId', 'small');
        $this->assertSame('maximal', $enormous['required_verification']['tier'], 'an enormous leap must clear every gate');
        $this->assertContains('mutation_adequacy', $enormous['required_verification']['gates']);
        $this->assertSame('trust_ladder_autonomous', $enormous['required_verification']['autonomous_merge_requires']);
        $this->assertSame('standard', $small['required_verification']['tier']);
    }

    public function test_zero_landing_probability_scores_zero_no_unfalsifiable_pick(): void
    {
        $result = $this->decider()->rank([
            ['candidateId' => 'impossible', 'leap_magnitude' => 100.0, 'p_land' => 0.0],
            ['candidateId' => 'real', 'leap_magnitude' => 3.0, 'p_land' => 0.5],
        ]);
        $this->assertSame('real', $result['winner']['candidateId'], 'a magnitude that can never land is worth nothing');
    }
}
