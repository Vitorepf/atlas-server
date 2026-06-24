<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\SalesMomentPatternIndex;
use App\Services\Ai\MarketingDomain\Knowledge\TacticalNegotiationLibrary;
use PHPUnit\Framework\TestCase;

/**
 * TacticalNegotiationLibrary — Voss applied to copy. Conforms to PatternLibrary, scored by the single
 * scorer, indexed by sales moment.
 */
class TacticalNegotiationLibraryTest extends TestCase
{
    public function test_conforms_to_contract(): void
    {
        $lib = new TacticalNegotiationLibrary;
        $this->assertSame('tactical_negotiation', $lib->name());
        $this->assertNotEmpty($lib->all());
        foreach ($lib->all() as $p) {
            foreach (['key', 'name', 'category', 'weight', 'trigger', 'lever', 'markers', 'sales_moment'] as $f) {
                $this->assertArrayHasKey($f, $p);
            }
            $this->assertContains($p['category'], $lib->categories());
        }
        $this->assertSame(count($lib->all()), count(array_unique(array_column($lib->all(), 'key'))));
    }

    public function test_scorer_detects_voss_moves(): void
    {
        $copy = 'You are probably thinking this is just another scam. It seems like you have been burned before. '
            .'What is it costing you to wait? Would it be ridiculous to give it one shot?';
        $present = (new PatternLibraryScorer)->score(new TacticalNegotiationLibrary, $copy)['present'];
        $this->assertContains('accusation_audit', $present);
        $this->assertContains('labeling', $present);
        $this->assertContains('calibrated_question', $present);
        $this->assertContains('no_oriented_question', $present);
    }

    public function test_indexed_across_objection_and_close_moments(): void
    {
        $idx = new SalesMomentPatternIndex([new TacticalNegotiationLibrary]);
        $this->assertContains('accusation_audit', array_column($idx->byMoment('objection'), 'key'));
        $this->assertContains('calibrated_question', array_column($idx->byMoment('close'), 'key'));
    }
}
