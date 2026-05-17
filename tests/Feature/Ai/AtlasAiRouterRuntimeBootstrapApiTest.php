<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiRouterRuntimeBootstrapApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_desktop_router_runtime_bootstrap_contract(): void
    {
        $response = $this->getJson('/ai/router-runtime/bootstrap', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.ai.router_runtime_bootstrap.v1')
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('readiness.status', 'passed')
            ->assertJsonPath('entrypoints.create_interaction.path', '/ai/interactions')
            ->assertJsonPath('entrypoints.flow_status.returns', 'atlas.ai.flow_status.v1')
            ->assertJsonPath('payload_contract.router_slice', 'payload.atlas_ai_router')
            ->assertJsonPath('ux_contract.primary_status_source', '/ai/interactions/{trace}/flow-status')
            ->assertJsonPath('integration_boundaries.merge_dev_and_forge', false)
            ->assertJsonPath('writes', false);

        $flows = $response->json('flows');
        $this->assertIsArray($flows);
        $this->assertContains('atlas_dev', array_column($flows, 'id'));
        $this->assertContains('atlas_research', array_column($flows, 'id'));
        $this->assertContains('atlas_explain', array_column($flows, 'id'));
        $this->assertContains('atlas_debug', array_column($flows, 'id'));
        $this->assertContains('atlas_review', array_column($flows, 'id'));
        $this->assertContains('atlas_plan', array_column($flows, 'id'));
        $this->assertContains('atlas_conversation', array_column($flows, 'id'));
        $this->assertContains('atlas_forge', array_column($flows, 'id'));

        $commands = array_column($response->json('slash_commands'), 'command');
        $this->assertContains('/dev', $commands);
        $this->assertContains('/plan', $commands);
        $this->assertContains('/forge', $commands);
        $this->assertSame('open_forge_surface', $response->json('status_states.forge_required.next_action'));
        $this->assertSame('render_atlas_dev_controls', $response->json('status_states.atlas_dev_runtime.next_action'));
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/router-runtime/bootstrap')
            ->assertUnauthorized();
    }
}
