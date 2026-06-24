<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordIntelligencePipeline;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L12 orchestration: the loose engines run as ONE deterministic SYSTEM over an offer
 * (comprehension → discovery → grading → negatives → launch + receipts), idempotent bit-a-bit
 * (same offer+econ → same run_hash + same keywords + same receipt hashes).
 */
class KeywordIntelligencePipelineTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol',
            'niche' => 'weight loss',
            'offer' => ['product_name' => 'Lipo Bliss'],
        ]);
    }

    public function test_runs_the_full_system_end_to_end(): void
    {
        $r = (new KeywordIntelligencePipeline)->run($this->asset(), ['payout' => 120, 'cvr' => 0.012]);

        foreach (['offer_fingerprint', 'universe', 'scored', 'launch_selection', 'negatives', 'knowledge_version', 'run_hash'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertTrue($r['universe']['complete'], 'universo enumerado sem buracos');
        $this->assertGreaterThanOrEqual(5, count($r['scored']));
        foreach (['recommended', 'high_risk', 'rejected', 'knowledge'] as $k) {
            $this->assertArrayHasKey($k, $r['launch_selection']);
        }
        $this->assertNotEmpty($r['negatives']['flat']);
    }

    public function test_idempotent_same_offer_same_run_bit_a_bit(): void
    {
        $p = new KeywordIntelligencePipeline;
        $a = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012]);
        $b = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012]);

        $this->assertSame($a['run_hash'], $b['run_hash'], 'mesma oferta+econ → mesmo run_hash');
        $this->assertSame(
            array_column($a['scored'], 'keyword'),
            array_column($b['scored'], 'keyword'),
            'mesmo universo pontuado, mesma ordem',
        );
        // Decision-Receipts reproduzíveis por keyword
        $hashesA = array_map(fn ($row) => $row['receipt']['receipt_hash'], $a['launch_selection']['recommended']);
        $hashesB = array_map(fn ($row) => $row['receipt']['receipt_hash'], $b['launch_selection']['recommended']);
        $this->assertSame($hashesA, $hashesB);
    }

    public function test_fingerprint_changes_with_economics(): void
    {
        $p = new KeywordIntelligencePipeline;
        $a = $p->run($this->asset(), ['payout' => 120]);
        $b = $p->run($this->asset(), ['payout' => 40]);
        $this->assertNotSame($a['run_hash'], $b['run_hash']);
    }
}
