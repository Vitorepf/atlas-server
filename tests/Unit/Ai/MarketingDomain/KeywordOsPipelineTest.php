<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordQualityIndex;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordPatternEngine;
use PHPUnit\Framework\TestCase;

/**
 * THE META, end-to-end: dada uma oferta (VSL dissecada), o Atlas cospe de PRIMEIRA ≥5 keywords
 * QUALIFICADAS, cada uma com (a) a INTENÇÃO entendida (tier/journey/polarity/confidence) e (b) o
 * veredito INVESTIMENTO-vs-GASTO (breakeven / rule-of-three / EPC, forecast-prior até venda real).
 * Prova provider-free, cross-nicho, sem campanha live.
 */
class KeywordOsPipelineTest extends TestCase
{
    private function healthAsset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol',
            'niche' => 'weight loss',
            'angle' => 'a home-made retatrutide that activates GLP-1, GIP and glucagon',
            'hook' => 'Melania Trump lost 63 lbs on a free government at-home protocol',
            'power_phrases' => ['four simple ingredients', 'activates three fat-burning hormones', 'Make America Skinny Again'],
            'persuasion_devices' => ['authority' => ['Melania Trump', 'Dr. Mehmet Oz']],
            'offer' => ['product_name' => 'Lipo Bliss'],
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function pipeline(AiMarketingVslAsset $asset, array $econ): array
    {
        $engine = (new QualifiedKeywordPatternEngine)->build($asset);

        return (new KeywordQualityIndex)->scoreEngineResult($engine, $asset, $econ);
    }

    public function test_offer_yields_at_least_5_qualified_keywords_with_intent_and_investment(): void
    {
        $r = $this->pipeline($this->healthAsset(), ['payout' => 120, 'cvr' => 0.012, 'refund' => 0.10]);

        $this->assertGreaterThanOrEqual(5, count($r['scored']), 'a dissected offer must yield ≥5 qualified keywords');

        foreach ($r['scored'] as $s) {
            $this->assertArrayHasKey('intent', $s);
            $this->assertArrayHasKey('tier', $s['intent']);
            $this->assertArrayHasKey('investment', $s);
            $this->assertArrayHasKey('verdict', $s['investment']);
            // honest: no live data yet → the verdict is a labeled forecast prior, never fake proof.
            $this->assertSame('forecast_prior', $s['investment']['basis']);
        }
    }

    public function test_hero_is_an_owned_root_and_some_keyword_is_investible(): void
    {
        $r = $this->pipeline($this->healthAsset(), ['payout' => 120, 'cvr' => 0.012, 'refund' => 0.10]);

        // the #1 keyword is a post-VSL-exposure owned root (mechanism/slogan/celebrity), never generic.
        $this->assertContains($r['scored'][0]['family'], ['mechanism_trick', 'slogan', 'celebrity', 'power_phrase', 'objection_verification']);

        // at least one keyword clears the investment bar (investimento or teste) — not all gasto.
        $investible = array_filter($r['scored'], fn ($s) => in_array($s['investment']['verdict'], ['investimento', 'teste'], true));
        $this->assertNotEmpty($investible, 'a strong offer must produce at least one investible keyword');
    }
}
