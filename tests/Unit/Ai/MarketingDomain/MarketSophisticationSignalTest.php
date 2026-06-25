<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordKnowledgeCore;
use App\Services\Ai\MarketingDomain\Campaign\MarketSophisticationSignal;
use PHPUnit\Framework\TestCase;

/**
 * Locks Schwartz market sophistication operationalized: a jaded niche (weight loss/ED/blood sugar) demands
 * coined-mechanism keywords (generic benefit is dead, CVR ~1.3%); a less-saturated niche can use direct
 * benefit + a mechanism. Deterministic. The canonical law is also asserted present.
 */
class MarketSophisticationSignalTest extends TestCase
{
    private MarketSophisticationSignal $s;

    protected function setUp(): void
    {
        $this->s = new MarketSophisticationSignal;
    }

    public function test_saturated_niche_demands_coined_mechanism(): void
    {
        foreach (['weight loss', 'erectile dysfunction', 'blood sugar', 'tinnitus', 'nail fungus'] as $niche) {
            $r = $this->s->assess($niche);
            $this->assertTrue($r['saturated'], "'{$niche}' é jaded");
            $this->assertSame('coined_mechanism_mandatory', $r['strategy']);
            $this->assertSame(5, $r['stage']);
        }
    }

    public function test_unsaturated_niche_allows_direct_benefit(): void
    {
        $r = $this->s->assess('artisan sourdough kits');
        $this->assertFalse($r['saturated']);
        $this->assertSame('direct_benefit_plus_mechanism', $r['strategy']);
    }

    public function test_sophistication_law_is_canonical(): void
    {
        $law = (new KeywordKnowledgeCore)->cite('schwartz-sophistication');
        $this->assertNotNull($law);
        $this->assertStringContainsString('Schwartz', $law['statement']);
    }
}
