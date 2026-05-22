<?php

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Atlas Dev Runtime (Meta 7) — garantias do POST /ai/interactions:
 *
 *  - rejeita 422 com code=programming_requires_workspace quando o pedido
 *    chega em mode=programming sem workspace;
 *  - injeta slice atlas_dev_runtime no payload entregue ao gateway;
 *  - normaliza decision_mode atlas_decide ↔ manual_override;
 *  - permite os três flows canon programming.dev/review/repair sem Obra.
 */
class AtlasDevRuntimeInteractionApiTest extends TestCase
{
    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_programming_without_workspace_returns_422(): void
    {
        $this->mock(AiGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enqueueInteraction');
        });

        $response = $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'feature pequena na Blackink',
                'client_id' => (string) Str::uuid(),
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'dev',
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', AtlasDevRuntimeService::REQUIRES_WORKSPACE_CODE);
    }

    public function test_programming_dev_with_workspace_emits_runtime_slice(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('feature pequena na Blackink', \Mockery::on(function (array $options) use ($clientId, &$captured): bool {
                    $captured = $options;

                    return ($options['client_id'] ?? null) === $clientId;
                }))
                ->andReturn($this->stubTrace($clientId, 'feature pequena na Blackink'));
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'feature pequena na Blackink',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'dev',
                    'workspace' => '/repos/blackink',
                    'decision_mode' => 'atlas_decide',
                ],
            ])
            ->assertAccepted();

        $slice = data_get($captured, 'payload.atlas_dev_runtime');

        $this->assertIsArray($slice);
        $this->assertSame('atlas.dev_runtime.v1', $slice['schema_version']);
        $this->assertSame('programming.dev', $slice['flow_id']);
        $this->assertSame('/repos/blackink', $slice['workspace']);
        $this->assertSame('atlas_decide', $slice['decision_mode']);
        $this->assertNull($slice['provider']);
        $this->assertFalse($slice['requires_obra']);
        $this->assertSame(['plan', 'diff_or_reason', 'tests_or_reason', 'risks'], $slice['expected_artifacts']);
        $this->assertTrue($slice['provider_execution_allowed']);

        $assisted = data_get($captured, 'payload.atlas_ai_assisted_execution_quality');
        $this->assertIsArray($assisted);
        $this->assertSame('atlas.ai.assisted_execution_quality.v1', $assisted['schema_version']);
        $this->assertSame('ready_for_assisted_execution', $assisted['status']);
        $this->assertSame('atlas_dev', data_get($assisted, 'route.target'));
        $this->assertSame('programming.dev', data_get($assisted, 'route.flow_id'));
        $this->assertTrue((bool) data_get($assisted, 'dev_runtime_preview.provider_safe'));
    }

    public function test_programming_debug_routes_to_repair_flow(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $_input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->stubTrace($clientId, 'debug bug');
                });
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'debug bug',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'debug',
                    'workspace' => '/repos/atlas',
                ],
            ])
            ->assertAccepted();

        $this->assertSame('programming.repair', data_get($captured, 'payload.atlas_dev_runtime.flow_id'));
        $this->assertSame('programming.repair', data_get($captured, 'payload.atlas_ai_assisted_execution_quality.route.flow_id'));
    }

    public function test_manual_provider_yields_manual_override_in_runtime_slice(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $_input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->stubTrace($clientId, 'review');
                });
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'review',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'provider' => 'codex_cli',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'review',
                    'workspace' => '/repos/atlas',
                    'decision_mode' => 'manual_override',
                    'operator_requested_provider' => 'codex_cli',
                ],
            ])
            ->assertAccepted();

        $this->assertSame('manual_override', data_get($captured, 'payload.atlas_dev_runtime.decision_mode'));
        $this->assertSame('codex_cli', data_get($captured, 'payload.atlas_dev_runtime.provider'));
        $this->assertSame('programming.review', data_get($captured, 'payload.atlas_dev_runtime.flow_id'));
    }

    public function test_atlas_code_surface_skips_dev_runtime_to_let_forge_handle(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $_input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->stubTrace($clientId, 'forge run');
                });
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'forge run',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_code',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'dev',
                    'obra_id' => (string) Str::uuid(),
                    'workspace' => '/repos/atlas',
                ],
            ])
            ->assertAccepted();

        $this->assertNull(data_get($captured, 'payload.atlas_dev_runtime'));
        $this->assertTrue((bool) data_get($captured, 'payload.requires_obra'));
        $this->assertSame('atlas_forge', data_get($captured, 'payload.atlas_ai_assisted_execution_quality.route.target'));
        $this->assertNull(data_get($captured, 'payload.atlas_ai_assisted_execution_quality.dev_runtime_preview'));
    }

    public function test_explain_request_emits_specialist_flow_runtime_slice(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $_input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->stubTrace($clientId, 'explique o router');
                });
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'explique o router do Atlas AI',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                ],
            ])
            ->assertAccepted();

        $slice = data_get($captured, 'payload.specialist_flow_runtime');

        $this->assertIsArray($slice);
        $this->assertSame('atlas.ai.specialist_flow_runtime.v1', $slice['schema_version']);
        $this->assertSame('atlas_explain', $slice['flow_id']);
        $this->assertSame('read_only_explanation', $slice['execution_mode']);
        $this->assertSame('not_delegated', data_get($slice, 'delegation.status'));
        $this->assertSame('atlas.ai.specialist_flow_receipt.v1', data_get($slice, 'receipt.schema_version'));
        $this->assertSame('atlas.ai.specialist_flow_execution.v1', data_get($captured, 'payload.specialist_flow_execution.schema_version'));
        $this->assertSame('atlas_explain_read_only_handler', data_get($captured, 'payload.specialist_flow_execution.handler_id'));
        $this->assertSame(data_get($slice, 'receipt.receipt_id'), data_get($captured, 'payload.specialist_flow_execution.runtime_receipt_id'));
        $this->assertNull(data_get($captured, 'payload.atlas_dev_runtime'));
        $this->assertNull(data_get($captured, 'payload.atlas_ai_assisted_execution_quality'));
    }

    private function stubTrace(string $clientId, string $input): AiTrace
    {
        return tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'atlas_dev_runtime_'.$clientId,
            'status' => 'queued',
            'operator_input' => $input,
            'agent_slug' => 'atlas_dev',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => ['client_id' => $clientId],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
