<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\CopySubstanceProbe;
use PHPUnit\Framework\TestCase;

/**
 * CopySubstanceProbe — the substance gate that stops keyword-stuffing from gaming the audience score.
 * It scores 0..1 how substantive copy is, independent of which conversion markers it fires.
 */
class CopySubstanceProbeTest extends TestCase
{
    public function test_keyword_salad_is_hollow(): void
    {
        $probe = new CopySubstanceProbe;
        $salad = 'track record verified audited as seen on bloomberg protect your capital low risk '
            .'realistic money-back backtested rule-based start with $100 step-by-step one-time no subscription';
        $this->assertLessThan(0.3, $probe->substance($salad), 'a marker list with no sentences must be hollow');
    }

    public function test_bare_label_stuffing_is_hollow(): void
    {
        $probe = new CopySubstanceProbe;
        // Grammatical sentences but only self-declared labels, zero credibility-specifics: the dressed scam.
        $scam = 'Our verified, audited track record speaks for itself. As seen on Forbes. Backed by a '
            .'proven, trusted, certified system.';
        $r = $probe->analyze($scam);
        $this->assertGreaterThanOrEqual(2, $r['label_claims']);
        $this->assertSame(0, $r['credibility_specifics']);
        $this->assertLessThan(0.6, $r['substance']);
    }

    public function test_real_story_with_specifics_is_substantive(): void
    {
        $probe = new CopySubstanceProbe;
        $elite = 'My father lost the house in 2008. I swore that would never be me. So I spent eleven '
            .'years building one boring rule. It has never had a losing year. Read the brokerage statements.';
        $this->assertGreaterThanOrEqual(0.8, $probe->substance($elite), 'narrative + named year + duration = substantive');
    }

    public function test_specifics_rescue_a_claim(): void
    {
        $probe = new CopySubstanceProbe;
        // Same labels, but now backed by a year + drawdown number → earns the claim, stays substantive.
        $earned = 'Audited track record since 2008. Worst drawdown was 11% across two crashes.';
        $this->assertGreaterThanOrEqual(0.8, $probe->substance($earned));
    }

    public function test_short_real_copy_is_not_falsely_flagged(): void
    {
        $probe = new CopySubstanceProbe;
        $this->assertGreaterThanOrEqual(0.8, $probe->substance('He left. You stayed. There is a way back.'));
    }
}
