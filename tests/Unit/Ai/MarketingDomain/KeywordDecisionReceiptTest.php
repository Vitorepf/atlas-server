<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordDecisionReceipt;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L12 Decision-Receipt: every keyword decision is reproducible (same inputs → same hash,
 * bit-a-bit) and carries provenance (cites the L0 canonical laws). This is what makes "100% determinístico"
 * VERIFICÁVEL, não só uma palavra.
 */
class KeywordDecisionReceiptTest extends TestCase
{
    private KeywordDecisionReceipt $r;

    protected function setUp(): void
    {
        $this->r = new KeywordDecisionReceipt;
    }

    private function row(int $score = 90, string $risk = 'high'): array
    {
        return [
            'keyword' => 'blue salt trick',
            'score' => $score,
            'band' => 'scale',
            'family' => 'mechanism_trick',
            'match_type' => 'exact/phrase',
            'intent' => ['tier' => 'T4', 'action' => 'buy', 'polarity' => 'neutral', 'confidence' => 0.85],
            'investment' => ['verdict' => 'investimento', 'basis' => 'forecast_prior'],
            'account_risk' => ['risk_level' => $risk, 'flags' => [['type' => 'restricted_drug']]],
            'mind_state' => ['awareness' => 'most_aware'],
        ];
    }

    public function test_same_input_yields_same_hash(): void
    {
        $a = $this->r->issue($this->row());
        $b = $this->r->issue($this->row());
        $this->assertSame($a['receipt_hash'], $b['receipt_hash'], 'repetibilidade bit-a-bit: mesma entrada → mesmo hash');
        $this->assertSame(40, strlen($a['receipt_hash'])); // sha1
    }

    public function test_different_input_yields_different_hash(): void
    {
        $this->assertNotSame(
            $this->r->issue($this->row(90))['receipt_hash'],
            $this->r->issue($this->row(50))['receipt_hash'],
        );
    }

    public function test_receipt_cites_the_relevant_canonical_laws(): void
    {
        $ids = array_column($this->r->issue($this->row())['provenance'], 'id');
        $this->assertContains('breakeven-epc', $ids);       // investimento-vs-gasto
        $this->assertContains('rule-of-three', $ids);
        $this->assertContains('schwartz-awareness', $ids);  // intenção
        $this->assertContains('restricted-drug-suspension', $ids); // account_risk high
        $this->assertContains('expected-ctr-king', $ids);   // base QS de toda decisão
    }

    public function test_each_motor_is_the_single_source_of_its_laws(): void
    {
        // L0: o recibo cita exatamente as leis que os motores DECLARAM (const LAWS), não ids soltos.
        $ids = array_column($this->r->issue($this->row())['provenance'], 'id');
        foreach (\App\Services\Ai\MarketingDomain\Campaign\IntentLadderClassifier::LAWS as $law) {
            $this->assertContains($law, $ids);
        }
        foreach (\App\Services\Ai\MarketingDomain\Campaign\KeywordClusterer::LAWS as $law) {
            $this->assertContains($law, $ids); // match_type presente → cita as leis de estrutura/match
        }
    }

    public function test_flywheel_weighted_keyword_cites_the_real_sale_law(): void
    {
        $row = $this->row();
        $row['outcome_weight'] = 0.3; // L10 aplicou peso de venda real
        $ids = array_column($this->r->issue($row)['provenance'], 'id');
        $this->assertContains('offline-conversion-upstream', $ids, 'keyword calibrada pela venda real cita a lei da venda real');

        // sem peso aplicado (neutro) → não cita a lei do flywheel (proveniência honesta)
        $clean = array_column($this->r->issue($this->row())['provenance'], 'id');
        $this->assertNotContains('offline-conversion-upstream', $clean);
    }

    public function test_clean_keyword_does_not_cite_drug_law(): void
    {
        $ids = array_column($this->r->issue($this->row(90, 'none'))['provenance'], 'id');
        $this->assertNotContains('restricted-drug-suspension', $ids);
    }

    public function test_receipt_is_complete_and_versioned(): void
    {
        $rec = $this->r->issue($this->row());
        foreach (['keyword', 'decision', 'provenance', 'core_version', 'receipt_hash'] as $k) {
            $this->assertArrayHasKey($k, $rec);
        }
        $this->assertNotEmpty($rec['provenance']);
        foreach ($rec['provenance'] as $law) {
            $this->assertArrayHasKey('source', $law); // proveniência tem fonte real
        }
    }
}
