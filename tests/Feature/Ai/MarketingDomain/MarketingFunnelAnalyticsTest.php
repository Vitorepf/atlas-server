<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\MarketingAnalyticsPlanService;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingFunnelAnalyticsTest extends TestCase
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

    public function test_funnel_requires_at_least_three_stages(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(FunnelPlanService::class)->plan($run, [
            'stages' => [
                ['name' => 'A', 'metric' => 'm', 'target_conversion' => 1],
                ['name' => 'B', 'metric' => 'm', 'target_conversion' => 0.5],
            ],
        ]);
    }

    public function test_funnel_stage_requires_metric_and_target_conversion(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(FunnelPlanService::class)->plan($run, [
            'stages' => [
                ['name' => 'A', 'metric' => 'm', 'target_conversion' => 1],
                ['name' => 'B'],
                ['name' => 'C', 'metric' => 'm', 'target_conversion' => 0.1],
            ],
        ]);
    }

    public function test_analytics_plan_requires_events_and_kpis(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(MarketingAnalyticsPlanService::class)->plan($run, [
            'events' => [], 'kpis' => ['x'],
        ]);
    }
}
