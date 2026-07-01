<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidComparator;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderBid;
use Tests\TestCase;

final class BidComparatorTest extends TestCase
{
    private function bid(array $overrides = []): ProviderBid
    {
        $base = $overrides + [
            'providerId' => 'codex',
            'capabilityScore' => 80,
            'eligibilityBool' => true,
            'ineligibilityReasons' => [],
            'declaredCostUnits' => 500,
            'declaredEtaMs' => 1200,
            'bidHash' => str_repeat('a', 64),
        ];

        return new ProviderBid(
            providerId: $base['providerId'],
            capabilityScore: $base['capabilityScore'],
            eligibilityBool: $base['eligibilityBool'],
            ineligibilityReasons: $base['ineligibilityReasons'],
            declaredCostUnits: $base['declaredCostUnits'],
            declaredEtaMs: $base['declaredEtaMs'],
            bidHash: $base['bidHash'],
        );
    }

    // ── AC: ineligible bids sort after eligible bids regardless of cost ────────

    public function test_ineligible_bid_sorts_after_eligible_bid_regardless_of_cost(): void
    {
        $cheapIneligible = $this->bid(['providerId' => 'cheap', 'eligibilityBool' => false, 'ineligibilityReasons' => ['risk_tier_mismatch'], 'declaredCostUnits' => 1]);
        $costlyEligible = $this->bid(['providerId' => 'costly', 'eligibilityBool' => true, 'declaredCostUnits' => 9999]);

        $comparator = new BidComparator('task-1');
        [$cmp, $step] = $comparator->stepCompare($costlyEligible, $cheapIneligible);

        $this->assertSame('eligibility', $step);
        $this->assertLessThan(0, $cmp, 'eligible bid must sort before ineligible bid regardless of cost');
    }

    // ── AC: stronger proof capability beats cheaper weak proof for high-risk tasks ──

    public function test_stronger_proof_capability_beats_cheaper_weak_proof_for_high_risk_task(): void
    {
        $strongProof = $this->bid(['providerId' => 'strong', 'capabilityScore' => 90, 'declaredCostUnits' => 999]);
        $weakProofCheap = $this->bid(['providerId' => 'weak', 'capabilityScore' => 50, 'declaredCostUnits' => 1]);

        $comparator = new BidComparator('high-risk-task-1');
        [$cmp, $step] = $comparator->stepCompare($strongProof, $weakProofCheap);

        $this->assertSame('capability', $step);
        $this->assertLessThan(0, $cmp, 'stronger proof capability must win even against a far cheaper weak-proof bid');
    }

    // ── AC: stepCompare explains the first decisive comparison ─────────────────

    public function test_step_compare_names_cost_as_decisive_when_only_cost_differs(): void
    {
        $cheaper = $this->bid(['providerId' => 'a', 'declaredCostUnits' => 100]);
        $pricier = $this->bid(['providerId' => 'b', 'declaredCostUnits' => 200]);

        $comparator = new BidComparator('task-1');
        [$cmp, $step] = $comparator->stepCompare($cheaper, $pricier);

        $this->assertSame('cost', $step);
        $this->assertLessThan(0, $cmp);
    }

    public function test_step_compare_names_eta_as_decisive_when_only_eta_differs(): void
    {
        $faster = $this->bid(['providerId' => 'a', 'declaredEtaMs' => 500]);
        $slower = $this->bid(['providerId' => 'b', 'declaredEtaMs' => 1500]);

        $comparator = new BidComparator('task-1');
        [$cmp, $step] = $comparator->stepCompare($faster, $slower);

        $this->assertSame('eta', $step);
        $this->assertLessThan(0, $cmp);
    }

    public function test_step_compare_names_tiebreak_when_all_ranked_facts_are_equal(): void
    {
        $a = $this->bid(['providerId' => 'alpha']);
        $b = $this->bid(['providerId' => 'beta']);

        $comparator = new BidComparator('task-tie');
        [, $step] = $comparator->stepCompare($a, $b);

        $this->assertSame('tiebreak', $step);
    }

    public function test_compare_and_step_compare_agree_on_the_same_comparison(): void
    {
        $a = $this->bid(['providerId' => 'a', 'declaredCostUnits' => 100]);
        $b = $this->bid(['providerId' => 'b', 'declaredCostUnits' => 200]);

        $comparator = new BidComparator('task-1');
        $this->assertSame($comparator->compare($a, $b), $comparator->stepCompare($a, $b)[0]);
    }
}
