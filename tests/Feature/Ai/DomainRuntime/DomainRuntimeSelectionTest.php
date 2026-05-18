<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeSelectionService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeSelectionTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
        app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());
    }

    protected function tearDown(): void
    {
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    public function test_selection_picks_software_for_engineering_objective(): void
    {
        $payload = app(DomainRuntimeSelectionService::class)->select(
            'Implementar feature de exporter CSV no painel admin com tests, code review e deploy',
        );

        $this->assertTrue($payload['ok']);
        $this->assertSame('software', $payload['primary_domain']);
        $this->assertSame('keyword_match', $payload['reason']);
    }

    public function test_selection_picks_research_for_research_objective(): void
    {
        $payload = app(DomainRuntimeSelectionService::class)->select(
            'Produzir research brief sobre fontes primarias e contradiction check de fontes sobre mercado X',
        );

        $this->assertTrue($payload['ok']);
        $this->assertSame('research', $payload['primary_domain']);
    }

    public function test_selection_returns_no_match_when_irrelevant(): void
    {
        $payload = app(DomainRuntimeSelectionService::class)->select('xxx yyy zzz');

        $this->assertTrue($payload['ok']);
        $this->assertNull($payload['primary_domain']);
        $this->assertSame('no_keyword_match', $payload['reason']);
    }

    public function test_selection_respects_hint_domain(): void
    {
        $payload = app(DomainRuntimeSelectionService::class)->select(
            'irrelevant text',
            'finance',
        );

        $this->assertSame('finance', $payload['primary_domain']);
        $this->assertSame('hint_match', $payload['reason']);
    }
}
