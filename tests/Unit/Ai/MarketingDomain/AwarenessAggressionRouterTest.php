<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\AwarenessAggressionRouter;
use PHPUnit\Framework\TestCase;

/**
 * AwarenessAggressionRouter — fires the aggressive levers that FIT the reader's awareness stage
 * (Schwartz). Cold/unaware gets fear/enemy/story; most-aware gets scarcity/deadline/price. Wrong lever
 * at the wrong stage kills conversion.
 */
class AwarenessAggressionRouterTest extends TestCase
{
    public function test_unaware_leads_with_story_enemy_not_scarcity_or_social_proof(): void
    {
        $r = new AwarenessAggressionRouter;
        $fit = $r->fittingTactics('unaware');
        // Story/enemy/dream open the unaware reader (make them recognize the problem) — not fear-first.
        $this->assertSame('conspiracy_enemy', $fit[0]);
        // Scarcity/price must NOT be the opener for someone who doesn't know they have the problem.
        $this->assertNotContains('manufactured_scarcity', array_slice($fit, 0, 2));
        $this->assertNotContains('price_anchoring_extreme', array_slice($fit, 0, 2));
        // Social proof presupposes the reader already owns the problem — wrong for unaware.
        $this->assertNotContains('social_proof_pressure', $fit);
    }

    public function test_most_aware_leads_with_the_deal_scarcity_is_the_closer(): void
    {
        $r = new AwarenessAggressionRouter;
        $fit = $r->fittingTactics('most aware');
        // Most-aware compares the DEAL: price/offer/risk-reversal lead; scarcity/deadline close.
        $this->assertSame('price_anchoring_extreme', $fit[0]);
        $this->assertContains('risk_reversal_aggressive', array_slice($fit, 0, 2));
        // Scarcity + deadline still present, but as the CLOSER, not the opener.
        $this->assertContains('manufactured_scarcity', $fit);
        $this->assertGreaterThan(1, array_search('manufactured_scarcity', $fit, true));
    }

    public function test_prioritize_reorders_candidates_by_awareness(): void
    {
        $r = new AwarenessAggressionRouter;
        $candidates = ['manufactured_scarcity', 'fear_amplification', 'price_anchoring_extreme'];
        // For an unaware reader, fear (in-stage) leads; scarcity/price (out-of-stage) sink.
        $this->assertSame('fear_amplification', $r->prioritize($candidates, 'unaware')[0]);
        // For a most-aware reader, the deal (price anchoring) leads.
        $this->assertSame('price_anchoring_extreme', $r->prioritize($candidates, 'most_aware')[0]);
    }

    public function test_normalizes_aliases_and_defaults(): void
    {
        $r = new AwarenessAggressionRouter;
        $this->assertSame('problem_aware', $r->normalize(''));
        $this->assertSame('unaware', $r->normalize('completely unaware cold traffic'));
        $this->assertSame('product_aware', $r->normalize('product aware'));
        $this->assertSame('most_aware', $r->normalize('mais consciente'));
        $this->assertNotEmpty($r->rationale('unaware'));
    }
}
