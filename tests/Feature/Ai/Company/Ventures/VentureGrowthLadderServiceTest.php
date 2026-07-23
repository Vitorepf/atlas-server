<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Models\AiVenture;
use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\Strategy\VentureBlueprintService;
use App\Services\Ai\Company\Ventures\VentureBusinessRuleService;
use App\Services\Ai\Company\Ventures\VentureGrowthLadderService;
use App\Services\Ai\Company\Ventures\VentureIdeationService;
use App\Services\Ai\Company\Ventures\VentureRegistryService;
use App\Services\Ai\Company\Ventures\VentureStrategistService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureGrowthLadderServiceTest extends TestCase
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

    private function makeVenture(): AiVenture
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Empresa escada',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);

        return app(VentureRegistryService::class)->promoteIdea($idea);
    }

    private function linkValidationArtifacts(AiVenture $venture): AiVenture
    {
        $opportunity = app(OpportunityRadarService::class)->create([
            'title' => 'Oportunidade escada',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
            'market' => ['tam' => 1_000_000_000],
            'competitors' => [['name' => 'x']],
            'risks' => [['kind' => 'execution']],
        ]);

        $rules = app(VentureBusinessRuleService::class);
        $rules->declare($venture, ['category' => 'pricing', 'statement' => 'Preço mínimo 100 USD.']);
        $rules->declare($venture, ['category' => 'customer', 'statement' => 'Só vender para o ICP declarado.']);
        $rules->declare($venture, ['category' => 'finance', 'statement' => 'Margem bruta mínima de 60%.']);

        return app(VentureRegistryService::class)->attachArtifacts($venture, [
            'opportunity_id' => $opportunity->id,
        ]);
    }

    public function test_new_venture_is_recommended_s0_with_validation_gaps(): void
    {
        $venture = $this->makeVenture();
        $evaluation = app(VentureGrowthLadderService::class)->evaluate($venture);

        $this->assertSame('S0', $evaluation['recommended_stage']);
        $gates = array_column($evaluation['gaps'], 'gate');
        $this->assertContains('opportunity_linked', $gates);
        $this->assertContains('business_rules_min_active', $gates);
        $this->assertNotEmpty($evaluation['next_actions']);
    }

    public function test_ladder_climbs_stage_by_stage_with_persisted_evidence(): void
    {
        $ladder = app(VentureGrowthLadderService::class);
        $registry = app(VentureRegistryService::class);
        $venture = $this->makeVenture();

        // S1: opportunity + 3 active rules.
        $venture = $this->linkValidationArtifacts($venture);
        $this->assertSame('S1', $ladder->evaluate($venture)['recommended_stage']);

        // S2: blueprint + north star + ARR > 0.
        $blueprint = app(VentureBlueprintService::class)->create([
            'title' => 'Blueprint escada',
            'opportunity_id' => $venture->opportunity_id,
            'product' => ['scope' => 'mvp'],
            'gtm' => ['channel' => 'outbound'],
            'unit_economics' => ['cac' => 500],
            'hiring_plan' => ['founder_only' => true],
            'operations' => ['mode' => 'lean'],
        ]);
        $venture = $registry->attachArtifacts($venture, [
            'venture_blueprint_id' => $blueprint->id,
            'north_star' => ['metric' => 'arr_usd', 'target' => 100_000_000],
        ]);
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 50_000]);
        $this->assertSame('S2', $ladder->evaluate($venture)['recommended_stage']);

        // S3: ARR >= 1M.
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 1_500_000]);
        $this->assertSame('S3', $ladder->evaluate($venture)['recommended_stage']);

        // S4 requires ARR >= 10M AND LTV/CAC >= 3 — without the ratio it stays S3.
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 12_000_000]);
        $this->assertSame('S3', $ladder->evaluate($venture)['recommended_stage']);
        $ladder->recordMetric($venture, ['metric_key' => 'ltv_cac_ratio', 'value' => 3.4]);
        $this->assertSame('S4', $ladder->evaluate($venture)['recommended_stage']);

        // S5: ARR >= 100M.
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 110_000_000]);
        $evaluation = $ladder->evaluate($venture);
        $this->assertSame('S5', $evaluation['recommended_stage']);
        $this->assertSame([], $evaluation['gaps']);
    }

    public function test_chain_break_blocks_stage_even_with_high_arr(): void
    {
        $ladder = app(VentureGrowthLadderService::class);
        $venture = $this->makeVenture();

        // 100M USD ARR observed, but no opportunity/rules/blueprint: the chain
        // breaks at S1 and the recommendation stays S0 — no metric shortcut.
        $ladder->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 100_000_000]);
        $this->assertSame('S0', $ladder->evaluate($venture)['recommended_stage']);
    }

    public function test_strategist_review_records_review_and_applies_stage_only_with_apply(): void
    {
        $venture = $this->linkValidationArtifacts($this->makeVenture());
        $strategist = app(VentureStrategistService::class);

        $packet = $strategist->review($venture, false);
        $this->assertSame('S1', $packet['evaluation']['recommended_stage']);
        $this->assertFalse($packet['stage_applied']);
        $this->assertSame('S0', $packet['venture']->stage);
        $this->assertNotNull($packet['strategy_memo_id']);
        $this->assertNull($packet['strategy_memo_error']);

        $packet = $strategist->review($venture->refresh(), true);
        $this->assertTrue($packet['stage_applied']);
        $this->assertSame('S1', $packet['venture']->stage);
        $this->assertSame('validation', $packet['venture']->stage_key);
    }
}
