<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ProofProvenanceAuditor;
use PHPUnit\Framework\TestCase;

/**
 * ProofProvenanceAuditor — guards the OS's one moral line: it lists the attribution claims that REQUIRE
 * real backing from the producer before launch (named authority, media mention, attributed testimonial,
 * cited statistic). A checklist, not a fabrication detector and not a score — so it can't be gamed.
 */
class ProofProvenanceAuditorTest extends TestCase
{
    private function keys(string $copy): array
    {
        return array_column((new ProofProvenanceAuditor)->audit($copy)['requires_proof'], 'key');
    }

    public function test_flags_named_authority(): void
    {
        $this->assertContains('named_authority', $this->keys('Backed by Dr. Attia and a Harvard study.'));
    }

    public function test_flags_media_mention(): void
    {
        $this->assertContains('media_mention', $this->keys('As seen on CBS and featured in Forbes.'));
    }

    public function test_flags_attributed_testimonial(): void
    {
        $this->assertContains('attributed_testimonial', $this->keys('"I lost 30 lbs in a month" — Maria, 47'));
    }

    public function test_flags_cited_statistic(): void
    {
        $this->assertContains('cited_statistic', $this->keys('Studies show 80% of women see results in weeks.'));
    }

    public function test_clean_copy_with_no_attribution_is_clean(): void
    {
        $r = (new ProofProvenanceAuditor)->audit('A simple morning routine that helps you feel lighter and more energetic.');
        $this->assertTrue($r['clean']);
        $this->assertSame(0, $r['count']);
    }

    public function test_bare_claims_are_flagged_as_highest_risk(): void
    {
        // Pure label-claims, nothing checkable anywhere → bare (highest fabrication/compliance risk).
        $r = (new ProofProvenanceAuditor)->audit('As seen on CBS. Dr. Smith confirms this works.');
        $this->assertGreaterThanOrEqual(1, $r['bare_count']);
        $this->assertFalse($r['requires_proof'][0]['anchored'], 'bare claim must be tagged not-anchored');
    }

    public function test_a_checkable_anchor_lowers_the_risk_tag(): void
    {
        // A year + journal reference gives something to verify → anchored (still needs backing, lower risk).
        $r = (new ProofProvenanceAuditor)->audit('Dr. Smith, in a 2019 study published in a peer-reviewed journal, confirmed it.');
        $this->assertSame(0, $r['bare_count']);
        $this->assertTrue($r['requires_proof'][0]['anchored']);
    }

    public function test_counts_multiple_distinct_claim_types(): void
    {
        $copy = 'Dr. Smith confirms it. As seen on NBC. "It changed my life" — Ana, 52. '
            .'Clinically proven, with research showing 90% success.';
        $this->assertGreaterThanOrEqual(3, (new ProofProvenanceAuditor)->audit($copy)['count']);
    }
}
