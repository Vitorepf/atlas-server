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
        // Library deepened in Volta 2 (+10 rare levers); short grand-slam fires the core but
        // not the rare ones — asserts pin core levers explicitly (anti-Goodhart).
        $this->assertGreaterThanOrEqual(45, $r['score']);
        $this->assertContains($r['grade'], ['weak', 'decent', 'strong', 'killer']);

        foreach (['dream_outcome_concrete', 'proof_stack', 'bonus_stack', 'named_package',
            'value_anchored', 'risk_reversal_strong', 'scarcity_quantity', 'scarcity_time'] as $k) {
            $this->assertContains($k, $r['present'], "Expected lever {$k}");
        }
    }

    public function test_volta_2_rare_offer_levers_fire(): void
    {
        $elite = 'Total value: $4,997. Today only $97. The Triple Hormone Protocol™ — patented. '
            .'Bonus alone worth more than the product. Even if just for Bonus 3 this is a no-brainer. '
            .'Because we only produce 500 kits per month — small batch. '
            .'Why so cheap? Mission to break this barrier — covering costs. '
            .'Pay nothing today, pay $X when you see results. '
            .'My private contact at the lab — proprietary data nobody has. '
            .'Private community with 9,400 members + Q&A weekly with me. '
            .'After that, never again — price goes up to $497 next week. '
            ."If it doesn't work we pay you \$200 on top.";
        $r = (new PatternLibraryScorer)->score(new OfferArchitectureLibrary, $elite);

        foreach (['value_explosion_ratio', 'reverse_risk', 'naming_premium', 'bonus_supremacy',
            'scarcity_logic', 'price_drop_reason', 'split_payment', 'unfair_advantage_offer',
            'community_inclusion', 'declared_decline_terms'] as $k) {
            $this->assertContains($k, $r['present'], "Volta 2 rare offer lever {$k} should fire");
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

        $this->assertGreaterThanOrEqual(40, $r['score']);
        $this->assertContains('bonus_stack', $r['present']);
        $this->assertContains('risk_reversal_strong', $r['present']);
        $this->assertContains('named_package', $r['present']);
    }
}
