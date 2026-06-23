<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PolicyComplianceGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Base → Greenlight gatekeeper: the aggressive base page (drug names, conspiracy, fake
 * urgency, guaranteed/dramatic claims, celebrity) is flagged as suspension_risk; the calm Greenlight
 * copy passes clean. Compliant phrasing ("not guaranteed", the FDA "treat, cure, or prevent" disclaimer)
 * must NOT false-positive.
 */
class PolicyComplianceGateTest extends TestCase
{
    public function test_aggressive_copy_is_suspension_risk(): void
    {
        $aggressive = 'The at-home retatrutide protocol Big Pharma does not want you to see. As Melania revealed, '
            .'lose 63 lbs in 2 months — guaranteed. This leaked report is scheduled to be taken offline. Only 63 bottles left.';
        $r = (new PolicyComplianceGate)->scan($aggressive);
        $this->assertSame('suspension_risk', $r['verdict']);
        $this->assertSame('critical', $r['worst']);
        $cats = array_column($r['violations'], 'category');
        $this->assertContains('rx_drug_term', $cats);
        $this->assertContains('conspiracy_sensational', $cats);
        $this->assertContains('fake_urgency', $cats);
        $this->assertContains('celebrity_endorsement', $cats);
    }

    public function test_calm_educational_copy_is_greenlight(): void
    {
        $calm = 'A plain-language look at how metabolism can shift after 40. This is general wellness information, '
            .'not medical advice. Individual results vary and are not guaranteed. Watch the free educational presentation.';
        $r = (new PolicyComplianceGate)->scan($calm);
        $this->assertSame('greenlight', $r['verdict']);
        $this->assertSame(0, $r['n']);
    }

    public function test_not_guaranteed_is_not_flagged(): void
    {
        $this->assertSame(0, (new PolicyComplianceGate)->scan('Individual results vary and are not guaranteed.')['n']);
        $this->assertSame(0, (new PolicyComplianceGate)->scan('There is no guarantee of any result.')['n']);
    }

    public function test_standard_fda_disclaimer_is_not_flagged_as_miracle(): void
    {
        $r = (new PolicyComplianceGate)->scan('This is not intended to diagnose, treat, cure, or prevent any disease.');
        $cats = array_column($r['violations'], 'category');
        $this->assertNotContains('miracle_cure', $cats);
    }

    public function test_prescription_drug_term_is_critical(): void
    {
        $r = (new PolicyComplianceGate)->scan('a natural alternative to retatrutide and ozempic');
        $this->assertSame('critical', $r['worst']);
        $this->assertContains('rx_drug_term', array_column($r['violations'], 'category'));
    }

    public function test_each_violation_carries_a_fix(): void
    {
        foreach ((new PolicyComplianceGate)->scan('lose 40 lbs guaranteed with this miracle')['violations'] as $v) {
            $this->assertNotEmpty($v['fix']);
            $this->assertNotEmpty($v['evidence']);
        }
    }
}
