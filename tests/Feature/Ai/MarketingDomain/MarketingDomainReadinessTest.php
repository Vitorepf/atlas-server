<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\MarketingReadinessService;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingDomainReadinessTest extends TestCase
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

    public function test_readiness_ok_with_required_checks(): void
    {
        $report = app(MarketingReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'readiness ok=false: '.json_encode($report['summary']));
        $this->assertSame('atlas.ai.marketing_domain.readiness.v1', $report['schema']);

        $names = collect($report['checks'])->pluck('name')->all();
        foreach ([
            'table:ai_marketing_runs',
            'table:ai_marketing_artifacts',
            'table:ai_marketing_experiments',
            'table:ai_marketing_approval_gates',
            'service:MarketingRuntimeService',
            'service:ICPPositioningService',
            'service:CampaignPlanService',
            'service:CopyBriefService',
            'service:CreativeBriefService',
            'service:FunnelPlanService',
            'service:MarketingAnalyticsPlanService',
            'service:GrowthExperimentPlanService',
            'service:MarketingApprovalGateService',
            'service:MarketingControlPlaneProjection',
            'canon:marketing_enums',
            'bridge:policy_approval_request_tolerant',
        ] as $expected) {
            $this->assertContains($expected, $names, "missing readiness check [{$expected}]");
        }
    }

    public function test_readiness_fails_when_table_missing(): void
    {
        $this->dropMarketingDomainTables();
        $report = app(MarketingReadinessService::class)->report();
        $this->assertFalse($report['ok']);
    }
}
