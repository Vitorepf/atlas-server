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
    public function test_unaware_leads_with_fear_not_scarcity(): void
    {
        $r = new AwarenessAggressionRouter;
        $fit = $r->fittingTactics('unaware');
        $this->assertSame('fear_amplification', $fit[0]);
        // Scarcity/price must NOT be the opener for someone who doesn't know they have the problem.
        $this->assertNotContains('manufactured_scarcity', array_slice($fit, 0, 2));
        $this->assertNotContains('price_anchoring_extreme', array_slice($fit, 0, 2));
    }

    public function test_most_aware_leads_with_scarcity_and_deadline(): void
    {
        $r = new AwarenessAggressionRouter;
        $fit = $r->fittingTactics('most aware');
        $this->assertContains('manufactured_scarcity', array_slice($fit, 0, 2));
        $this->assertContains('false_deadline', array_slice($fit, 0, 3));
    }

    public function test_prioritize_reorders_candidates_by_awareness(): void
    {
        $r = new AwarenessAggressionRouter;
        $candidates = ['manufactured_scarcity', 'fear_amplification', 'price_anchoring_extreme'];
        // For an unaware reader, fear should jump to the front; scarcity should sink.
        $ordered = $r->prioritize($candidates, 'unaware');
        $this->assertSame('fear_amplification', $ordered[0]);
        // For a most-aware reader, scarcity should lead.
        $this->assertSame('manufactured_scarcity', $r->prioritize($candidates, 'most_aware')[0]);
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
