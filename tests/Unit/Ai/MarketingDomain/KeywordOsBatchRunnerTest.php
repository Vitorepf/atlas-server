<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordOsBatchRunner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L12 scale/ledger: the pipeline runs over N offers as a DETERMINISTIC, IDEMPOTENT batch with a
 * ledger that proves each verdict — same offers (any order) → same batch_hash. This is "escalar milhões"
 * as system engineering: repeatable over thousands of offers, audited per run.
 */
class KeywordOsBatchRunnerTest extends TestCase
{
    private function offerA(): array
    {
        return ['asset' => new AiMarketingVslAsset(['mechanism_name' => 'Blue Salt Trick', 'trick' => 'salt water protocol', 'niche' => 'ed', 'offer' => ['product_name' => 'BlueMax']]), 'econ' => ['payout' => 90, 'cvr' => 0.012]];
    }

    private function offerB(): array
    {
        return ['asset' => new AiMarketingVslAsset(['mechanism_name' => 'Triple Hormone Drops Protocol', 'trick' => 'at-home retatrutide protocol', 'niche' => 'weight loss', 'offer' => ['product_name' => 'Lipo Bliss']]), 'econ' => ['payout' => 120, 'cvr' => 0.012]];
    }

    public function test_batch_is_deterministic_and_idempotent(): void
    {
        $r = new KeywordOsBatchRunner;
        $a = $r->runBatch([$this->offerA(), $this->offerB()]);
        $b = $r->runBatch([$this->offerA(), $this->offerB()]);
        $this->assertSame($a['batch_hash'], $b['batch_hash'], 'mesmas ofertas → mesmo batch_hash');
        $this->assertSame($a['ledger'], $b['ledger']);
        $this->assertSame(2, $a['count']);
    }

    public function test_batch_is_order_independent(): void
    {
        $r = new KeywordOsBatchRunner;
        $ab = $r->runBatch([$this->offerA(), $this->offerB()]);
        $ba = $r->runBatch([$this->offerB(), $this->offerA()]);
        $this->assertSame($ab['batch_hash'], $ba['batch_hash'], 'ordem de entrada não muda o lote');
    }

    public function test_ledger_proves_each_offer(): void
    {
        $a = (new KeywordOsBatchRunner)->runBatch([$this->offerA(), $this->offerB()]);
        $this->assertCount(2, $a['ledger']);
        foreach ($a['ledger'] as $row) {
            foreach (['offer_fingerprint', 'run_hash', 'universe_count', 'scored_count', 'recommended_count', 'high_risk_count', 'enough'] as $k) {
                $this->assertArrayHasKey($k, $row);
            }
            $this->assertSame(40, strlen($row['run_hash']));
        }
        // the weight-loss offer (retatrutide) must surface high-risk keywords for the operator.
        $highRisk = array_sum(array_column($a['ledger'], 'high_risk_count'));
        $this->assertGreaterThan(0, $highRisk);
    }

    public function test_changing_economics_changes_the_batch(): void
    {
        $r = new KeywordOsBatchRunner;
        $base = $r->runBatch([$this->offerB()]);
        $changed = $r->runBatch([['asset' => $this->offerB()['asset'], 'econ' => ['payout' => 30, 'cvr' => 0.012]]]);
        $this->assertNotSame($base['batch_hash'], $changed['batch_hash']);
    }
}
