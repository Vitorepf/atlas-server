<?php

namespace Tests\Feature\Ai\VentureFoundry;

use App\Models\AiVentureStrategyReview;
use App\Services\Ai\VentureFoundry\VentureGrowthLadderService;
use App\Services\Ai\VentureFoundry\VentureIdeationService;
use App\Services\Ai\VentureFoundry\VentureRegistryService;
use App\Services\Ai\VentureFoundry\VentureStrategistService;
use App\Services\Ai\VentureFoundry\VentureTrajectoryService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureTrajectoryServiceTest extends TestCase
{
    use CreatesStrategyRuntimeTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
    }

    protected function tearDown(): void
    {
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    private function makeVenture(): \App\Models\AiVenture
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Empresa trajetória',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);

        return app(VentureRegistryService::class)->promoteIdea($idea);
    }

    public function test_projection_is_blocked_without_observed_arr(): void
    {
        $venture = $this->makeVenture();
        $projection = app(VentureTrajectoryService::class)->project($venture, []);

        $this->assertSame(VentureTrajectoryService::STATUS_BLOCKED, $projection['status']);
        $this->assertSame([], $projection['scenarios']);
    }

    public function test_projection_math_from_1m_to_100m(): void
    {
        $venture = $this->makeVenture();
        $ladder = app(VentureGrowthLadderService::class);
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 1_000_000]);

        $projection = app(VentureTrajectoryService::class)->project($venture, $ladder->latestMetrics($venture));

        $this->assertSame(VentureTrajectoryService::STATUS_PROJECTED, $projection['status']);
        $this->assertEqualsWithDelta(100.0, $projection['multiple_to_target'], 0.01);

        $byScenario = array_column($projection['scenarios'], 'years_to_target', 'scenario');
        // 100x no cenário base (dobra/ano): log(100)/log(2) = 6.6 anos.
        $this->assertEqualsWithDelta(6.6, $byScenario['base'], 0.05);
        // Conservador (50%/ano): log(100)/log(1.5) = 11.4 anos.
        $this->assertEqualsWithDelta(11.4, $byScenario['conservative'], 0.05);

        $byHorizon = array_column($projection['required_growth'], 'required_cagr', 'horizon_years');
        // 100x em 5 anos exige CAGR de 100^(1/5)-1 = 151.19%.
        $this->assertEqualsWithDelta(1.5119, $byHorizon[5], 0.001);
    }

    public function test_target_reached_when_arr_meets_target(): void
    {
        $venture = $this->makeVenture();
        $ladder = app(VentureGrowthLadderService::class);
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 120_000_000]);

        $projection = app(VentureTrajectoryService::class)->project($venture, $ladder->latestMetrics($venture));

        $this->assertSame(VentureTrajectoryService::STATUS_TARGET_REACHED, $projection['status']);
    }

    public function test_strategist_review_persists_trajectory(): void
    {
        $venture = $this->makeVenture();
        app(VentureGrowthLadderService::class)->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 500_000]);

        $packet = app(VentureStrategistService::class)->review($venture);

        $this->assertSame(VentureTrajectoryService::STATUS_PROJECTED, $packet['trajectory']['status']);

        $review = AiVentureStrategyReview::query()->firstOrFail();
        $this->assertSame(VentureTrajectoryService::STATUS_PROJECTED, $review->trajectory['status']);
        $this->assertNotEmpty($review->trajectory['scenarios']);
    }
}
