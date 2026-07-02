<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiHyperflowCertificationApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_exposes_hyperflow_certification_without_rivals_battery(): void
    {
        $response = $this->getJson('/ai/hyperflow/certification', $this->headers);

        $response->assertJsonPath('schema_version', 'atlas.ai.hyperflow_certification.v1')
            ->assertJsonPath('surfaces.certification', '/ai/hyperflow/certification')
            ->assertJsonPath('surfaces.router_readiness', '/ai/router-runtime/readiness')
            ->assertJsonPath('completion_audit.schema_version', 'atlas.ai.hyperflow_completion_audit.v1')
            ->assertJsonPath('writes', false);

        $this->assertContains($response->json('status'), ['passed', 'blocked']);

        $checkIds = array_column($response->json('checks'), 'id');
        $this->assertContains('router_runtime_readiness', $checkIds);
        $this->assertContains('specialist_flows.deep_contracts', $checkIds);
        $this->assertContains('delegation.dev_forge_boundaries', $checkIds);
        $this->assertContains('audit.receipts_persistence_telemetry', $checkIds);
        $this->assertContains('docs.hyperflow_canonical_contracts', $checkIds);

        // Rivals 1.0 battery is retired: no battery checks, gate or claim policy survive.
        $this->assertNotContains('rivals_battery.claude_code_codex', $checkIds);
        $this->assertNotContains('rivals_battery.external_provider_execution', $checkIds);
        $this->assertArrayNotHasKey('external_evidence_gate', $response->json());
        $this->assertArrayNotHasKey('claim_policy', $response->json());
        $this->assertNotContains('rivals_battery_claude_code_codex', array_column($response->json('completion_audit.requirements'), 'id'));
        $this->assertStringNotContainsString('rivals-battery', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_hyperflow_command_certifies_without_battery_actions(): void
    {
        Artisan::call('atlas:ai:hyperflow', ['action' => 'certify', '--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.ai.hyperflow_command.v1', $payload['schema_version']);
        $this->assertSame('certify', $payload['action']);
        $this->assertSame('atlas.ai.hyperflow_certification.v1', data_get($payload, 'certification.schema_version'));

        $exit = Artisan::call('atlas:ai:hyperflow', ['action' => 'rivals-battery', '--json' => true]);
        $unsupported = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('unsupported_action', $unsupported['error']);
        $this->assertSame(['certify'], $unsupported['supported_actions']);
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/hyperflow/certification')
            ->assertUnauthorized();
    }
}
