<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class IntentClassifyTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    /**
     * @return array<int,array{0:string,1:string}>
     */
    public static function provideIntentSamples(): array
    {
        return [
            ['implemente um exporter csv com testes de regressao', RouterRuntimeCanon::INTENT_PROGRAMMING],
            ['crie uma obra no Forge para corrigir um bug com testes', RouterRuntimeCanon::INTENT_PROGRAMMING],
            ['debug esse stack trace de erro 500', RouterRuntimeCanon::INTENT_DEBUG],
            ['code review desse PR de auth', RouterRuntimeCanon::INTENT_REVIEW],
            ['pesquise as fontes mais confiaveis sobre arquitetura agente', RouterRuntimeCanon::INTENT_RESEARCH],
            ['explique como funciona o cache de prompt da Anthropic', RouterRuntimeCanon::INTENT_EXPLAIN],
            ['plano de execucao em fases para entregar a Meta 7', RouterRuntimeCanon::INTENT_PLAN],
            ['avalie minha carteira de investimentos e simule rebalanceamento', RouterRuntimeCanon::INTENT_FINANCE],
            ['rascunhe uma campanha de copy para growth marketing', RouterRuntimeCanon::INTENT_MARKETING],
            ['monte uma estrategia de venture studio para 2026', RouterRuntimeCanon::INTENT_STRATEGY],
            ['pentest autorizado, busque vulnerabilidade no portal', RouterRuntimeCanon::INTENT_CYBER],
            ['quero uma rotina de estudo deliberado para react native', RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT],
            ['automatize o scraping daquele site e gere relatorio', RouterRuntimeCanon::INTENT_AUTOMATION],
        ];
    }

    #[DataProvider('provideIntentSamples')]
    public function test_intent_kernel_classifies_canonical_intents(string $input, string $expected): void
    {
        $intent = app(IntentKernelService::class)->classify($input);
        $this->assertSame(
            $expected,
            $intent->intent_type,
            "expected intent_type [{$expected}] for input [{$input}], got [{$intent->intent_type}]",
        );
        $this->assertNotEmpty($intent->signals);
        $this->assertGreaterThan(0.0, (float) $intent->confidence);
    }

    public function test_ambiguous_prompt_returns_unknown_and_high_ambiguity(): void
    {
        $intent = app(IntentKernelService::class)->classify('xyz');
        $this->assertSame(RouterRuntimeCanon::INTENT_UNKNOWN, $intent->intent_type);
        $this->assertGreaterThanOrEqual(0.5, (float) $intent->ambiguity_score);
    }
}
