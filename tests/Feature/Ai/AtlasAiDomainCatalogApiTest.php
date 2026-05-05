<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiDomainCatalogApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_domain_catalog_api_exposes_same_contract_for_app_and_automation(): void
    {
        $response = $this->getJson('/ai/domains?flow=programming.repair', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('filters.flow', 'programming.repair')
            ->assertJsonPath('summary.domains', 1)
            ->assertJsonPath('summary.flows', 1)
            ->assertJsonPath('domains.0.id', 'programming')
            ->assertJsonPath('domains.0.onboarding.status', 'ready')
            ->assertJsonPath('domains.0.onboarding.completed_count', 9)
            ->assertJsonPath('flows.0.id', 'programming.repair')
            ->assertJsonPath('flows.0.orchestrator_maturity', 'implemented')
            ->assertJsonPath('flows.0.executor_preference', 'dev_repair_executor');

        $this->assertTrue($response->json('validation.valid'));
        $this->assertContains('maturity_gate', $response->json('domains.0.onboarding.completed_phases'));
    }

    public function test_domain_catalog_api_rejects_unknown_maturity_filter(): void
    {
        $this->getJson('/ai/domains?maturity=experimental', $this->headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['maturity']);
    }

    public function test_domain_catalog_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/domains')
            ->assertUnauthorized();
    }
}
