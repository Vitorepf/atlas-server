<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Router\AtlasAiRouterDecision;
use App\Services\Ai\Router\AtlasAiRouterService;
use Tests\TestCase;

/**
 * Anti-regression suite for the Atlas Desktop AI surface → Hyperflow seam.
 *
 * Guards against the historical failure mode where the Desktop AI panel
 * defaulted to `programming.dev` regardless of operator intent. The suite
 * locks down 4 invariants the Desktop reads from the trace returned by
 * `/ai/interactions`:
 *
 *   1. **Research-like prompts route to `atlas_research`.** Desktop must
 *      render the research panel for them, not the Atlas Dev workbench.
 *   2. **Finance/marketing/strategy prompts NEVER auto-fall to `atlas_dev`.**
 *      They flow through `atlas_research|plan|conversation` depending on
 *      verb shape — never the programming flow.
 *   3. **Programming intent only wins with explicit signal**: workspace
 *      present + patch-like verb → `atlas_dev`; debug verb + workspace →
 *      `atlas_debug`; review + diff attachment → `atlas_review`.
 *   4. **Atlas Code surface always promotes to `atlas_forge`** — Dev/Forge
 *      boundary stays intact.
 *
 * The decision shape itself must carry every key Desktop uses to render the
 * Hyperflow decision panel: `flow_id`, `flow_origin`, `command_intent`,
 * `routing_reason`, `routing_confidence`, `handoff_payload`,
 * `alternative_flow_ids`, `surface_id`.
 */
class AtlasAiDesktopHyperflowAntiRegressionTest extends TestCase
{
    private const DESKTOP_SURFACE_ID = 'atlas_desktop_ai';

    public function test_research_prompt_from_desktop_routes_to_atlas_research(): void
    {
        $decision = $this->routerDecide(
            'Pesquise estado da arte de continuation packs em coding agents e separe fato de inferência',
        );

        $this->assertSame(AtlasAiRouterDecision::FLOW_RESEARCH, $decision->flowId);
        $this->assertSame('research_like_intent', $decision->routingReason);
        $this->assertSame('research', data_get($decision->handoffPayload, 'intent_kernel.intent_class'));
        $this->assertNotContains(AtlasAiRouterDecision::FLOW_DEV, [$decision->flowId], 'research must not silently become atlas_dev');
    }

    public function test_finance_like_prompt_from_desktop_does_not_force_atlas_dev(): void
    {
        $decision = $this->routerDecide(
            'Pesquise como o S&P 500 reagiu a juros altos no último ciclo e me dê 3 fontes',
        );

        $this->assertNotSame(
            AtlasAiRouterDecision::FLOW_DEV,
            $decision->flowId,
            'finance/research-like prompt must NOT regress into atlas_dev',
        );
        $this->assertContains(
            $decision->flowId,
            [AtlasAiRouterDecision::FLOW_RESEARCH, AtlasAiRouterDecision::FLOW_PLAN, AtlasAiRouterDecision::FLOW_CONVERSATION],
        );
    }

    public function test_marketing_like_prompt_from_desktop_does_not_force_atlas_dev(): void
    {
        $decision = $this->routerDecide(
            'Pesquise referências de copy SaaS para landing page com prova social e CTA único',
        );

        $this->assertNotSame(AtlasAiRouterDecision::FLOW_DEV, $decision->flowId);
        $this->assertContains(
            $decision->flowId,
            [AtlasAiRouterDecision::FLOW_RESEARCH, AtlasAiRouterDecision::FLOW_CONVERSATION, AtlasAiRouterDecision::FLOW_PLAN],
        );
    }

