<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordAccountRiskSignal;
use PHPUnit\Framework\TestCase;

/**
 * Locks the account-death SIGNAL (report §6: conta morta = CPA infinito). It flags restricted-drug /
 * brand-bidding / celebrity landmines on a keyword and surfaces them to the operator — it is a SIGNAL,
 * NEVER a freio (compliance is the operator's decision on top — [[atlas-no-moral-limit-in-engine]]).
 */
class KeywordAccountRiskSignalTest extends TestCase
{
    private KeywordAccountRiskSignal $s;

    protected function setUp(): void
    {
        $this->s = new KeywordAccountRiskSignal;
    }

    public function test_restricted_drug_embedded_in_surviving_keyword_is_flagged_high(): void
    {
        // the hero "at-home retatrutide protocol" is an owned root that SURVIVES elimination — but it
        // still names a LegitScript-gated drug → the signal must surface the suspension risk.
        $r = $this->s->assess('at-home retatrutide protocol');
        $this->assertSame('high', $r['risk_level']);
        $this->assertSame('restricted_drug', $r['flags'][0]['type']);

        $this->assertSame('high', $this->s->assess('glp-1 alternative')['risk_level']);
    }

    public function test_clean_keyword_has_no_risk(): void
    {
        $r = $this->s->assess('blue salt trick');
        $this->assertSame('none', $r['risk_level']);
        $this->assertSame([], $r['flags']);
    }

    public function test_brand_bidding_is_flagged_medium(): void
    {
        $r = $this->s->assess('lipo bliss reviews', ['brand_lexicon' => ['lipo bliss']]);
        $this->assertSame('medium', $r['risk_level']);
        $this->assertSame('brand_bidding', $r['flags'][0]['type']);
    }

    public function test_celebrity_is_flagged_high(): void
    {
        $r = $this->s->assess('melania trump weight loss drops', ['celebrity_lexicon' => ['melania trump']]);
        $this->assertSame('high', $r['risk_level']);
        $this->assertContains('celebrity', array_column($r['flags'], 'type'));
    }

    public function test_it_is_a_signal_not_a_freio(): void
    {
        $r = $this->s->assess('at-home retatrutide protocol');
        $this->assertStringContainsString('NÃO é freio', $r['decision']);
    }
}
