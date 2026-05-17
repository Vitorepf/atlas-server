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

    public function test_routes_patch_like_without_workspace_to_research_instead_of_dev(): void
    {
        $decision = app(AtlasAiRouterService::class)->decide([
            'input_text' => 'Implemente um sistema de login',
            'payload' => [
                'surface_id' => 'atlas_desktop_ai',
            ],
        ]);

        $this->assertSame(AtlasAiRouterDecision::FLOW_RESEARCH, $decision->flowId);
        $this->assertSame('patch_like_without_workspace', $decision->routingReason);
        $this->assertFalse($decision->handoffPayload['workspace_present']);
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
