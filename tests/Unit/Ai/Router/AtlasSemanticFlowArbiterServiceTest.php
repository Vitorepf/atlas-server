<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiRouterDecision;
use App\Services\Ai\Router\AtlasSemanticFlowArbiterService;
use Tests\TestCase;

final class AtlasSemanticFlowArbiterServiceTest extends TestCase
{
    public function test_valid_flow_id_in_model_output_is_accepted(): void
    {
        $svc = new AtlasSemanticFlowArbiterService(fn (string $prompt): string => "atlas_finance\n");

        $this->assertSame(AtlasAiRouterDecision::FLOW_FINANCE, $svc->arbitrate('analise o ativo PETR4 e diga se vale entrada'));
    }

    public function test_prose_around_the_id_still_resolves_to_last_valid_id(): void
    {
        $svc = new AtlasSemanticFlowArbiterService(
            fn (string $prompt): string => "Considerando a mensagem, o flow certo é: atlas_debug",
        );

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEBUG, $svc->arbitrate('analisa essa página, o botão de login quebrou'));
    }

    public function test_output_outside_catalog_fails_open_to_null(): void
    {
        $svc = new AtlasSemanticFlowArbiterService(fn (string $prompt): string => 'atlas_marte');

        $this->assertNull($svc->arbitrate('mensagem qualquer fora do vocabulário'));
    }

    public function test_runner_failure_fails_open_to_null(): void
    {
        $svc = new AtlasSemanticFlowArbiterService(function (string $prompt): ?string {
            throw new \RuntimeException('provider indisponível');
        });

        $this->assertNull($svc->arbitrate('analise o funil da campanha de ontem'));
    }

    public function test_disabled_flag_short_circuits_without_calling_runner(): void
    {
        config()->set('atlas.ai.semantic_arbiter.enabled', false);
        $called = false;
        $svc = new AtlasSemanticFlowArbiterService(function (string $prompt) use (&$called): string {
            $called = true;

            return 'atlas_finance';
        });

        $this->assertNull($svc->arbitrate('analise o ativo VALE3'));
        $this->assertFalse($called);
    }

    public function test_prompt_carries_the_full_flow_catalog(): void
    {
        $captured = '';
        $svc = new AtlasSemanticFlowArbiterService(function (string $prompt) use (&$captured): string {
            $captured = $prompt;

            return 'atlas_conversation';
        });
        $svc->arbitrate('bom dia, tudo bem por aí?');

        foreach (array_keys(AtlasSemanticFlowArbiterService::FLOW_CATALOG) as $id) {
            $this->assertStringContainsString($id, $captured);
        }
    }
}
