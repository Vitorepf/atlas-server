<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphBusinessContextService;
use Tests\TestCase;

/**
 * Pins the Business Context kernel step contract:
 *   - Contracts: output carries project/product/environment/sensitivity/priority.
 *   - Invariant: provider is never chosen here (provider_selected = false).
 *   - Regras para IA: business context is NOT the cognitive domain
 *     (cognitive_domain stays null).
 *   - Escopo: choosing a provider, choosing the domain, and mutating policy are
 *     forbidden at this stage.
 *   - Riscos (risk_level: high):
 *       * an unanchored context (no Obra/workspace/product) is blocked stale and
 *         cannot hand off;
 *       * a secret-class context that has not cleared Policy Profile is blocked.
 *   - Flow: a clean, anchored, cleared context hands off to domain-profile-flow.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/system-graph/business-context.md
 */
class AtlasSystemGraphBusinessContextTest extends TestCase
{
    private function service(): AtlasSystemGraphBusinessContextService
    {
        return new AtlasSystemGraphBusinessContextService;
    }

    public function test_anchored_context_hands_off_to_domain_profile_flow_without_choosing_provider_or_domain(): void
    {
        // Fluxo + Contracts: an Obra-anchored task emits the business context and
        // hands off downstream, but never picks a provider or a cognitive domain.
        $ctx = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'internal',
        ]);

        $this->assertTrue($ctx['anchored']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::READY, $ctx['readiness']);
        $this->assertTrue($ctx['can_hand_off']);
        $this->assertSame('domain-profile-flow', $ctx['next_node']);
        // Contracts invariant.
        $this->assertFalse($ctx['provider_selected']);
        // Regras para IA: this step never resolves the cognitive domain.
        $this->assertNull($ctx['cognitive_domain']);
    }

    public function test_forbidden_actions_include_provider_domain_and_policy_mutation(): void
    {
        // Escopo / forbidden_changes: never select a provider, never choose the
        // cognitive domain, never mutate policy outside Policy Profile.
        $ctx = $this->service()->resolve(['obra' => 'atlas-code']);

        $this->assertContains('select_provider_or_model', $ctx['forbidden_at_this_stage']);
        $this->assertContains('choose_cognitive_domain', $ctx['forbidden_at_this_stage']);
        $this->assertContains('mutate_policy_outside_policy_profile', $ctx['forbidden_at_this_stage']);

        // The dedicated boundary surface agrees.
        $boundary = $this->service()->forbiddenAtThisStage();
        $this->assertContains('mutate_policy_outside_policy_profile', $boundary['forbidden']);
    }

    public function test_unanchored_context_is_blocked_stale_and_cannot_hand_off(): void
    {
        // Riscos: "contexto obsoleto guiar implementacao errada." An envelope with
        // no Obra / workspace / product is unanchored -> stale, no hand-off, high risk.
        $ctx = $this->service()->resolve([
            // only a free-floating project name, none of the real business anchors
            'project' => 'random',
        ]);

        $this->assertFalse($ctx['anchored']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::BLOCKED_STALE, $ctx['readiness']);
        $this->assertFalse($ctx['can_hand_off']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::RISK_HIGH, $ctx['risk']);
        $this->assertContains('obra', $ctx['missing_anchors']);
        $this->assertContains('workspace', $ctx['missing_anchors']);
        $this->assertContains('product', $ctx['missing_anchors']);
    }

    public function test_secret_context_without_policy_clearance_is_blocked(): void
    {
        // Riscos: "Business Context conter segredo sem policy adequada."
        // A fully anchored but uncleared secret context must not hand off.
        $blocked = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'secret',
            // policy_profile_cleared intentionally absent
        ]);

        $this->assertTrue($blocked['anchored']);
        $this->assertTrue($blocked['requires_policy_clearance']);
        $this->assertFalse($blocked['policy_cleared']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::BLOCKED_POLICY, $blocked['readiness']);
        $this->assertFalse($blocked['can_hand_off']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::RISK_HIGH, $blocked['risk']);

        // Same context, now cleared by Policy Profile -> it may hand off.
        $cleared = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'secret',
            'policy_profile_cleared' => true,
        ]);

        $this->assertTrue($cleared['policy_cleared']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::READY, $cleared['readiness']);
        $this->assertTrue($cleared['can_hand_off']);
    }

    public function test_sensitivity_lifts_priority_and_risk(): void
    {
        // High sensitivity (sensitive/secret) lifts both priority and risk; a clean
        // public/internal anchored context stays modest.
        $internal = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'internal',
        ]);
        $this->assertSame(AtlasSystemGraphBusinessContextService::PRIORITY_NORMAL, $internal['priority']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::RISK_MEDIUM, $internal['risk']);

        $public = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'public',
        ]);
        $this->assertSame(AtlasSystemGraphBusinessContextService::RISK_LOW, $public['risk']);

        $sensitive = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'sensitive',
            'policy_profile_cleared' => true,
        ]);
        $this->assertSame(AtlasSystemGraphBusinessContextService::PRIORITY_HIGH, $sensitive['priority']);
        $this->assertSame(AtlasSystemGraphBusinessContextService::RISK_HIGH, $sensitive['risk']);
    }

    public function test_unknown_sensitivity_falls_back_to_internal(): void
    {
        // Closed vocabulary: an out-of-band sensitivity token is normalized to the
        // safe default (internal), never trusted as public.
        $ctx = $this->service()->resolve([
            'obra' => 'atlas-code',
            'workspace' => 'atlas-server',
            'product' => 'atlas',
            'sensitivity' => 'whatever-nonsense',
        ]);

        $this->assertSame(AtlasSystemGraphBusinessContextService::SENSITIVITY_INTERNAL, $ctx['sensitivity']);
        $this->assertContains('internal', $this->service()->sensitivityLadder());
    }
}