    public function test_programming_implementation_with_workspace_routes_to_atlas_dev(): void
    {
        $decision = $this->routerDecide(
            'Implemente o endpoint de billing com teste',
            payload: ['workspace' => '/tmp/atlas-workspace'],
        );

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEV, $decision->flowId);
        $this->assertSame('patch_like_with_workspace', $decision->routingReason);
        $this->assertTrue((bool) data_get($decision->handoffPayload, 'workspace_present'));
    }

    public function test_programming_without_workspace_falls_back_to_plan_not_dev(): void
    {
        $decision = $this->routerDecide('Implemente um sistema de login');

        $this->assertSame(
            AtlasAiRouterDecision::FLOW_PLAN,
            $decision->flowId,
            'patch-like without workspace must NOT silently become atlas_dev',
        );
        $this->assertFalse((bool) data_get($decision->handoffPayload, 'workspace_present'));
    }

    public function test_debug_workspace_prompt_routes_to_atlas_debug(): void
    {
        $decision = $this->routerDecide(
            'Debug esse stacktrace no workspace atlas e rode o menor teste relevante',
            payload: ['workspace' => '/tmp/atlas-workspace'],
        );

        $this->assertSame(AtlasAiRouterDecision::FLOW_DEBUG, $decision->flowId);
    }

    public function test_review_with_diff_attachment_routes_to_atlas_review(): void
    {
        $decision = $this->routerDecide(
            'Revise isso',
            payload: ['attachments' => [['kind' => 'file', 'name' => 'changes.diff']]],
        );

        $this->assertSame(AtlasAiRouterDecision::FLOW_REVIEW, $decision->flowId);
        $this->assertSame('diff_or_pr_attachment', $decision->routingReason);
    }

    public function test_atlas_code_surface_routes_to_atlas_forge_not_dev(): void
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
        $this->assertNotSame(AtlasAiRouterDecision::FLOW_DEV, $decision->flowId);
    }

    public function test_conversational_prompt_falls_back_to_atlas_conversation_not_programming_dev(): void
    {
        $decision = $this->routerDecide('Olá, tudo bem?');

        $this->assertSame(AtlasAiRouterDecision::FLOW_CONVERSATION, $decision->flowId);
        $this->assertSame('fallback_conversation', $decision->routingReason);
        $this->assertNotSame(AtlasAiRouterDecision::FLOW_DEV, $decision->flowId);
    }

    public function test_slash_research_overrides_default_routing(): void
    {
        $decision = $this->routerDecide('/research como funciona DKIM');

        $this->assertSame(AtlasAiRouterDecision::FLOW_RESEARCH, $decision->flowId);
    }

    public function test_decision_serialization_exposes_every_key_desktop_renders(): void
    {
        $decision = $this->routerDecide(
            'Implemente endpoint /status',
            payload: ['workspace' => '/tmp/atlas-workspace'],
        );

        $array = $decision->toArray();
        foreach ([
            'schema_version',
            'flow_id',
            'flow_origin',
            'command_intent',
            'routing_reason',
            'routing_confidence',
            'handoff_payload',
            'alternative_flow_ids',
        ] as $key) {
            $this->assertArrayHasKey(
                $key,
                $array,
                "Decision array must surface {$key} so Desktop AI can render the Hyperflow decision",
            );
        }
        // surface_id is carried INSIDE handoff_payload so Desktop knows which
        // surface produced the trace (atlas_desktop_ai vs atlas_code etc).
        $this->assertSame(self::DESKTOP_SURFACE_ID, data_get($array, 'handoff_payload.surface_id'));
        $this->assertSame('router_auto', $array['flow_origin']);
        $this->assertSame(AtlasAiRouterDecision::FLOW_DEV, $array['flow_id']);
    }

    public function test_decision_flow_id_belongs_to_canon_flow_set(): void
    {
        $decision = $this->routerDecide('Conversa qualquer');
        $this->assertContains($decision->flowId, AtlasAiRouterDecision::FLOWS);
        $this->assertNotContains(
            'programming.dev',
            AtlasAiRouterDecision::FLOWS,
            'Desktop must NEVER see the legacy `programming.dev` token in a Hyperflow flow_id field',
        );
    }

    public function test_handoff_payload_carries_workspace_signal_for_desktop_state(): void
    {
        $with = $this->routerDecide('Implemente algo', payload: ['workspace' => '/repo']);
        $without = $this->routerDecide('Implemente algo');

        $this->assertTrue((bool) data_get($with->handoffPayload, 'workspace_present'));
        $this->assertFalse((bool) data_get($without->handoffPayload, 'workspace_present'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function routerDecide(string $inputText, array $payload = []): AtlasAiRouterDecision
    {
        $payload = array_merge(['surface_id' => self::DESKTOP_SURFACE_ID], $payload);

        return app(AtlasAiRouterService::class)->decide([
            'input_text' => $inputText,
            'payload' => $payload,
        ]);
    }
}
