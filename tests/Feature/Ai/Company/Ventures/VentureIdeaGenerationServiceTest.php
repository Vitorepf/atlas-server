<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Models\AiVentureIdea;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasStructuredOutputValidator;
use App\Services\Ai\Company\Ventures\VentureIdeaGenerationService;
use App\Services\Ai\Company\Ventures\VentureIdeationService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureIdeaGenerationServiceTest extends TestCase
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

    /**
     * Zero-spend seam override: canned provider output (or a forced failure).
     */
    private function service(?string $cannedOutput, bool $throw = false): VentureIdeaGenerationService
    {
        return new class(
            app(AiProviderManager::class),
            app(AtlasStructuredOutputValidator::class),
            app(VentureIdeationService::class),
            $cannedOutput,
            $throw,
        ) extends VentureIdeaGenerationService
        {
            public function __construct(
                AiProviderManager $providers,
                AtlasStructuredOutputValidator $validator,
                VentureIdeationService $ideation,
                private readonly ?string $canned,
                private readonly bool $throwOnInvoke,
            ) {
                parent::__construct($providers, $validator, $ideation);
            }

            protected function invokeProvider(?string $providerKey, string $prompt): string
            {
                if ($this->throwOnInvoke) {
                    throw new \RuntimeException('provider_returned_not_ok:test');
                }

                return (string) $this->canned;
            }
        };
    }

    public function test_generates_ideas_with_cite_or_omit_market_size(): void
    {
        $canned = json_encode(['ideas' => [
            [
                'title' => 'Compliance Copilot Local',
                'problem' => 'Empresas reguladas auditam controles manualmente',
                'icp' => 'Compliance officers de fintechs',
                'pain' => 'Multas por controle vencido',
                'urgency' => 'high',
                'market_size_usd' => 2_000_000_000,
                'market_size_assumption' => '20k fintechs alvo * 100k USD/ano',
                'pain_severity' => 4,
                'founder_fit' => 5,
                'sovereignty_fit' => 5,
                'rationale' => 'Regulação cresce e auditoria local preserva dados.',
            ],
            [
                'title' => 'Mercado sem premissa',
                'problem' => 'Problema real',
                'icp' => 'ICP real',
                'pain' => 'Dor real',
                'urgency' => 'medium',
                'market_size_usd' => 9_000_000_000,
                'market_size_assumption' => '',
                'pain_severity' => 9,
            ],
        ]]);

        $run = $this->service($canned)->generate('negócios B2B locais para operador solo');

        $this->assertSame('ok', $run['generation_status']);
        $this->assertSame(2, $run['created']);

        $first = AiVentureIdea::query()->where('idea_id', 'gen-compliance-copilot-local')->firstOrFail();
        $this->assertSame('generated', $first->source);
        $this->assertSame('proposed', $first->status);
        $this->assertEqualsWithDelta(2_000_000_000.0, (float) $first->market_size_usd, 0.01);
        $this->assertSame('20k fintechs alvo * 100k USD/ano', $first->generation_meta['market_size_assumption']);
        $this->assertGreaterThan(0, $first->score);

        // Cite-or-omit: market size without an assumption is dropped; clamp 9 -> 5.
        $second = AiVentureIdea::query()->where('idea_id', 'gen-mercado-sem-premissa')->firstOrFail();
        $this->assertNull($second->market_size_usd);
        $this->assertSame(5, $second->pain_severity);
        $this->assertSame(0.0, (float) $second->score_breakdown['market']['component']);
    }

    public function test_invalid_output_and_provider_failure_fail_closed(): void
    {
        $run = $this->service('isto não é JSON {')->generate('brief qualquer');
        $this->assertSame('invalid_structured_output', $run['generation_status']);
        $this->assertSame(0, $run['created']);
        $this->assertSame(0, AiVentureIdea::query()->count());

        $run = $this->service(null, throw: true)->generate('brief qualquer');
        $this->assertSame('provider_failed', $run['generation_status']);
        $this->assertSame(0, AiVentureIdea::query()->count());

        // Schema violation (urgency outside the enum) also yields zero.
        $run = $this->service((string) json_encode(['ideas' => [[
            'title' => 'X', 'problem' => 'p', 'icp' => 'i', 'pain' => 'd', 'urgency' => 'amanhã',
        ]]]))->generate('brief');
        $this->assertSame('invalid_structured_output', $run['generation_status']);
        $this->assertSame(0, AiVentureIdea::query()->count());
    }

    public function test_duplicates_and_cap_are_reported_not_silent(): void
    {
        app(VentureIdeationService::class)->register([
            'idea_id' => 'gen-ideia-repetida',
            'title' => 'Ideia repetida',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);

        $ideas = [['title' => 'Ideia repetida', 'problem' => 'p', 'icp' => 'i', 'pain' => 'd', 'urgency' => 'low']];
        for ($i = 1; $i <= 3; $i++) {
            $ideas[] = ['title' => "Ideia nova {$i}", 'problem' => 'p', 'icp' => 'i', 'pain' => 'd', 'urgency' => 'low'];
        }

        $run = $this->service((string) json_encode(['ideas' => $ideas]))->generate('brief', 2);

        $this->assertSame(2, $run['created']);
        $reasons = array_column($run['dropped'], 'reason');
        $this->assertContains('duplicate_existing', $reasons);
        $this->assertContains('max_ideas_cap', $reasons);
    }
}
