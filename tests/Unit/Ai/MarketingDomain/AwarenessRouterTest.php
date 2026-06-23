<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\AwarenessRouter;
use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\AwarenessSophisticationLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the routing meta-layer: each awareness level prescribes the canonical Schwartz lead, the router
 * detects which level a copy is written for, and flags the mismatch that converts at zero (most-aware
 * copy shown to problem-aware traffic). Also: the library plugs into the one generic scorer.
 */
class AwarenessRouterTest extends TestCase
{
    public function test_prescribes_the_canonical_lead_per_awareness(): void
    {
        $r = new AwarenessRouter;
        $this->assertSame('story', $r->route('unaware')['lead_type']);
        $this->assertSame('mechanism', $r->route('solution_aware')['lead_type']);
        $this->assertSame('direct-cta', $r->route('most_aware')['lead_type']);
        $this->assertContains('new_opportunity', $r->route('solution_aware')['angles']);
    }

    public function test_detects_mismatch_that_kills_conversion(): void
    {
        $r = new AwarenessRouter;

        $mismatch = $r->match('Order now and claim your discount — last chance, today only, get yours.', 'problem_aware');
        $this->assertFalse($mismatch['aligned']);
        $this->assertSame('most_aware', $mismatch['detected']);

        $aligned = $r->match('If you are struggling and tired of the scale not moving, and nothing seems to work.', 'problem_aware');
        $this->assertTrue($aligned['aligned']);
    }

    public function test_library_plugs_into_the_generic_scorer(): void
    {
        $r = (new PatternLibraryScorer)->score(new AwarenessSophisticationLibrary, 'unlike ozempic, the protocol — how it works, the only one without the needle');
        $this->assertSame('awareness_sophistication', $r['library']);
        $this->assertArrayHasKey('awareness', $r['by_category']);
        $this->assertArrayHasKey('sophistication', $r['by_category']);
    }
}
