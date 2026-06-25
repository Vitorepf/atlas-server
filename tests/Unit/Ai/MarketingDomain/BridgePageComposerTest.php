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

    /**
     * The forged-override application (extracted so the in-loop Conversion Critic judges the SAME bytes
     * that ship) swaps a 0-strength generated headline/lead for the forged elite ones.
     */
    public function test_apply_overrides_swaps_weak_headline_and_lead_for_forged_elite(): void
    {
        $asset = new AiMarketingVslAsset([]);
        $bridge = ['headline' => 'algo genérico aqui', 'meta' => [], 'lead_paragraph' => 'curto'];
        $elite = ['Karen Perdeu 34 lbs Com As Gotas — Sem Injeção'];
        $longLead = str_repeat('Se você é uma mulher e já tentou de tudo contra o ozempic, olhe no espelho. ', 6);

        $out = $this->call('applyOverrides', [$bridge, $elite, ['testimonials' => []], [], $longLead, $asset]);

        $this->assertSame($elite[0], $out['headline']);        // "34 lbs" + "sem injeção" beats a 0-strength line
        $this->assertSame($longLead, $out['lead_paragraph']);  // you/ozempic/mirror/length beats "curto"
        $this->assertIsArray($out['proof_block']);             // proof_block always normalized to an array
    }

    public function test_apply_overrides_fills_a_thin_mechanism_tease(): void
    {
        $asset = new AiMarketingVslAsset(['target_geo' => 'US', 'language' => 'en']);
        $bridge = ['headline' => 'x', 'meta' => [], 'lead_paragraph' => 'x', 'mechanism_tease' => 'too short'];

        $out = $this->call('applyOverrides', [$bridge, [], ['testimonials' => []], [], '', $asset]);

        $this->assertGreaterThan(12, str_word_count($out['mechanism_tease'])); // the model's thin tease got the forged safety net
        $this->assertStringContainsStringIgnoringCase('presentation', $out['mechanism_tease']);
    }

    public function test_apply_overrides_keeps_a_rich_non_revealing_tease(): void
    {
        $asset = new AiMarketingVslAsset(['target_geo' => 'US', 'language' => 'en']);
        $rich = 'A small daily routine quietly targets the trigger almost every plan ignores after forty, and the presentation above lays out the simple steps to follow at home.';
        $bridge = ['headline' => 'x', 'meta' => [], 'lead_paragraph' => 'x', 'mechanism_tease' => $rich];

        $out = $this->call('applyOverrides', [$bridge, [], ['testimonials' => []], [], '', $asset]);

        $this->assertSame($rich, $out['mechanism_tease']); // rich, non-revealing tease survives untouched
    }

    /**
     * The Conversion Critic feeds the regeneration loop: a STRUCTURAL flaw is turned into a corrective
     * instruction for the next attempt; a PRIOR (marker-density) reason never is — priors cannot re-roll.
     */
    public function test_correction_note_appends_structural_flaw_only(): void
    {
        $critic = ['verdict' => 'block', 'reasons' => [
            ['floor' => 'watch_through_leak', 'kind' => 'structural', 'detail' => 'vaza o reveal no topo'],
            ['floor' => 'overall_score', 'kind' => 'prior', 'detail' => 'score abaixo do piso'],
        ]];

        $note = $this->call('correctionNote', [null, null, null, null, $critic]);
        $this->assertStringContainsString('FALHA ESTRUTURAL DE CONVERSÃO', $note);
        $this->assertStringContainsString('vaza o reveal no topo', $note);
        $this->assertStringNotContainsString('score abaixo do piso', $note); // a prior never drives a re-roll

        $priorOnly = $this->call('correctionNote', [null, null, null, null, ['reasons' => [['kind' => 'prior', 'detail' => 'x']]]]);
        $this->assertStringNotContainsString('FALHA ESTRUTURAL', $priorOnly);
    }
}
