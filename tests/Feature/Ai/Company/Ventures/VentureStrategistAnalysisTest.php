<?php

namespace Tests\Feature\Ai\Company\Ventures;

use App\Models\AiVenture;
use App\Models\AiVentureStrategyReview;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasStructuredOutputValidator;
use App\Services\Ai\Company\Ventures\VentureIdeationService;
use App\Services\Ai\Company\Ventures\VentureRegistryService;
use App\Services\Ai\Company\Ventures\VentureStrategistAnalysisService;
use App\Services\Ai\Company\Ventures\VentureStrategistService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureStrategistAnalysisTest extends TestCase
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
            'title' => 'Empresa parecer',
            'problem' => 'Auditoria manual não escala',
            'icp' => 'Fintechs reguladas',
            'pain' => 'Multas',
        ]);

        return app(VentureRegistryService::class)->promoteIdea($idea);
    }

    /**
     * Bind a zero-spend canned analysis service into the container so the
     * strategist resolved via app() uses it.
     */
    private function bindCannedAnalysis(?string $cannedOutput, bool $throw = false): void
    {
        $canned = new class(
            app(AiProviderManager::class),
            app(AtlasStructuredOutputValidator::class),
            $cannedOutput,
            $throw,
        ) extends VentureStrategistAnalysisService
        {
            public function __construct(
                AiProviderManager $providers,
                AtlasStructuredOutputValidator $validator,
                private readonly ?string $canned,
                private readonly bool $throwOnInvoke,
            ) {
                parent::__construct($providers, $validator);
            }

            protected function invokeProvider(?string $providerKey, string $prompt): string
            {
                if ($this->throwOnInvoke) {
                    throw new \RuntimeException('provider_returned_not_ok:test');
                }

                return (string) $this->canned;
            }
        };

        $this->app->instance(VentureStrategistAnalysisService::class, $canned);
    }

    public function test_grounding_lock_keeps_grounded_moves_and_drops_ungrounded(): void
    {
        $this->bindCannedAnalysis((string) json_encode([
            'headline' => 'Validar a tese antes de buscar receita',
            'strengths' => ['Tese clara para ICP regulado'],
            'risks' => ['Sem oportunidade ligada ainda'],
            'strategic_moves' => [
                ['move' => 'Registrar a oportunidade no radar e ligar à venture', 'rationale' => 'Gate aberto bloqueia S1', 'horizon' => 'now', 'grounded_in' => ['F3', 'F4']],
                ['move' => 'Abrir escritório em Dubai', 'rationale' => 'Expansão', 'horizon' => 'year', 'grounded_in' => ['F99']],
                ['move' => 'Movimento sem grounding nenhum', 'grounded_in' => []],
            ],
            'focus_next_cycle' => ['Fechar gates de S1'],
            'confidence' => 1.7,
        ]));

        $venture = $this->makeVenture();
        $packet = app(VentureStrategistService::class)->review($venture, false, true);

        $analysis = $packet['analysis'];
        $this->assertSame(VentureStrategistAnalysisService::STATUS_OK, $analysis['analysis_status']);
        $this->assertCount(1, $analysis['strategic_moves']);
        $this->assertSame(['F3', 'F4'], $analysis['strategic_moves'][0]['grounded_in']);
        $this->assertCount(2, $analysis['dropped_moves']);
        $this->assertSame('ungrounded', $analysis['dropped_moves'][0]['reason']);
        $this->assertSame(1.0, $analysis['confidence']); // clamped from 1.7
        $this->assertNotEmpty($analysis['facts']);

        // Persisted on the review record.
        $review = AiVentureStrategyReview::query()->firstOrFail();
        $this->assertSame(VentureStrategistAnalysisService::STATUS_OK, $review->analysis['analysis_status']);
        $this->assertCount(1, $review->analysis['strategic_moves']);
    }

    public function test_analysis_failures_degrade_without_blocking_the_review(): void
    {
        // Invalid JSON -> degraded, deterministic review still persists.
        $this->bindCannedAnalysis('not json at all {');
        $venture = $this->makeVenture();
        $packet = app(VentureStrategistService::class)->review($venture, false, true);
        $this->assertSame(VentureStrategistAnalysisService::STATUS_INVALID_OUTPUT, $packet['analysis']['analysis_status']);
        $this->assertSame('S0', $packet['evaluation']['recommended_stage']);
        $this->assertSame(1, AiVentureStrategyReview::query()->count());

        // All moves ungrounded -> explicit degraded status.
        $this->bindCannedAnalysis((string) json_encode([
            'headline' => 'x',
            'strategic_moves' => [['move' => 'm', 'grounded_in' => ['F77']]],
        ]));
        $packet = app(VentureStrategistService::class)->review($venture->refresh(), false, true);
        $this->assertSame(VentureStrategistAnalysisService::STATUS_ALL_MOVES_UNGROUNDED, $packet['analysis']['analysis_status']);

        // Provider failure -> degraded, never throws out of the review.
        $this->bindCannedAnalysis(null, throw: true);
        $packet = app(VentureStrategistService::class)->review($venture->refresh(), false, true);
        $this->assertSame(VentureStrategistAnalysisService::STATUS_PROVIDER_FAILED, $packet['analysis']['analysis_status']);

        // Without --analyze nothing runs and nothing is stored.
        $packet = app(VentureStrategistService::class)->review($venture->refresh(), false, false);
        $this->assertNull($packet['analysis']);
    }

    public function test_review_cycle_reviews_active_ventures_and_skips_recent(): void
    {
        $registry = app(VentureRegistryService::class);
        $ideation = app(VentureIdeationService::class);

        $ventures = [];
        foreach (['alfa', 'beta', 'gama'] as $name) {
            $idea = $ideation->register([
                'title' => "Empresa {$name}",
                'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
            ]);
            $ventures[$name] = $registry->promoteIdea($idea);
        }
        $registry->transition($ventures['gama'], VentureRegistryService::STATUS_PAUSED);

        $strategist = app(VentureStrategistService::class);

        $cycle = $strategist->reviewCycle();
        $this->assertFalse($cycle['analyze']);
        $this->assertSame(2, $cycle['active_ventures']);
        $this->assertCount(2, $cycle['reviewed']);
        $this->assertSame('not_requested', $cycle['reviewed'][0]['analysis_status']);
        $this->assertSame(2, AiVentureStrategyReview::query()->count());

        // Immediate re-run: everything is recent, nothing is re-reviewed.
        $second = $strategist->reviewCycle();
        $this->assertCount(0, $second['reviewed']);
        $this->assertCount(2, $second['skipped']);
        $this->assertSame(2, AiVentureStrategyReview::query()->count());

        // Command surface.
        $exit = $this->artisan('atlas:venture', ['action' => 'review-cycle', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }
}
