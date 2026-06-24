<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ConversionStrategist;
use PHPUnit\Framework\TestCase;

/**
 * ConversionStrategist — the unified brain. Given an asset it must compose awareness, sophistication,
 * mechanism and offer-gaps into ONE coherent plan, consistently with each organ's own rules, cross-niche.
 */
class ConversionStrategistTest extends TestCase
{
    public function test_most_aware_saturated_health_leads_with_deal_and_keeps_the_mechanism(): void
    {
        $asset = new AiMarketingVslAsset([
            'niche' => 'weight loss', 'awareness_level' => 'most_aware', 'sophistication_level' => 5,
            'mechanism_name' => 'at-home 3-hormone reset', 'big_idea' => 'reset the 3 fat hormones',
        ]);
        $plan = (new ConversionStrategist)->plan($asset);

        // Most-aware compares the deal → opens with price anchoring, not scarcity.
        $this->assertSame('price_anchoring_extreme', $plan['aggression_order'][0]);
        // Saturated level 5 keeps the mechanism, wrapped in identity (panel fix).
        $this->assertTrue($plan['mechanism']['lead_with_mechanism']);
        $this->assertSame('The 3-Hormone Reset', $plan['mechanism']['name']);
        $this->assertSame(5, $plan['sophistication']['level']);
        $this->assertNotEmpty($plan['summary']);
    }

    public function test_unaware_opens_with_story_enemy_not_scarcity(): void
    {
        $asset = new AiMarketingVslAsset([
            'niche' => 'blood sugar', 'awareness_level' => 'unaware', 'sophistication_level' => 2,
        ]);
        $plan = (new ConversionStrategist)->plan($asset);
        $this->assertSame('conspiracy_enemy', $plan['aggression_order'][0]);
        $this->assertNotContains('social_proof_pressure', $plan['aggression_order']);
    }

    public function test_surfaces_offer_gaps_from_a_thin_offer(): void
    {
        $asset = new AiMarketingVslAsset([
            'niche' => 'finance', 'awareness_level' => 'solution_aware', 'sophistication_level' => 4,
            'big_idea' => 'imagine your account compounding', // dream only — no proof/time/effort
        ]);
        $plan = (new ConversionStrategist)->plan($asset);
        $keys = array_column($plan['offer_gaps'], 'key');
        $this->assertContains('perceived_likelihood', $keys);
        $this->assertContains('time_delay', $keys);
        // High-leverage gap first.
        $this->assertTrue($plan['offer_gaps'][0]['high_leverage']);
    }

    public function test_thin_asset_reports_missing_ammunition_instead_of_faking(): void
    {
        // Pillar 2 (understanding): a thin asset must NAME the missing fields, not ship generic filler.
        $thin = (new ConversionStrategist)->plan(new AiMarketingVslAsset([
            'niche' => 'finance', 'awareness_level' => 'problem_aware', 'sophistication_level' => 4,
        ]));
        $this->assertNotEmpty($thin['needs']);
        $needsBlob = implode(' ', $thin['needs']);
        $this->assertStringContainsString('prova concreta', $needsBlob);
        $this->assertStringContainsString('mechanism_name', $needsBlob);
        $this->assertStringContainsString('FALTA', $thin['summary']);
    }

    public function test_rich_asset_has_no_missing_ammunition(): void
    {
        $rich = (new ConversionStrategist)->plan(new AiMarketingVslAsset([
            'niche' => 'weight loss', 'awareness_level' => 'problem_aware', 'sophistication_level' => 4,
            'core_promise' => 'lose the weight', 'mechanism_name' => 'The 3-Hormone Reset',
            'claims' => ['Dr Lee tracked 312 women; 9 of 10 dropped a size in 6 weeks'],
            'metrics' => ['result_claims' => ['results in 21 days']],
            'offer' => ['ease' => ['no gym', 'just 10 minutes a day']],
        ]));
        $this->assertSame([], $rich['needs']);
    }

    public function test_plan_is_a_complete_author_brief_with_lead_and_objection_loop(): void
    {
        // Pillar 2: the plan must hand the author a COMPLETE package — the lead to open with and the
        // Belfort loop for the top objection — not just the strategy.
        $plan = (new ConversionStrategist)->plan(new AiMarketingVslAsset([
            'niche' => 'finance', 'awareness_level' => 'problem_aware', 'sophistication_level' => 4,
            'core_promise' => 'grow your money', 'mechanism_name' => 'The Allocation Rule',
        ]));
        $this->assertArrayHasKey('lead', $plan);
        $this->assertNotEmpty($plan['lead']['best']);
        $this->assertArrayHasKey('objection_loop', $plan);
        $this->assertArrayHasKey('reframe', $plan['objection_loop']['steps']);
    }

    public function test_plan_surfaces_proof_concreteness_the_number_one_lever(): void
    {
        $strategist = new ConversionStrategist;

        $rich = $strategist->plan(new AiMarketingVslAsset([
            'niche' => 'weight loss', 'awareness_level' => 'solution_aware', 'sophistication_level' => 4,
            'big_idea' => 'lose the weight', 'claims' => ['Dr. Lee tracked 312 women; 80% kept it off'],
        ]));
        $this->assertTrue($rich['proof']['has_concrete']);
        $this->assertStringNotContainsString('PROVA fraca', $rich['summary']);

        $thin = $strategist->plan(new AiMarketingVslAsset([
            'niche' => 'weight loss', 'awareness_level' => 'solution_aware', 'sophistication_level' => 4,
            'big_idea' => 'lose the weight',
        ]));
        $this->assertFalse($thin['proof']['has_concrete']);
        $this->assertStringContainsString('PROVA fraca', $thin['summary']);
    }

    public function test_full_copy_overrides_asset_text_for_offer_audit(): void
    {
        $asset = new AiMarketingVslAsset(['niche' => 'relationship', 'awareness_level' => 'product_aware', 'sophistication_level' => 3]);
        $copy = 'Imagine reconnecting. Proven by a 60-day money-back guarantee, results in 14 days, with no texting and just 10 minutes a day.';
        $plan = (new ConversionStrategist)->plan($asset, $copy);
        $this->assertSame([], $plan['offer_gaps']); // a complete offer has no gaps
    }
}
