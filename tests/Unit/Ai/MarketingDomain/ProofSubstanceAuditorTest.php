<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ProofSubstanceAuditor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ProofSubstanceAuditor v2 — proof concreteness as structural truth (Eixo 7). A second brutal panel proved
 * v1 was a vocabulary proxy (hype/discount/guarantee scored concrete; transformation/credential scored
 * weak). These are the ADVERSARIAL regression cases from that panel: a number is proof only tied to a
 * RESULT; transformation, credentialed authority and income receipts count; guarantee/discount/CTA do not.
 */
class ProofSubstanceAuditorTest extends TestCase
{
    private ProofSubstanceAuditor $p;

    protected function setUp(): void
    {
        $this->p = new ProofSubstanceAuditor;
    }

    /** @return array<string,array{0:string}> */
    public static function hypeAndJunk(): array
    {
        return [
            'percent hype' => ['You will feel 100% better and 100% more confident, guaranteed.'],
            'percent discount' => ['Get 50% off today only, limited spots.'],
            'date without result' => ['In 7 days you will not believe how different everything feels.'],
            'abstract mechanism' => ['This activates your inner confidence and motivation today.'],
            'cta as demo' => ['Just try it and see for yourself how good life can be.'],
            'guarantee is not efficacy' => ['Backed by our 60-day money-back guarantee, zero risk.'],
            'recipe ratio' => ['Mix 2 in 1 ratio of water and powder daily.'],
            'delivery date' => ['Ships in 3 days to your door.'],
            'mailing list count' => ['We have 250 customers in our mailing list.'],
            'authority stuffing' => ['Doctor. University. Clinic. Journal. Institute.'],
            'negated count' => ['Not a single one of the 312 people saw results.'],
        ];
    }

    #[DataProvider('hypeAndJunk')]
    public function test_hype_and_junk_is_not_concrete_proof(string $copy): void
    {
        $this->assertFalse($this->p->audit($copy)['has_concrete'], "should NOT be concrete: {$copy}");
    }

    /** @return array<string,array{0:string}> */
    public static function eliteProof(): array
    {
        return [
            'first-person weight' => ['I lost 34 pounds and my husband cried.'],
            'before-after size' => ['Maria went from a size 16 to a size 8.'],
            'income receipt' => ['I pulled $4,217 in my first week.'],
            'small count tied to result' => ['All 9 men in the pilot regrew visible hair.'],
            'clinical reversal' => ['My A1c went from 9.2 to 5.6.'],
            'credential no title' => ['Harvard-trained, 20 years in cardiology.'],
            'authority by role' => ['Lead cardiac surgeon Aronson tested this on his patients.'],
            'studied count + result' => ['We ran the trial on 312 participants and they lost weight.'],
            'rhetorical not-negation' => ['You cannot ignore what Dr. Smith found.'],
            'full grand-slam' => ['Dr. Aronson ran this on 312 women; 9 out of 10 dropped a dress size in 6 weeks.'],
        ];
    }

    #[DataProvider('eliteProof')]
    public function test_elite_proof_is_recognized_as_concrete(string $copy): void
    {
        $this->assertTrue($this->p->audit($copy)['has_concrete'], "should be concrete: {$copy}");
    }

    public function test_flags_vague_proof_with_no_concrete_anchor(): void
    {
        $r = $this->p->audit('Studies show it works. Experts agree. Thousands of people love it.');
        $this->assertFalse($r['has_concrete']);
        $this->assertContains('studies_show', $r['vague']);
        $this->assertContains('mass_vague', $r['vague']);
    }

    public function test_negated_proof_does_not_count(): void
    {
        $this->assertSame([], $this->p->audit('There is no clinical proof and no doctor behind this yet.')['concrete']);
    }

    public function test_empty_proof_placeholder_does_not_count(): void
    {
        $this->assertFalse($this->p->audit('Here is the deal. [PROOF SLOT: estudo/depoimento mais forte da oferta].')['has_concrete']);
    }

    public function test_the_watch_presentation_cta_is_not_proof(): void
    {
        $this->assertFalse($this->p->audit('Watch the free presentation now — spots are limited.')['has_concrete']);
    }

    public function test_strength_ranks_transformation_above_a_weaker_anchor(): void
    {
        $strong = $this->p->strength('I lost 34 pounds in 8 weeks.');          // transformation, tier 5
        $weaker = $this->p->strength('It works by lowering your insulin.');     // mechanism, tier 3
        $this->assertGreaterThan($weaker, $strong);
        $this->assertGreaterThan(0, $weaker);
    }
}
