<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiRouterDecision;
use App\Services\Ai\Router\AtlasAiRouterService;
use Tests\TestCase;

/**
 * Lanes à prova de erro do router (03/07):
 *  - Lei da escolha explícita: picker explicit_flow/explicit_domain É a rota.
 *  - Árbitro do kernel canônico: cauda ambígua consulta o IntentKernelService
 *    (13 tipos) em vez de cair em conversa; conversa só quando classificada.
 */
class AtlasAiRouterErrorProofLanesTest extends TestCase
{
    public function test_explicit_domain_catalog_selection_is_law(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'me ajuda aqui',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'flow_id' => AtlasAiRouterDecision::FLOW_FINANCE,
                'domain_catalog_selection' => [
                    'selection_source' => 'explicit_flow',
                    'flow_id' => AtlasAiRouterDecision::FLOW_FINANCE,
                ],
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_FINANCE, $decision->flowId);
        $this->assertSame('operator_override', $decision->flowOrigin);
        $this->assertSame('confirmed', $decision->routingConfidence);
        $this->assertSame('explicit_domain_catalog_selection', $decision->routingReason);
    }

    public function test_explicit_conversation_selection_is_honored(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'corrija o bug do parser agora',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
                'domain_catalog_selection' => [
                    'selection_source' => 'explicit_flow',
                    'flow_id' => AtlasAiRouterDecision::FLOW_CONVERSATION,
                ],
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_CONVERSATION, $decision->flowId);
        $this->assertSame('operator_override', $decision->flowOrigin);
    }

    public function test_ux_mapping_selection_does_not_override_autodetect(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Implemente o endpoint de relatorios com teste',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
                'domain_catalog_selection' => [
                    'selection_source' => 'ux_mapping',
                    'flow_id' => AtlasAiRouterDecision::FLOW_CONVERSATION,
                ],
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEV, $decision->flowId);
    }

    public function test_arbiter_routes_finance_intent_from_envelope_instead_of_conversation(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            // Sem keyword de engenharia: heurísticas legadas não casam.
            'input_text' => 'monta a carteira com alocacao de risco',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'hyperflow_runtime' => [
                    'schema_version' => 'atlas.ai.hyperflow_runtime.v1',
                    'status' => 'error',
                    'intent' => ['type' => 'finance', 'confidence' => 0.8],
                ],
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_FINANCE, $decision->flowId);
        $this->assertSame('canonical_intent_arbiter:finance', $decision->routingReason);
        $this->assertSame('strong', $decision->routingConfidence);
    }

    public function test_arbiter_runs_pure_canonical_kernel_when_envelope_absent(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'analise financeira do meu portfolio de investimento e risco da carteira',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_FINANCE, $decision->flowId);
        $this->assertStringStartsWith('canonical_intent_arbiter:', $decision->routingReason);
    }

    public function test_genuine_smalltalk_still_reaches_conversation(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'bom dia, tudo bem?',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_CONVERSATION, $decision->flowId);
    }
}
