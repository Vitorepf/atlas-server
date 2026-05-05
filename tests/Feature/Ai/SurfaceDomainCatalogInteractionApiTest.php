<?php

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class SurfaceDomainCatalogInteractionApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_interaction_api_enriches_surface_payload_with_canonical_domain_flow_selection(): void
    {
        $clientId = (string) Str::uuid();
        $capturedOptions = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$capturedOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Corrija o bug do fluxo mobile', \Mockery::on(function (array $options) use ($clientId, &$capturedOptions): bool {
                    $capturedOptions = $options;

                    return ($options['client_id'] ?? null) === $clientId;
                }))
                ->andReturn($this->trace($clientId, 'Corrija o bug do fluxo mobile'));
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'Corrija o bug do fluxo mobile',
                'client_id' => $clientId,
                'new_thread' => true,
                'agent_slug' => 'desenvolvedor',
                'provider' => 'codex_cli',
                'source_type' => 'app',
                'payload' => [
                    'app_surface' => 'atlas_ai_sheet',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'debug',
                    'routing_domain' => 'blackink',
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('trace.status', 'queued');

        $this->assertSame('programming', data_get($capturedOptions, 'payload.domain_id'));
        $this->assertSame('programming.repair', data_get($capturedOptions, 'payload.flow_id'));
        $this->assertSame('atlas_app', data_get($capturedOptions, 'payload.surface_id'));
        $this->assertSame('blackink', data_get($capturedOptions, 'payload.product_domain'));
        $this->assertSame('ok', data_get($capturedOptions, 'payload.domain_catalog_selection.status'));
        $this->assertSame('ready', data_get($capturedOptions, 'payload.domain_catalog_selection.domain.onboarding.status'));
        $this->assertSame('dev_repair_executor', data_get($capturedOptions, 'payload.domain_catalog_selection.flow.executor_preference'));
        $this->assertTrue(data_get($capturedOptions, 'payload.domain_catalog_selection.safety.destructive_requires_approval'));
    }

    public function test_interaction_api_records_unresolved_explicit_flow_without_falling_back(): void
    {
        $clientId = (string) Str::uuid();
        $capturedOptions = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$capturedOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Use um flow inexistente para auditoria', \Mockery::on(function (array $options) use ($clientId, &$capturedOptions): bool {
                    $capturedOptions = $options;

                    return ($options['client_id'] ?? null) === $clientId;
                }))
                ->andReturn($this->trace($clientId, 'Use um flow inexistente para auditoria'));
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'Use um flow inexistente para auditoria',
                'client_id' => $clientId,
                'new_thread' => true,
                'agent_slug' => 'orquestrador',
                'provider' => 'claude_cli',
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_app',
                    'flow_id' => 'programming.unknown',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'dev',
                ],
            ])
            ->assertAccepted();

        $this->assertSame('programming.unknown', data_get($capturedOptions, 'payload.flow_id'));
        $this->assertNull(data_get($capturedOptions, 'payload.domain_id'));
        $this->assertSame('unresolved', data_get($capturedOptions, 'payload.domain_catalog_selection.status'));
        $this->assertSame('programming.unknown', data_get($capturedOptions, 'payload.domain_catalog_selection.requested.flow_id'));
        $this->assertSame('explicit_flow', data_get($capturedOptions, 'payload.domain_catalog_selection.selection_source'));
    }

    private function trace(string $clientId, string $input): AiTrace
    {
        return tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'surface_domain_catalog_'.$clientId,
            'status' => 'queued',
            'operator_input' => $input,
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['client_id' => $clientId],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
