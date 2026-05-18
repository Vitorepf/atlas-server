<?php

namespace Tests\Feature\Ai\Strategy;

use App\Services\Ai\Strategy\GTMPlanService;
use App\Services\Ai\Strategy\StrategyDomainException;
use App\Services\Ai\Strategy\UnitEconomicsService;
use App\Services\Ai\Strategy\VentureBlueprintService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainVentureBlueprintTest extends TestCase
{
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    public function test_venture_blueprint_requires_product_gtm_unit_economics_hiring_operations(): void
    {
        /** @var VentureBlueprintService $svc */
        $svc = app(VentureBlueprintService::class);

        $blueprint = $svc->create([
            'opportunity_id' => 'opp-uuid',
            'title' => 'Atlas-native subscription',
            'product' => ['name' => 'atlas-pro', 'features' => ['cli', 'cockpit']],
            'gtm' => [
                'positioning' => 'AI operator runtime',
                'icp' => 'Solo operators',
                'channels' => ['cli'],
                'pricing' => ['model' => 'subscription'],
                'motion' => GTMPlanService::MOTION_PRODUCT_LED,
            ],
            'unit_economics' => ['cac' => 50, 'ltv' => 400],
            'hiring_plan' => ['phase_1' => ['founder']],
            'operations' => ['runbook' => 'atlas:operate'],
        ]);

        $this->assertSame(VentureBlueprintService::STATUS_DRAFT, $blueprint->status);
        $this->assertNotEmpty($blueprint->blueprint_hash);
        $this->assertSame('atlas-pro', $blueprint->product['name']);
    }

    public function test_venture_blueprint_rejects_missing_gtm(): void
    {
        /** @var VentureBlueprintService $svc */
        $svc = app(VentureBlueprintService::class);

        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'opportunity_id' => 'opp-uuid',
            'title' => 'Missing GTM',
            'product' => ['name' => 'x'],
            'gtm' => [],
            'unit_economics' => ['cac' => 10],
            'hiring_plan' => ['phase_1' => ['founder']],
            'operations' => ['runbook' => 'x'],
        ]);
    }

    public function test_gtm_plan_validator_rejects_invalid_motion(): void
    {
        /** @var GTMPlanService $svc */
        $svc = app(GTMPlanService::class);
        $this->expectException(StrategyDomainException::class);
        $svc->validate([
            'positioning' => 'x',
            'icp' => 'y',
            'channels' => ['cli'],
            'pricing' => ['model' => 'subscription'],
            'motion' => 'paid_growth_hacking',
        ]);
    }

    public function test_unit_economics_rejects_negative_cac(): void
    {
        /** @var UnitEconomicsService $svc */
        $svc = app(UnitEconomicsService::class);
        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'unit_id' => 'unit-test',
            'cac' => -10,
            'assumptions' => ['note' => 'invalid'],
        ]);
    }

    public function test_unit_economics_rejects_when_all_metrics_missing(): void
    {
        /** @var UnitEconomicsService $svc */
        $svc = app(UnitEconomicsService::class);
        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'unit_id' => 'unit-test',
            'assumptions' => ['note' => 'no metrics'],
        ]);
    }
}
