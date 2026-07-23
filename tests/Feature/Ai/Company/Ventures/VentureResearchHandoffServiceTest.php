<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Models\AiDomainHandoff;
use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use App\Services\Ai\Company\Ventures\VentureGrowthLadderService;
use App\Services\Ai\Company\Ventures\VentureIdeationService;
use App\Services\Ai\Company\Ventures\VentureRegistryService;
use App\Services\Ai\Company\Ventures\VentureResearchHandoffService;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureResearchHandoffServiceTest extends TestCase
{
    use CreatesDomainRuntimeTables;
    use CreatesStrategyRuntimeTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
        app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());
    }

    protected function tearDown(): void
    {
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    public function test_emits_strategy_to_research_handoff_with_gap_driven_questions(): void
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Plataforma de conformidade',
            'problem' => 'Auditoria manual não escala',
            'icp' => 'Fintechs reguladas série A',
            'pain' => 'Multas regulatórias',
        ]);
        $venture = app(VentureRegistryService::class)->promoteIdea($idea);

        $evaluation = app(VentureGrowthLadderService::class)->evaluate($venture);

        $handoff = app(VentureResearchHandoffService::class)->emitForVenture(
            $venture,
            (array) $evaluation['gaps'],
            ['Qual o custo médio de multa para fintechs no Brasil?'],
        );

        $this->assertSame('strategy', $handoff->source_domain_id);
        $this->assertSame('research', $handoff->target_domain_id);
        $this->assertSame('pending', $handoff->status);
        $this->assertSame(64, strlen($handoff->receipt_hash));
        $this->assertSame(1, AiDomainHandoff::query()->count());

        $context = $handoff->context_pack;
        $this->assertSame($venture->venture_id, $context['venture_id']);
        $this->assertSame('Fintechs reguladas série A', $context['icp']);
        $this->assertNotEmpty($context['gaps']);

        $questions = implode(' | ', $context['research_questions']);
        $this->assertStringContainsString('TAM/SAM/SOM', $questions);
        $this->assertStringContainsString('custo médio de multa', $questions);

        $this->assertTrue($handoff->expected_output['claims_attributed']);
    }
}
