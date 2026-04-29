<?php

namespace Tests\Unit;

use App\Services\Ai\AiIntentRouter;
use Tests\TestCase;

class AiIntentRouterTest extends TestCase
{
    public function test_explicit_agent_overrides_keyword_routing(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Analise meu HRV e sono', 'financas');

        $this->assertSame('financas', $route['agent']);
        $this->assertSame('operator_selected', $route['intent']);
    }

    public function test_routes_health_context_to_health_agent(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Meu sono foi ruim e o HRV caiu depois do treino');

        $this->assertSame('saude', $route['agent']);
        $this->assertSame('keyword:sono', $route['intent']);
    }

    public function test_routes_finance_context_to_finance_agent(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Vale comprar esse ativo ou preservar caixa?');

        $this->assertSame('financas', $route['agent']);
        $this->assertSame('keyword:comprar', $route['intent']);
    }

    public function test_falls_back_to_default_orchestrator(): void
    {
        config()->set('atlas.ai.default_agent', 'orquestrador');
        $router = new AiIntentRouter;

        $route = $router->route('Me ajude a pensar sobre essa decisao.');

        $this->assertSame('orquestrador', $route['agent']);
        $this->assertSame('general', $route['intent']);
    }
}
