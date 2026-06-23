<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\BridgePageComposerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Locks the deterministic intelligence of the composer that does NOT need the provider: the language
 * is the MARKET language (a US/English VSL gets an English bridge, not Portuguese), the niche router
 * maps the offer to the niche whose patterns sold, keyword ranking is conversion-first, and normalize
 * forces every CTA to the VSL (one goal) and fills the renderer/guard slots. No LLM, no DB.
 */
class BridgePageComposerTest extends TestCase
{
    private function svc(): BridgePageComposerService
    {
        return (new ReflectionClass(BridgePageComposerService::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, array $args)
    {
        $m = (new ReflectionClass(BridgePageComposerService::class))->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->svc(), $args);
    }

    public function test_market_language_is_english_for_a_us_vsl(): void
    {
        $asset = new AiMarketingVslAsset(['target_geo' => 'US / English', 'language' => 'en']);
        $this->assertStringContainsString('English', $this->call('resolveLanguage', [$asset, null]));

        $br = new AiMarketingVslAsset(['target_geo' => 'BR', 'language' => 'pt']);
        $this->assertStringContainsString('Português', $this->call('resolveLanguage', [$br, null]));

        // explicit override wins
        $this->assertSame('Español', $this->call('resolveLanguage', [$asset, 'Español']));
    }

    public function test_niche_router_maps_weight_loss_signals(): void
    {
        $this->assertSame('weight_loss', $this->call('nicheFromText', ['at-home retatrutide GLP-1 gelatin trick for weight loss']));
        $this->assertSame('diabetes', $this->call('nicheFromText', ['reverter diabetes e a glicose']));
        $this->assertNull($this->call('nicheFromText', ['something unrelated entirely']));
    }

    public function test_normalize_forces_one_goal_to_the_vsl_and_fills_slots(): void
    {
        $asset = new AiMarketingVslAsset(['awareness_level' => 'solution_aware']);
        $raw = [
            'headline' => 'Some headline',
            'hero_cta' => ['label' => 'Buy now', 'target' => 'https://x.hop.clickbank.net'],
            'cta_blocks' => [['label' => 'Order', 'target' => 'checkout'], 'Watch'],
        ];

        $bridge = $this->call('normalize', [$raw, $asset, 'angle X', 'English (US)']);

        $this->assertSame('atlas.vsl.bridge.v1', $bridge['schema_version']);
        $this->assertSame('#vsl', $bridge['hero_cta']['target']);
        foreach ($bridge['cta_blocks'] as $c) {
            $this->assertSame('#vsl', $c['target']); // every goal collapses to "watch the VSL"
        }
        $this->assertIsArray($bridge['body_sections']);
        $this->assertIsArray($bridge['trust_bar']);
        $this->assertSame('solution_aware', $bridge['awareness_target']);
        $this->assertNotEmpty($bridge['meta']['slug']);
    }

    public function test_keyword_coverage_rewards_search_language_in_the_headline(): void
    {
        $kws = ['gelatin trick', 'jello diet', 'pink gelatin', 'dr jennifer ashton gelatin recipe'];

        $strong = $this->call('keywordCoverage', ['The Dr. Jennifer Ashton Gelatin Trick everyone searches for, with pink results', $kws]);
        $weak = $this->call('keywordCoverage', ['A brand new triple-hormone drops protocol for women', $kws]);

        $this->assertGreaterThan($weak['score'], $strong['score']);
        $this->assertContains('gelatin trick', $strong['covered']);
        $this->assertSame(0, $weak['score']); // no search vocabulary echoed → honest zero
    }

    public function test_vsl_keywords_come_from_the_offer_clusters_not_the_niche_pool(): void
    {
        $asset = new \App\Models\AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'keywords' => ['clusters' => [
                ['name' => 'Triple Hormone Drops', 'terms' => ['triple hormone drops', 'retatrutide alternative drops']],
                ['name' => 'Lipo Bliss Brand', 'terms' => ['lipo bliss', '[buy lipo bliss]']],
            ]],
        ]);

        $kw = $this->call('vslKeywords', [$asset]);

        $this->assertContains('triple hormone drops', $kw);
        $this->assertContains('lipo bliss', $kw);
        $this->assertContains('buy lipo bliss', $kw);          // brackets stripped
        $this->assertContains('triple hormone drops protocol', $kw); // mechanism seeded
        // never invents an unrelated theme
        $this->assertStringNotContainsString('gelatin', strtolower(implode(' ', $kw)));
    }

    public function test_keyword_ranking_is_conversion_first(): void
    {
        $pattern = new \App\Models\AiMarketingWinningPattern([
            'converting_keywords' => [
                ['term' => 'low', 'conversions' => 3],
                ['term' => 'high', 'conversions' => 1083],
                ['term' => 'mid', 'conversions' => 50],
            ],
        ]);

        $top = $this->call('topKeywords', [$pattern, 2]);
        $this->assertSame(['high', 'mid'], $top);
    }
}
