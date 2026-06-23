<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\OfferArchitectureLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the offer-architecture layer (Hormozi Grand Slam): a fully stacked offer fires the dream
 * outcome (regex on lbs/days), bonus stack, value anchor, strong risk reversal, scarcity, and named
 * package; a commodity page scores zero; the architecture generalizes from health to finance — proving
 * the construction sells regardless of product.
 */
class OfferArchitectureLibraryTest extends TestCase
{
    private string $grandSlam = 'Lose 41 lbs in 12 weeks without diet or workout. The Triple Hormone Protocol Kit. '
        .'Bonus #1: Dream Waist Method (value: $197). Bonus #2: free training. Total value $1,997, today only $97. '
        .'As seen on CBS. 9,400 reviews. 60-day money-back, 100% no questions, keep the bonus. '
        .'Only 100 spots left. Tonight midnight expires. First 100 get the fast-action bonus. '
        .'Made in USA, doctor-formulated. 6-bottle kit best value. 3x no interest.';

    public function test_grand_slam_fires_the_core_offer_levers(): void
    {
        $r = (new PatternLibraryScorer)->score(new OfferArchitectureLibrary, $this->grandSlam);

        $this->assertSame('offer_architecture', $r['library']);
        $this->assertGreaterThanOrEqual(70, $r['score']);
        $this->assertContains($r['grade'], ['strong', 'killer']);

        foreach (['dream_outcome_concrete', 'proof_stack', 'bonus_stack', 'named_package',
            'value_anchored', 'risk_reversal_strong', 'scarcity_quantity', 'scarcity_time'] as $k) {
            $this->assertContains($k, $r['present'], "Expected lever {$k}");
        }
    }

    public function test_commodity_page_scores_zero(): void
    {
        $r = (new PatternLibraryScorer)->score(new OfferArchitectureLibrary, 'Our supplement helps weight loss. Buy on our website.');
        $this->assertSame(0, $r['score']);
        $this->assertSame('flat', $r['grade']);
    }

    public function test_generalizes_to_finance(): void
    {
        $finance = 'Earn $10k/month in 90 days with done-for-you templates. The Founder Pack. '
            .'Bonus 1: live training (value $497). Total value $3,000, today $297. As seen on Forbes. '
            .'90-day money-back guarantee, risk-free. Limited spots, deadline tonight. Premium tier with annual upgrade.';
        $r = (new PatternLibraryScorer)->score(new OfferArchitectureLibrary, $finance);

        $this->assertGreaterThanOrEqual(60, $r['score']);
        $this->assertContains('bonus_stack', $r['present']);
        $this->assertContains('risk_reversal_strong', $r['present']);
        $this->assertContains('named_package', $r['present']);
    }
}
