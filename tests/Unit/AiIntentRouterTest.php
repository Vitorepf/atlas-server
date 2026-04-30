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

        $route = $router->route('Me ajude a pensar sobre esse assunto.');

        $this->assertSame('orquestrador', $route['agent']);
        $this->assertSame('general', $route['intent']);
    }

    public function test_routes_decision_context_to_decision_advisor(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Preciso tomar uma decisao com tradeoff importante.');

        $this->assertSame('decision-advisor', $route['agent']);
        $this->assertSame('keyword:decisao', $route['intent']);
    }

    public function test_routes_research_context_to_researcher_quick(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Faça uma pesquisa rapida com fontes.');

        $this->assertSame('researcher-quick', $route['agent']);
        $this->assertSame('keyword:pesquisa', $route['intent']);
    }

    public function test_routes_review_context_to_code_reviewer(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Faça review do diff e aponte regressao.');

        $this->assertSame('code-reviewer', $route['agent']);
        $this->assertSame('keyword:review', $route['intent']);
    }

    public function test_routes_clear_output_request_to_clear_communicator(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Explica simples, sem código, porque nao olho mais codigo.');

        $this->assertSame('comunicador-claro', $route['agent']);
        $this->assertSame('keyword:sem código', $route['intent']);
    }

    public function test_routes_provider_handoff_context(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Preciso trocar provider de Claude para Codex sem perder contexto.');

        $this->assertSame('provider-handoff', $route['agent']);
        $this->assertSame('keyword:trocar provider', $route['intent']);
    }

    public function test_routes_dev_quality_gate_context(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Antes de concluir, rode o quality gate da implementacao.');

        $this->assertSame('dev-quality-gate', $route['agent']);
        $this->assertSame('keyword:quality gate', $route['intent']);
    }

    public function test_routes_security_review_before_domain_routes(): void
    {
        $router = new AiIntentRouter;

        $route = $router->route('Faça security review das permissões e tokens.');

        $this->assertSame('security-review', $route['agent']);
        $this->assertSame('keyword:security review', $route['intent']);
    }
}
