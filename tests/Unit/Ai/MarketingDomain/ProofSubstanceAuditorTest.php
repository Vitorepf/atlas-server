<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ProofSubstanceAuditor;
use PHPUnit\Framework\TestCase;

/**
 * ProofSubstanceAuditor — proof concreteness as structural truth (Eixo 7). Concrete anchors (named
 * authority, specific count, ratio, mechanism, dated result) vs vague proof-tells. Built with the
 * value-equation lesson: word-boundary, negation-aware, placeholder-stripped — NOT a vocabulary proxy.
 */
class ProofSubstanceAuditorTest extends TestCase
{
    public function test_detects_concrete_proof_anchors(): void
    {
        $r = (new ProofSubstanceAuditor)->audit('Dr. Aronson ran this on 312 women at the clinic; 9 out of 10 dropped a dress size in 6 weeks.');
        $this->assertTrue($r['has_concrete']);
        $this->assertContains('named_authority', $r['concrete']);
        $this->assertContains('specific_count', $r['concrete']);
        $this->assertContains('ratio_or_percent', $r['concrete']);
        $this->assertSame([], $r['vague']);
    }

    public function test_flags_vague_proof_with_no_concrete_anchor(): void
    {
        $r = (new ProofSubstanceAuditor)->audit('Studies show it works. Experts agree. Thousands of people love it. Clinically proven.');
        $this->assertFalse($r['has_concrete']);
        $this->assertContains('studies_show', $r['vague']);
        $this->assertContains('mass_vague', $r['vague']);
        $this->assertStringContainsString('não converte', $r['note']);
    }

    public function test_negated_proof_does_not_count(): void
    {
        // "no clinical proof and no doctor" must NOT register as proof (the v1 value-equation bug).
        $r = (new ProofSubstanceAuditor)->audit('There is no clinical proof and no doctor behind this yet.');
        $this->assertSame([], $r['concrete']);
    }

    public function test_empty_proof_placeholder_does_not_count(): void
    {
        $r = (new ProofSubstanceAuditor)->audit('Here is the deal. [PROOF SLOT: estudo/depoimento mais forte da oferta].');
        $this->assertFalse($r['has_concrete']);
    }

    public function test_cross_niche_finance_mechanism_and_count(): void
    {
        $r = (new ProofSubstanceAuditor)->audit('It works by routing idle cash; 1,400 students banked an 8200 return, audited.');
        $this->assertContains('mechanism_of_action', $r['concrete']);
        $this->assertContains('specific_count', $r['concrete']);
    }

    public function test_mixed_keeps_concrete_and_still_flags_the_vague(): void
    {
        $r = (new ProofSubstanceAuditor)->audit('Studies show it helps, but Dr. Lee tracked 200 men and 80% kept it off.');
        $this->assertTrue($r['has_concrete']);
        $this->assertContains('studies_show', $r['vague']);
        $this->assertStringContainsString('substituir', $r['note']);
    }
}
