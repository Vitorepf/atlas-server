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

    public function test_programming_dev_with_uncertified_workspace_is_blocked_by_context_gate(): void
    {
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enqueueInteraction');
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
            ->assertStatus(422)
            ->assertJsonPath('code', 'dev_context_not_provider_safe');
    }

    public function test_programming_debug_with_uncertified_workspace_is_blocked_by_context_gate(): void
    {
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enqueueInteraction');
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
            ->assertStatus(422)
            ->assertJsonPath('code', 'assisted_execution_needs_context')
            ->assertJsonPath('blockers.0.id', 'aedpds_gate_blocked');
    }

    public function test_manual_provider_with_uncertified_workspace_is_blocked_by_context_gate(): void
    {
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('enqueueInteraction');
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
            ->assertStatus(422)
            ->assertJsonPath('code', 'assisted_execution_needs_context')
            ->assertJsonPath('blockers.0.id', 'aedpds_gate_blocked');
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
        $this->assertSame('atlas_forge', data_get($captured, 'payload.atlas_product_delivery_runtime.route'));
        $this->assertSame('forge_obra', data_get($captured, 'payload.atlas_product_delivery_runtime.delivery_plan.execution_unit'));
        $this->assertNull(data_get($captured, 'payload.atlas_ai_assisted_execution_quality.dev_runtime_preview'));
    }

    public function test_product_request_without_programming_mode_still_gets_delivery_runtime(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $_input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->stubTrace($clientId, 'cria um ecommerce');
                });
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'cria um ecommerce completo com pagamentos e webhooks',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                    'workspace' => '/repos/shop',
                ],
            ])
            ->assertAccepted();

        $delivery = data_get($captured, 'payload.atlas_product_delivery_runtime');
        $this->assertIsArray($delivery);
        $this->assertSame('atlas.autonomous_product_delivery_runtime.v1', $delivery['schema_version']);
        $this->assertSame('atlas_forge', $delivery['route']);
        $this->assertSame('forge_obra', data_get($delivery, 'delivery_plan.execution_unit'));
        $this->assertTrue((bool) data_get($delivery, 'proof_requirements.apfpr_required'));
        $this->assertNull(data_get($captured, 'payload.atlas_dev_runtime'));
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

    public function test_awis_runtime_context_survives_interaction_entrypoint_until_gateway(): void
    {
        $clientId = (string) Str::uuid();
        $captured = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId, &$captured): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andReturnUsing(function (string $_input, array $options) use ($clientId, &$captured): AiTrace {
                    $captured = $options;

                    return $this->stubTrace($clientId, 'continue o AWIS sem nascer frio');
                });
        });

        $this
            ->withHeaders($this->headers)
            ->postJson('/ai/interactions', [
                'input_text' => 'continue o AWIS sem nascer frio',
                'client_id' => $clientId,
                'new_thread' => true,
                'source_type' => 'app',
                'payload' => [
                    'surface_id' => 'atlas_desktop_ai',
                    'awis_runtime_context' => [
                        'schema_version' => 'atlas.awis.runtime_context_hint.v1',
                        'workspace' => [
                            'key' => 'atlas',
                            'name' => 'Atlas',
                            'root_path_known' => true,
                        ],
                        'never_start_cold' => true,
                        'load_first' => [
                            'Space pack:AWIS cérebro vivo',
                            'session-gold:usar memória validada antes de responder',
                        ],
                        'use_as_summary' => [
                            'Space organiza; comparação abre sessões lado a lado.',
                        ],
                        'validate_with' => [
                            'npm run atlas-ai:test',
                        ],
                        'working_set' => [
                            'files' => [
                                'apps/desktop/src/surfaces/atlas-ai/contract.ts',
                            ],
                            'docs' => [
                                'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                            ],
                            'commands' => [
                                'npx tsc -b',
                            ],
                        ],
                        'evidence_gate' => [
                            'verify_before_trust' => [
                                'validar antes de promover memória',
                            ],
                            'human_boundary' => [
                                'decisão de produto fica com operador',
                            ],
                        ],
                        'space_context' => [
                            'active_spaces' => ['AWIS cérebro vivo'],
                            'strongest_spaces' => ['AWIS cérebro vivo'],
                            'load_first' => ['Space pack:AWIS cérebro vivo'],
                            'carry_forward' => ['contexto forte do Space'],
                            'validate_before_use' => ['revalidar Space pack'],
                            'artifact_refs' => ['artifact-awis-123'],
                        ],
                        'artifact_context' => [
                            'replay_ready' => true,
                            'latest_artifact_hash' => 'artifact-awis-123',
                            'load_order' => ['artifact:startup snapshot'],
                            'validate_with' => ['artifact:revalidar snapshot'],
                            'reusable_patterns' => ['não nascer frio'],
                            'strongest_spaces' => ['AWIS cérebro vivo'],
                        ],
                        'next_session' => [
                            'first_load' => [
                                'carregar Space pack ativo',
                            ],
                            'validate_with' => [
                                'comparar com Evidence Ledger',
                            ],
                        ],
                        'continue_learning' => [
                            'record_outcome' => true,
                            'update_memory' => true,
                            'update_space_pack' => true,
                            'preserve_artifact_after_success' => true,
                        ],
                    ],
                ],
            ])
            ->assertAccepted();

        $context = data_get($captured, 'payload.awis_runtime_context');
        $this->assertIsArray($context);
        $this->assertSame('atlas.awis.runtime_context_hint.v1', $context['schema_version']);
        $this->assertSame('Atlas', data_get($context, 'workspace.name'));
        $this->assertTrue((bool) data_get($context, 'never_start_cold'));
        $this->assertContains('Space pack:AWIS cérebro vivo', data_get($context, 'load_first'));
        $this->assertContains('npm run atlas-ai:test', data_get($context, 'validate_with'));
        $this->assertContains('AWIS cérebro vivo', data_get($context, 'space_context.active_spaces'));
        $this->assertContains('Space pack:AWIS cérebro vivo', data_get($context, 'space_context.load_first'));
        $this->assertSame('artifact-awis-123', data_get($context, 'artifact_context.latest_artifact_hash'));
        $this->assertContains('não nascer frio', data_get($context, 'artifact_context.reusable_patterns'));
        $this->assertTrue((bool) data_get($context, 'continue_learning.record_outcome'));
        $this->assertTrue((bool) data_get($context, 'continue_learning.update_space_pack'));
        $this->assertSame('atlas.ai.specialist_flow_runtime.v1', data_get($captured, 'payload.specialist_flow_runtime.schema_version'));
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
