<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiRouterRuntimeReadinessApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_router_runtime_enterprise_readiness_gate(): void
    {
        $response = $this->getJson('/ai/router-runtime/readiness', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.router_runtime_readiness.v1')
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('summary.failed', 0)
            ->assertJsonPath('surfaces.readiness', '/ai/router-runtime/readiness')
            ->assertJsonPath('surfaces.flow_status', '/ai/interactions/{trace}/flow-status')
            ->assertJsonPath('writes', false);

        $checks = $response->json('checks');
        $this->assertIsArray($checks);
        $this->assertContains('router.flows_declared', array_column($checks, 'id'));
        $this->assertContains('router.behavior_smoke', array_column($checks, 'id'));
        $this->assertContains('specialist.runtime_contract', array_column($checks, 'id'));
        $this->assertContains('specialist.execution_packet', array_column($checks, 'id'));
        $this->assertContains('persistence.artifacts', array_column($checks, 'id'));
        $this->assertContains('api.flow_status_read_model', array_column($checks, 'id'));
        $this->assertContains('api.router_runtime_bootstrap', array_column($checks, 'id'));
        $this->assertContains('telemetry.specialist_flow_score_components', array_column($checks, 'id'));
        $this->assertContains('prompt.specialist_flow_projection', array_column($checks, 'id'));
        $this->assertContains('docs.canonical_router_runtime_upgrade', array_column($checks, 'id'));
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/router-runtime/readiness')
            ->assertUnauthorized();
    }
}
