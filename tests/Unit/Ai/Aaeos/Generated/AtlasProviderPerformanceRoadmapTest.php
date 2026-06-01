<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProviderPerformanceRoadmapService;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasProviderPerformanceRoadmapTest extends TestCase
{
    private AtlasProviderPerformanceRoadmapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasProviderPerformanceRoadmapService();
    }

    public function testAutoModesAreOwnedByAtlasDecideAndAreTheDefaultPathWithoutReceipt(): void
    {
        $allowed = $this->service->resolveMode(AtlasProviderPerformanceRoadmapService::MODE_AUTO_BEST_ALLOWED);

        $this->assertSame('atlas_decide', $allowed['owner']);
        $this->assertTrue($allowed['is_default_path']);
        $this->assertFalse($allowed['requires_decision_receipt']);
        $this->assertFalse($allowed['requires_explicit_user_permission']);

        // auto_best_available is the same authority but needs explicit user permission for higher cost/risk.
        $available = $this->service->resolveMode(AtlasProviderPerformanceRoadmapService::MODE_AUTO_BEST_AVAILABLE);
        $this->assertSame('atlas_decide', $available['owner']);
        $this->assertTrue($available['requires_explicit_user_permission']);
    }

    public function testManualOverrideIsAnAuditedNonDefaultPathThatForcesAReceipt(): void
    {
        $manual = $this->service->resolveMode(AtlasProviderPerformanceRoadmapService::MODE_MANUAL_OVERRIDE);

        $this->assertSame('user', $manual['owner']);
        // Doc: "Manual model selection is an audited override, not the default path."
        $this->assertFalse($manual['is_default_path']);
        $this->assertTrue($manual['requires_decision_receipt']);
        $this->assertTrue($manual['records_override_consequences']);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->resolveMode('cheapest_possible');
    }

    public function testAp99EvidenceIsRoutingReadyOnlyWhenAllSevenSignalsArePresentInOrder(): void
    {
        $this->assertCount(7, AtlasProviderPerformanceRoadmapService::AP99_SIGNALS);

        $complete = [
            'task_domain_flow' => 'finance.strategy',
            'provider_model' => 'example-model',
            'latency_and_cost' => ['latency_ms' => 900, 'cost_usd' => 0.02],
            'gate_outcomes' => 'pass',
            'repair_rate' => 0.0,
            'human_acceptance' => 'accepted',
            'final_quality_score' => 0.93,
        ];
        $okResult = $this->service->validateAp99Evidence($complete);
        $this->assertTrue($okResult['complete']);
        $this->assertTrue($okResult['routing_ready']);
        $this->assertSame([], $okResult['missing']);

        // Drop two signals (one empty string, one absent) -> not routing-ready, missing in canonical order.
        $partial = $complete;
        $partial['human_acceptance'] = '';
        unset($partial['repair_rate']);
        $badResult = $this->service->validateAp99Evidence($partial);
        $this->assertFalse($badResult['routing_ready']);
        $this->assertSame(['repair_rate', 'human_acceptance'], $badResult['missing']);
    }

    public function testProviderLaunchIntakePromotesUsefulPatternsButAlwaysKeepsUsageBehindAtlas(): void
    {
        $skill = $this->service->classifyProviderLaunch('skill');
        $this->assertTrue($skill['actionable']);
        $this->assertTrue($skill['promote_to_atlas_contract']);
        $this->assertTrue($skill['keep_behind_atlas']);

        // Irrelevant launches are not promoted, but direct usage STILL stays behind Atlas.
        $irrelevant = $this->service->classifyProviderLaunch('irrelevant');
        $this->assertFalse($irrelevant['actionable']);
        $this->assertFalse($irrelevant['promote_to_atlas_contract']);
        $this->assertTrue($irrelevant['keep_behind_atlas']);
    }

    public function testDynamicComputeMarketRoutesHighRiskToPremiumAndLowRiskToLocalFast(): void
    {
        foreach (['architecture', 'finance', 'strategy', 'final_review'] as $highRisk) {
            $route = $this->service->routeCompute($highRisk);
            $this->assertTrue($route['high_risk'], "{$highRisk} should be high risk");
            $this->assertSame(AtlasProviderPerformanceRoadmapService::TIER_PREMIUM, $route['tier']);
            $this->assertSame('atlas_decide', $route['broker']);
        }

        $lowRisk = $this->service->routeCompute('Formatting');
        $this->assertFalse($lowRisk['high_risk']);
        $this->assertSame(AtlasProviderPerformanceRoadmapService::TIER_LOCAL_FAST, $lowRisk['tier']);
        // case/whitespace normalized
        $this->assertSame('formatting', $lowRisk['work_class']);
    }

    public function testAntiFragilityTestFailsWhenUserMustLeaveAtlasEvenIfClaimedAbsorbed(): void
    {
        $good = $this->service->antiFragilityTest(true, false);
        $this->assertTrue($good['good_for_atlas']);
        $this->assertNull($good['layer_gap']);

        // Claimed "absorbed" but user must leave Atlas -> habit changed -> incomplete layer.
        $gap = $this->service->antiFragilityTest(true, true);
        $this->assertFalse($gap['good_for_atlas']);
        $this->assertSame('surface_or_driver_layer_incomplete', $gap['layer_gap']);
    }
}
