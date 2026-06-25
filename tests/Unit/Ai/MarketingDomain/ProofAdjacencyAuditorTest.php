<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ProofAdjacencyAuditor;
use PHPUnit\Framework\TestCase;

/**
 * ProofAdjacencyAuditor — believability: every bold claim needs EXTERNAL proof adjacent to it. An orphan
 * claim (promise with no verifiable proof beside it) is a conversion liability. Structural, not a score.
 */
class ProofAdjacencyAuditorTest extends TestCase
{
    public function test_a_bare_promise_is_an_orphan_claim(): void
    {
        $r = (new ProofAdjacencyAuditor)->audit('You will lose 34 lbs in 6 weeks. It is the easiest thing you will ever do.');
        $this->assertTrue($r['has_orphan_claim']);
        $this->assertSame([], $r['backed_claims']);
        $this->assertStringContainsString('descrença', $r['note']);
    }

    public function test_a_claim_with_adjacent_external_proof_is_backed(): void
    {
        $r = (new ProofAdjacencyAuditor)->audit('You will lose 34 lbs in 6 weeks. Dr. Aronson tracked 312 women and 9 out of 10 did exactly that.');
        $this->assertFalse($r['has_orphan_claim']);
        $this->assertNotEmpty($r['backed_claims']);
    }

    public function test_the_claims_own_number_does_not_count_as_its_proof(): void
    {
        // "you will lose 34 lbs" restates the claim — that is NOT external proof of it.
        $r = (new ProofAdjacencyAuditor)->audit('You will lose 34 lbs. Guaranteed.');
        $this->assertTrue($r['has_orphan_claim']);
    }

    public function test_no_strong_claim_means_nothing_to_back(): void
    {
        $r = (new ProofAdjacencyAuditor)->audit('Here is a little background on how metabolism works over time.');
        $this->assertFalse($r['has_orphan_claim']);
        $this->assertSame([], $r['orphan_claims']);
        $this->assertSame([], $r['backed_claims']);
    }

    public function test_cross_niche_finance_claim_backed_by_credential(): void
    {
        $r = (new ProofAdjacencyAuditor)->audit('You could double your money this year. Portfolio manager Ray Chen did it with 1,400 students, audited.');
        $this->assertFalse($r['has_orphan_claim']);
    }

    /**
     * Case-C regression (locks the over-block fix that conditions the proof_adjacency HARD floor): a bare
     * future-pacing / dream lead carries no result signal, so it is NOT a provable claim and must never be
     * orphan-flagged — otherwise a legitimate cold-traffic lead would force a bad re-roll.
     */
    public function test_future_pacing_lead_is_not_an_orphan_claim(): void
    {
        $r = (new ProofAdjacencyAuditor)->audit(
            'You will finally understand why nothing worked before. You will see the real reason in this short presentation.'
        );
        $this->assertFalse($r['has_orphan_claim']);
        $this->assertSame([], $r['orphan_claims']);
    }

    public function test_result_tied_future_promise_is_still_a_claim(): void
    {
        // the tightening released ONLY bare future-pacing — a future promise carrying a real result is
        // still a provable claim that needs proof beside it.
        $r = (new ProofAdjacencyAuditor)->audit('You will lose 34 pounds. No special diet needed.');
        $this->assertTrue($r['has_orphan_claim']);
    }
}
