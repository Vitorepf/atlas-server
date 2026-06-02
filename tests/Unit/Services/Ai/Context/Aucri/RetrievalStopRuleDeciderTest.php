<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context\Aucri;

use App\Services\Ai\Context\Aucri\RetrievalStopRuleDecider;
use Tests\TestCase;

final class RetrievalStopRuleDeciderTest extends TestCase
{
    private RetrievalStopRuleDecider $decider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decider = new RetrievalStopRuleDecider();
    }

    public function testHardCapStopsWithMaxRoundsReached(): void
    {
        // roundsDone (5) >= maxRounds (5) -> rule (1) wins regardless of sufficiency.
        $result = $this->decider->decide(0.99, 0.80, 1000, 10, 5, 5);

        $this->assertSame('atlas.aucri.retrieval_stop_rule.v1', $result['schema_version']);
        $this->assertSame('stop', $result['decision']);
        $this->assertContains('max_rounds_reached', $result['reasons']);
        $this->assertNotSame([], $result['reasons']);
    }

    public function testSufficientWithCollapsedMarginalYieldStops(): void
    {
        // sufficient (0.90 >= 0.80), newTokens=4, cost=100 -> 4/100 = 0.04 < 0.05 -> rule (3).
        $result = $this->decider->decide(0.90, 0.80, 4, 100, 2, 6);

        $this->assertSame('stop', $result['decision']);
        $this->assertContains('sufficient_and_marginal_yield_collapsed', $result['reasons']);
        $this->assertNotSame([], $result['reasons']);
    }

    public function testSufficientWithHealthyMarginalYieldContinues(): void
    {
        // sufficient (0.90 >= 0.80), newTokens=40, cost=100 -> 40/100 = 0.40, not < 0.05 -> rule (6).
        $result = $this->decider->decide(0.90, 0.80, 40, 100, 2, 6);

        $this->assertSame('continue', $result['decision']);
        $this->assertContains('sufficient_but_marginal_yield_remains', $result['reasons']);
        $this->assertNotSame([], $result['reasons']);
    }

    public function testBelowThresholdContinues(): void
    {
        // not sufficient (0.40 < 0.80), roundsDone>0 so first-round rule skipped -> rule (5).
        $result = $this->decider->decide(0.40, 0.80, 25, 100, 3, 8);

        $this->assertSame('continue', $result['decision']);
        $this->assertContains('sufficiency_below_threshold', $result['reasons']);
        $this->assertNotSame([], $result['reasons']);
    }

    public function testFirstRoundIsMandatoryAndContinues(): void
    {
        // roundsDone=0 with maxRounds>0; sufficiency kept below threshold so rules (2)/(3) skip -> rule (4).
        $result = $this->decider->decide(0.10, 0.80, 0, 0, 0, 4);

        $this->assertSame('continue', $result['decision']);
        $this->assertContains('first_round_mandatory', $result['reasons']);
        $this->assertNotSame([], $result['reasons']);
    }

    public function testMarginalYieldRatioIsComputedFromInputsNotStatic(): void
    {
        // Same sufficiency/round shape, only the ratio changes across the 0.05 cutoff.
        // newTokens=49, cost=1000 -> 0.049 < 0.05 -> stop (collapsed).
        $belowCutoff = $this->decider->decide(0.85, 0.70, 49, 1000, 1, 9);
        // newTokens=51, cost=1000 -> 0.051, not < 0.05 -> continue (yield remains).
        $aboveCutoff = $this->decider->decide(0.85, 0.70, 51, 1000, 1, 9);

        $this->assertSame('stop', $belowCutoff['decision']);
        $this->assertContains('sufficient_and_marginal_yield_collapsed', $belowCutoff['reasons']);

        $this->assertSame('continue', $aboveCutoff['decision']);
        $this->assertContains('sufficient_but_marginal_yield_remains', $aboveCutoff['reasons']);
    }
}
