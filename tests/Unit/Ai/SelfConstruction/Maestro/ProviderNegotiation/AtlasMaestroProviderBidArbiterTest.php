<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidArbiter;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidArbitrationVerdict;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidSet;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\NoEligibleProviderVerdict;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderBid;
use Tests\TestCase;

final class AtlasMaestroProviderBidArbiterTest extends TestCase
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

    public function test_winner_is_stable_across_10_shuffles_of_the_input_order(): void
    {
        $a = $this->bid(['providerId' => 'A', 'capabilityScore' => 90, 'declaredCostUnits' => 400, 'declaredEtaMs' => 800]);
        $b = $this->bid(['providerId' => 'B', 'capabilityScore' => 80, 'declaredCostUnits' => 400, 'declaredEtaMs' => 700]);
        $c = $this->bid(['providerId' => 'C', 'capabilityScore' => 80, 'declaredCostUnits' => 300, 'declaredEtaMs' => 700]);
        $set = [$a, $b, $c];

        $arbiter = new AtlasMaestroProviderBidArbiter();
        $winners = [];
        for ($i = 0; $i < 10; $i++) {
            $shuffled = $set;
            shuffle($shuffled);
            $verdict = $arbiter->arbitrate(new BidSet($shuffled), 'task-1');
            $this->assertInstanceOf(BidArbitrationVerdict::class, $verdict);
            $winners[] = $verdict->winnerProviderId;
        }
        $this->assertCount(1, array_unique($winners), 'winner must be stable across shuffles');
        $this->assertSame('A', $winners[0]);
    }

    public function test_zero_eligible_bids_returns_no_eligible_provider_verdict_with_reason_codes(): void
    {
        $a = $this->bid(['providerId' => 'A', 'eligibilityBool' => false, 'ineligibilityReasons' => ['sensitivity_violation']]);
        $b = $this->bid(['providerId' => 'B', 'eligibilityBool' => false, 'ineligibilityReasons' => ['sensitivity_violation', 'locality_violation']]);

        $verdict = (new AtlasMaestroProviderBidArbiter())->arbitrate(new BidSet([$a, $b]), 'task-1');
        $this->assertInstanceOf(NoEligibleProviderVerdict::class, $verdict);
        $this->assertSame(2, $verdict->reasonCodes['sensitivity_violation']);
        $this->assertSame(1, $verdict->reasonCodes['locality_violation']);
    }

    public function test_verdict_is_replayable_byte_identical(): void
    {
        $set = new BidSet([
            $this->bid(['providerId' => 'A', 'capabilityScore' => 80]),
            $this->bid(['providerId' => 'B', 'capabilityScore' => 80, 'declaredEtaMs' => 1000]),
        ]);

        $a = (new AtlasMaestroProviderBidArbiter())->arbitrate($set, 'task-1');
        $b = (new AtlasMaestroProviderBidArbiter())->arbitrate($set, 'task-1');
        $this->assertSame(json_encode($a->toArray()), json_encode($b->toArray()));
    }

    public function test_criteria_trace_names_step_that_eliminated_each_runner(): void
    {
        $winner = $this->bid(['providerId' => 'A', 'capabilityScore' => 90]);
        $costRunner = $this->bid(['providerId' => 'B', 'capabilityScore' => 90, 'declaredCostUnits' => 999]);
        $etaRunner = $this->bid(['providerId' => 'C', 'capabilityScore' => 90, 'declaredEtaMs' => 9999]);
        $verdict = (new AtlasMaestroProviderBidArbiter())->arbitrate(new BidSet([$winner, $costRunner, $etaRunner]), 'task-1');

        $this->assertInstanceOf(BidArbitrationVerdict::class, $verdict);
        $this->assertSame('A', $verdict->winnerProviderId);
        $steps = array_column($verdict->criteriaTrace, 'eliminated_by');
        $this->assertContains('cost', $steps);
        $this->assertContains('eta', $steps);
    }

    public function test_arbiter_source_does_not_invoke_proposer_or_capability_score(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/ProviderNegotiation/AtlasMaestroProviderBidArbiter.php'));
        $this->assertStringNotContainsString('AtlasMaestroProviderBidProposer', $src);
        $this->assertStringNotContainsString('declaredCapabilities', $src);
    }

    public function test_stale_performance_bid_loses_to_fresh_bid_on_capability_step(): void
    {
        // Stale performance (recent failures) is surfaced as a penalized capabilityScore.
        $fresh = $this->bid(['providerId' => 'fresh', 'capabilityScore' => 85]);
        $stale = $this->bid(['providerId' => 'stale', 'capabilityScore' => 55]);

        $verdict = (new AtlasMaestroProviderBidArbiter())->arbitrate(new BidSet([$stale, $fresh]), 'task-stale');

        $this->assertInstanceOf(BidArbitrationVerdict::class, $verdict);
        $this->assertSame('fresh', $verdict->winnerProviderId);
        $this->assertSame('capability', $verdict->decisiveCriterion);
    }

    public function test_risk_tier_mismatch_makes_bid_ineligible_and_only_safe_bid_wins(): void
    {
        $safe       = $this->bid(['providerId' => 'safe-provider', 'capabilityScore' => 70]);
        $riskMismatch = $this->bid(['providerId' => 'risky', 'eligibilityBool' => false, 'ineligibilityReasons' => ['risk_tier_mismatch']]);

        $verdict = (new AtlasMaestroProviderBidArbiter())->arbitrate(new BidSet([$safe, $riskMismatch]), 'task-risk');

        $this->assertInstanceOf(BidArbitrationVerdict::class, $verdict);
        $this->assertSame('safe-provider', $verdict->winnerProviderId);
    }

    public function test_deterministic_tiebreak_when_all_ranked_criteria_equal(): void
    {
        $a = $this->bid(['providerId' => 'alpha', 'capabilityScore' => 80, 'declaredCostUnits' => 500, 'declaredEtaMs' => 1200]);
        $b = $this->bid(['providerId' => 'beta',  'capabilityScore' => 80, 'declaredCostUnits' => 500, 'declaredEtaMs' => 1200]);

        $v1 = (new AtlasMaestroProviderBidArbiter())->arbitrate(new BidSet([$a, $b]), 'task-tie');
        $v2 = (new AtlasMaestroProviderBidArbiter())->arbitrate(new BidSet([$b, $a]), 'task-tie');

        $this->assertInstanceOf(BidArbitrationVerdict::class, $v1);
        $this->assertSame('tiebreak', $v1->decisiveCriterion);
        $this->assertSame($v1->winnerProviderId, $v2->winnerProviderId, 'tiebreak winner must be stable regardless of input order');
    }
}
