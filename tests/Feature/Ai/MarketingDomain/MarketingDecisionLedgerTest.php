<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Decision\MarketingDecisionLedger;
use App\Services\Ai\MarketingDomain\Decision\MarketingSymptomActionTree;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingDecisionLedgerTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_records_a_diagnosis_then_closes_the_loop_and_compounds(): void
    {
        $ledger = app(MarketingDecisionLedger::class);
        $tree = app(MarketingSymptomActionTree::class);

        // A real Stage-1 diagnosis flows straight into the ledger.
        $diagnosis = $tree->diagnose(
            ['ad_ctr' => 0.012, 'sales' => 3, 'spend' => 400.0],
            ['test_decision_spend' => 378.0, 'max_cpa' => 126.0],
        )['primary'];

        $entry = $ledger->record($diagnosis, [
            'campaign_ref' => 'OT169',
            'niche' => 'weight_loss',
            'offer_state' => ['ad_ctr' => 0.012, 'spend' => 400.0],
        ]);

        $this->assertSame(MarketingDecisionLedger::OUTCOME_PENDING, $entry->outcome);
        $this->assertSame('edit_bridge_headline', $entry->action);
        $this->assertNotSame('', $entry->decision_hash);

        // Close the loop with the measured result.
        $closed = $ledger->attachResult(
            $entry->id,
            MarketingDecisionLedger::OUTCOME_IMPROVED,
            ['ad_ctr_after' => 0.041],
            'novo ângulo casou com a intenção da keyword',
        );
        $this->assertSame(MarketingDecisionLedger::OUTCOME_IMPROVED, $closed->outcome);
        $this->assertNotNull($closed->measured_at);

        // Recall compounds: the lever now has a win-rate for this niche.
        $recall = $ledger->recall(['niche' => 'weight_loss']);
        $this->assertSame(1, $recall['count']);
        $this->assertSame(1.0, $recall['summary']['by_action']['edit_bridge_headline']['win_rate']);
    }

    public function test_recall_filters_by_action_and_invalid_outcome_is_coerced(): void
    {
        $ledger = app(MarketingDecisionLedger::class);

        $entry = $ledger->record(
            ['stage' => 'scale', 'action' => 'raise_bid', 'symptom' => 'healthy', 'lever' => 'scale-operator'],
            ['niche' => 'nerve'],
        );
        $ledger->attachResult($entry->id, 'garbage-value'); // coerced to no_change

        $recall = $ledger->recall(['action' => 'raise_bid']);
        $this->assertSame(1, $recall['count']);
        $this->assertSame('no_change', $recall['entries'][0]['outcome']);
        // no_change is neutral → win_rate null (nothing resolved improved/worse)
        $this->assertNull($recall['summary']['by_action']['raise_bid']['win_rate']);
    }
}
