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

    public function test_discovered_real_terms_are_merged_scored_and_deduped(): void
    {
        $p = new KeywordIntelligencePipeline;
        $base = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012]);
        $existing = $base['scored'][0]['keyword']; // um termo que o grid sintético já tem

        $r = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012], [
            'discovered_terms' => ['bariatric gelatin trick', 'pink gelatin trick', $existing],
        ]);

        $this->assertSame(2, $r['discovered_count'], 'os 2 termos novos entram; o duplicado do grid é deduplicado');
        $scoredKw = array_column($r['scored'], 'keyword');
        $this->assertContains('bariatric gelatin trick', $scoredKw, 'o termo real descoberto é pontuado pelo OS');
        $this->assertGreaterThan(count($base['scored']), count($r['scored']), 'a descoberta real amplia o universo pontuado');
    }

    public function test_discovery_is_idempotent(): void
    {
        $p = new KeywordIntelligencePipeline;
        $opts = ['discovered_terms' => ['bariatric gelatin trick', 'pink gelatin trick']];
        $a = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012], $opts);
        $b = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012], $opts);
        $this->assertSame(array_column($a['scored'], 'keyword'), array_column($b['scored'], 'keyword'));
    }

    public function test_mined_real_waste_negatives_flow_into_the_pipeline(): void
    {
        $p = new KeywordIntelligencePipeline;
        $r = $p->run($this->asset(), ['payout' => 120, 'cvr' => 0.012], [
            'mined_negatives' => ['recipe weight', 'blueberry trick'],
        ]);
        $this->assertContains('recipe weight', $r['negatives']['flat'], 'negativo do waste real entra no deliverable');
        $this->assertArrayHasKey('mined_real_waste', $r['negatives']['layers']);
    }

    public function test_slogan_coined_offer_is_covered_not_empty(): void
    {
        // oferta SEM mechanism/trick mas com SLOGAN coined → deve ter cobertura (buraco do painel adversarial)
        $asset = new AiMarketingVslAsset([
            'mechanism_name' => '', 'trick' => '', 'niche' => 'weight loss',
            'power_phrases' => ['make america skinny again'],
            'offer' => ['product_name' => 'SlimX'],
        ]);
        $r = (new KeywordIntelligencePipeline)->run($asset, ['payout' => 100, 'cvr' => 0.01]);
        $this->assertGreaterThan(0, $r['universe']['count'], 'oferta coined-por-slogan tem cobertura, não zero');
        $this->assertGreaterThan(0, count($r['scored']));
    }

    public function test_offer_without_any_coined_lever_is_honestly_empty(): void
    {
        // sem mechanism/trick/slogan: product-name é PROIBIDO (tese) → não há o que bidar honestamente.
        $bare = new AiMarketingVslAsset(['mechanism_name' => '', 'trick' => '', 'niche' => 'weight loss', 'offer' => ['product_name' => 'SlimX']]);
        $r = (new KeywordIntelligencePipeline)->run($bare, ['payout' => 100, 'cvr' => 0.01]);
        $this->assertSame(0, $r['universe']['count']);
        $this->assertFalse($r['universe']['complete'], 'sem alavanca coined o OS sinaliza incompleto — não finge cobertura');
    }

    public function test_fingerprint_changes_with_economics(): void
    {
        $p = new KeywordIntelligencePipeline;
        $a = $p->run($this->asset(), ['payout' => 120]);
        $b = $p->run($this->asset(), ['payout' => 40]);
        $this->assertNotSame($a['run_hash'], $b['run_hash']);
    }
}
