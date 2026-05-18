<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class DomainRouterRouteTest extends TestCase
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

    public function test_route_persists_decision_with_primary_and_secondary_domains(): void
    {
        $intent = app(IntentKernelService::class)->classify(
            'implemente endpoint de auth com testes e seguranca contra brute force; pesquise fontes',
        );
        $decision = app(DomainRouterService::class)->route($intent);

        $this->assertSame('programming', $decision->primary_domain);
        $this->assertContains('cyber', $decision->secondary_domains, 'should detect security/auth as secondary cyber');
        $this->assertContains('research', $decision->secondary_domains);
        $this->assertNotEmpty($decision->receipt_hash);
        $this->assertSame(64, strlen((string) $decision->receipt_hash));
    }

    public function test_research_intent_uses_deep_routing_mode(): void
    {
        $intent = app(IntentKernelService::class)->classify('pesquise estado da arte sobre agentes autonomos');
        $decision = app(DomainRouterService::class)->route($intent);

        $this->assertSame('research', $decision->primary_domain);
        $this->assertSame(RouterRuntimeCanon::MODE_DEEP, $decision->routing_mode);
    }

    public function test_conversation_intent_uses_lightweight_routing_mode(): void
    {
        $intent = app(IntentKernelService::class)->classify('explique em uma frase o que é o Atlas AI');
        $decision = app(DomainRouterService::class)->route($intent);

        $this->assertSame(RouterRuntimeCanon::MODE_LIGHTWEIGHT, $decision->routing_mode);
    }
}
