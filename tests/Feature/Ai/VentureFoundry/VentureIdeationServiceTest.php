<?php

namespace Tests\Feature\Ai\VentureFoundry;

use App\Models\AiVentureIdea;
use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\VentureFoundry\VentureFoundryException;
use App\Services\Ai\VentureFoundry\VentureIdeationService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureIdeationServiceTest extends TestCase
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

    public function test_register_persists_idea_with_deterministic_score_breakdown(): void
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Plataforma de auditoria local',
            'problem' => 'Times perdem horas auditando manualmente',
            'icp' => 'CTOs de empresas medias',
            'pain' => 'Auditoria manual cara e lenta',
            'urgency' => 'high',
            'market_size_usd' => 1_000_000_000,
            'pain_severity' => 4,
            'founder_fit' => 5,
            'sovereignty_fit' => 5,
        ]);

        $this->assertSame('proposed', $idea->status);
        $this->assertSame(64, strlen($idea->idea_hash));

        // pain 4/5*30=24, urgency high .75*15=11.25, market log10(1e9)/12*25=18.75, founder 15, sovereignty 15
        $this->assertEqualsWithDelta(84.0, $idea->score, 0.01);
        $this->assertEqualsWithDelta(24.0, $idea->score_breakdown['pain']['component'], 0.001);
        $this->assertEqualsWithDelta(11.25, $idea->score_breakdown['urgency']['component'], 0.001);
        $this->assertEqualsWithDelta(18.75, $idea->score_breakdown['market']['component'], 0.001);
    }

    public function test_register_rejects_missing_problem_and_duplicate_idea_id(): void
    {
        $service = app(VentureIdeationService::class);

        try {
            $service->register(['title' => 'Sem problema', 'icp' => 'x', 'pain' => 'y']);
            $this->fail('expected missing problem exception');
        } catch (VentureFoundryException $e) {
            $this->assertStringContainsString('[problem]', $e->getMessage());
        }

        $service->register([
            'title' => 'Ideia unica',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);

        $this->expectException(VentureFoundryException::class);
        $service->register([
            'title' => 'Ideia unica',
            'problem' => 'p2', 'icp' => 'i2', 'pain' => 'd2',
        ]);
    }

    public function test_ideate_from_radar_derives_ideas_only_for_unseen_opportunities(): void
    {
        $radar = app(OpportunityRadarService::class);
        $opportunity = $radar->create([
            'title' => 'Gap de conformidade em fintechs',
            'problem' => 'Fintechs pequenas nao conseguem manter conformidade',
            'icp' => 'Fintechs serie A',
            'pain' => 'Multas e bloqueio regulatorio',
            'urgency' => 'critical',
            'market' => ['tam' => 5_000_000_000],
            'competitors' => [['name' => 'consultorias manuais']],
            'risks' => [['kind' => 'regulatory', 'detail' => 'mudanca de norma']],
        ]);

        $service = app(VentureIdeationService::class);

        $created = $service->ideateFromRadar();
        $this->assertCount(1, $created);
        $this->assertSame('radar', $created[0]->source);
        $this->assertSame($opportunity->id, $created[0]->opportunity_id);
        $this->assertSame(5, $created[0]->pain_severity);
        $this->assertEqualsWithDelta(5_000_000_000.0, (float) $created[0]->market_size_usd, 0.01);

        // Second run is idempotent: no duplicate idea for the same opportunity.
        $this->assertCount(0, $service->ideateFromRadar());
        $this->assertSame(1, AiVentureIdea::query()->count());
    }
}
