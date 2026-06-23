<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\BidStrategyDecider;
use App\Services\Ai\MarketingDomain\Campaign\CampaignEconomicsCalculator;
use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\CreativeBriefService;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\GrowthExperimentPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\MarketingAnalyticsPlanService;
use PHPUnit\Framework\TestCase;

/**
 * Proves the 7 previously-empty brief/plan services now carry EXECUTABLE depth: each blueprint()
 * returns the full professional skeleton from the playbook (pure, deterministic), not just payload
 * validation. This is the 28-skill knowledge made runnable.
 */
class MarketingBriefEnrichmentTest extends TestCase
{
    public function test_copy_brief_routes_awareness_and_carries_vsl_anatomy(): void
    {
        $b = (new CopyBriefService)->blueprint('solution_aware');

        $this->assertSame('big_secret', $b['awareness_routing']['lead_type']);
        $this->assertNotEmpty($b['vsl_anatomy']);
        $this->assertArrayHasKey('cialdini_7', $b['persuasion_checklist']);
        $this->assertCount(8, $b['persuasion_checklist']['life_force_8']);
    }

    public function test_creative_brief_carries_youtube_ad_structure(): void
    {
        $b = (new CreativeBriefService)->blueprint();

        $this->assertArrayHasKey('hook', $b['ad_structure']['blocks']);
        $this->assertArrayHasKey('HeyGen', $b['creative_tools']);
    }

    public function test_funnel_brief_carries_advertorial_and_uptime_is_not_a_gate(): void
    {
        $b = (new FunnelPlanService)->blueprint('cold');

        $this->assertSame('listicle advertorial', $b['advertorial']['format']);
        $this->assertArrayHasKey('message_match', $b);
        // compliance is performance knowledge, never a refusal
        $this->assertFalse($b['account_uptime']['is_gate']);
    }

    public function test_icp_brief_scores_value_equation_and_grand_slam(): void
    {
        $b = (new ICPPositioningService)->blueprint('most_aware');

        $this->assertSame('offer', $b['awareness_routing']['lead_type']);
        $this->assertStringContainsString('DENOMINADOR', $b['value_equation']['insight']);
        $this->assertArrayHasKey('stack', $b['grand_slam']);
    }

    public function test_growth_brief_carries_test_math_and_scale_rules(): void
    {
        $b = (new GrowthExperimentPlanService)->blueprint();

        $this->assertStringContainsString('95%', $b['test_math']['confidence']);
        $this->assertStringContainsString('10-20%', $b['scale_rules']['vertical']);
    }

    public function test_analytics_brief_carries_conversion_pipeline_and_anti_proxy_kpi(): void
    {
        $b = (new MarketingAnalyticsPlanService)->blueprint();

        $this->assertArrayHasKey('data_manager_api', $b['conversion_pipeline']);
        $this->assertContains('sale', $b['funnel_events']);
        $this->assertStringContainsString('LUCRO', $b['anti_proxy']);
    }

    public function test_campaign_brief_is_channel_aware_search_vs_youtube(): void
    {
        $search = (new CampaignPlanService)->blueprint('google_search');
        $this->assertArrayHasKey('account_structures', $search);
        $this->assertArrayHasKey('rsa_spec', $search);
        $this->assertArrayHasKey('value_based_bidding', $search['bidding_knowledge']);

        $youtube = (new CampaignPlanService)->blueprint('youtube');
        $this->assertArrayHasKey('demand_gen', $youtube);
        $this->assertCount(4, $youtube['demand_gen']['best_practices']);
    }

    public function test_bid_strategy_now_carries_advanced_levers(): void
    {
        $economics = (new CampaignEconomicsCalculator)->compute(['payout' => 200.0]);
        $plan = (new BidStrategyDecider)->plan($economics);

        $this->assertArrayHasKey('advanced', $plan);
        $this->assertArrayHasKey('value_based_bidding', $plan['advanced']);
        $this->assertArrayHasKey('seasonality_adjustments', $plan['advanced']);
        $this->assertArrayHasKey('data_exclusions', $plan['advanced']);
        // existing phased plan still intact
        $this->assertSame('maximize_conversions', $plan['first_test_strategy']);
        $this->assertCount(3, $plan['phases']);
    }
}
