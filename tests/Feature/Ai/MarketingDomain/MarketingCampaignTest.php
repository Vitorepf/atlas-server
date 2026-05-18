<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingCampaignTest extends TestCase
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

    public function test_campaign_plan_requires_channels_and_kpis(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(CampaignPlanService::class)->plan($run, [
            'name' => 'C', 'objective' => 'O', 'channels' => [], 'kpis' => [['name' => 'x']], 'budget_proposed' => 100,
        ]);
    }

    public function test_campaign_plan_marks_no_auto_publish_no_auto_spend(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $campaign = app(CampaignPlanService::class)->plan($run, [
            'name' => 'C', 'objective' => 'O',
            'channels' => ['email'], 'kpis' => [['name' => 'x', 'target' => 1]],
            'budget_proposed' => 5000.0,
        ]);
        $this->assertSame(MarketingDomainCanon::ARTIFACT_CAMPAIGN, $campaign->artifact_type);
        $this->assertFalse($campaign->payload['side_effect_policy']['auto_publish']);
        $this->assertFalse($campaign->payload['side_effect_policy']['auto_spend']);
        $this->assertTrue($campaign->payload['side_effect_policy']['requires_approval']);
    }
}
