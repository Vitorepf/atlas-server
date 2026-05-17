<?php

namespace Tests\Unit\Ai\Router;

use App\Services\Ai\Router\AtlasAiRouterDecision;
use App\Services\Ai\Router\AtlasAiRouterService;
use Tests\TestCase;

class AtlasAiRouterServiceTest extends TestCase
{
    public function test_routes_patch_like_workspace_request_to_atlas_dev(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Implemente o endpoint de billing com teste',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEV, $decision->flowId);
        $this->assertSame('patch_like_with_workspace', $decision->routingReason);
        $this->assertSame('strong', $decision->routingConfidence);
        $this->assertTrue($decision->handoffPayload['workspace_present']);
    }

    public function test_routes_patch_like_without_workspace_to_plan_instead_of_dev(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Implemente um sistema de login',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_PLAN, $decision->flowId);
        $this->assertSame('patch_like_without_workspace_requires_plan', $decision->routingReason);
        $this->assertFalse($decision->handoffPayload['workspace_present']);
        $this->assertSame('plan', data_get($decision->handoffPayload, 'intent_kernel.intent_class'));
    }

    public function test_routes_diff_attachment_to_review(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Revise isso',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'attachments' => [
                    ['kind' => 'file', 'name' => 'changes.diff'],
                ],
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_REVIEW, $decision->flowId);
        $this->assertSame('diff_or_pr_attachment', $decision->routingReason);
    }

    public function test_routes_explain_without_workspace_to_explain_flow(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Explique como esse roteador funciona',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_EXPLAIN, $decision->flowId);
        $this->assertSame('explain_like_general', $decision->routingReason);
        $this->assertFalse($decision->handoffPayload['workspace_present']);
    }

    public function test_routes_atlas_code_surface_to_forge(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Executar obra',
            'payload' => [
                'surface_id' => 'atlas_code',
                'obra_id' => 'obra-1',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_FORGE, $decision->flowId);
        $this->assertSame('atlas_code_surface_requires_forge', $decision->routingReason);
        $this->assertSame('confirmed', $decision->routingConfidence);
    }

    public function test_routes_plan_like_prompt_to_atlas_plan(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Planeje a refatoracao do billing antes de implementar',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_PLAN, $decision->flowId);
        $this->assertSame('plan_like_intent', $decision->routingReason);
        $this->assertSame('plan', $decision->commandIntent);
        $this->assertSame('plan', data_get($decision->handoffPayload, 'intent_kernel.intent_class'));
        $this->assertTrue(data_get($decision->handoffPayload, 'intent_kernel.planning_required'));
    }

    public function test_routes_pesquise_prompt_to_research(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Pesquise o melhor caminho tecnico e separe fato de inferencia',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_RESEARCH, $decision->flowId);
        $this->assertSame('research_like_intent', $decision->routingReason);
        $this->assertSame('research', data_get($decision->handoffPayload, 'intent_kernel.intent_class'));
    }

    public function test_routes_debug_workspace_prompt_to_debug_with_dev_alternative(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Debug esse stacktrace no workspace atlas e rode o menor teste relevante.',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEBUG, $decision->flowId);
        $this->assertSame('logs_or_stacktrace_signal', $decision->routingReason);
        $this->assertContains(AtlasAiRouterDecision::FLOW_DEV, $decision->alternativeFlowIds);
    }

    public function test_slash_plan_overrides_auto_routing(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => '/plan implemente endpoint',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
                'workspace' => '/repo',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_PLAN, $decision->flowId);
        $this->assertSame('slash_command', $decision->flowOrigin);
        $this->assertSame('plan', $decision->commandIntent);
    }

    public function test_slash_command_overrides_auto_routing(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => '/debug esse traceback',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEBUG, $decision->flowId);
        $this->assertSame('slash_command', $decision->flowOrigin);
        $this->assertSame('debug', $decision->commandIntent);
    }
}
