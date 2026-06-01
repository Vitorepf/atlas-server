<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part01Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow rules (Parte 1, §1–§8):
 * the Escopo Canonico boundary table, the workspace-bound rule, the
 * surface-agnostic invariant, the ui_hints decision ban and the pipeline order.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md
 */
class AtlasDevEfficientProgrammingFlowV1Part01Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowV1Part01Service
    {
        return new AtlasDevEfficientProgrammingFlowV1Part01Service();
    }

    /**
     * §1.3 — an in-scope kind (patch) that is workspace-bound executes the fast
     * path; the same in-scope kind WITHOUT a workspace must NOT fast-path — it
     * delegates to Atlas Research instead of silently executing.
     */
    public function test_in_scope_kind_requires_workspace_binding_to_fast_path(): void
    {
        $s = $this->service();

        $bound = $s->classifyRequest('patch', true, true, 'atlas_ai_router');
        $this->assertTrue($bound['in_scope']);
        $this->assertTrue($bound['workspace_bound']);
        $this->assertSame('atlas_dev_fast_path', $bound['route']);
        $this->assertSame('atlas_dev', $bound['target_flow']);
        $this->assertTrue($bound['trusted_preclassified']);

        // In scope by kind, but no workspace + no concrete intent => not bound.
        $unbound = $s->classifyRequest('patch', false, false, 'direct');
        $this->assertTrue($unbound['in_scope']);
        $this->assertFalse($unbound['workspace_bound']);
        $this->assertSame('delegate_to_other_flow', $unbound['route']);
        $this->assertSame('atlas_research', $unbound['target_flow']);
        $this->assertSame('in_scope_kind_but_not_workspace_bound_delegates_to_research', $unbound['reason']);
    }

    /**
     * §1.3 — out-of-scope rows keep their documented route + destination flow:
     * conceptual research delegates to Atlas Research; a sensitive (auth/billing/
     * migration/security/production) change escalates to Forge.
     */
    public function test_out_of_scope_rows_delegate_or_escalate_to_documented_flow(): void
    {
        $s = $this->service();

        $research = $s->classifyRequest('conceptual_research', false, false, 'direct');
        $this->assertFalse($research['in_scope']);
        $this->assertSame('delegate_to_other_flow', $research['route']);
        $this->assertSame('atlas_research', $research['target_flow']);

        $chat = $s->classifyRequest('exploratory_chat', false, false, 'direct');
        $this->assertSame('atlas_conversation', $chat['target_flow']);

        // Sensitive change escalates to Forge even when a workspace is resolved.
        $sensitive = $s->classifyRequest('sensitive_change', true, true, 'direct');
        $this->assertFalse($sensitive['in_scope']);
        $this->assertSame('escalate_forge', $sensitive['route']);
        $this->assertSame('atlas_forge_preview', $sensitive['target_flow']);
        $this->assertSame('out_of_scope_escalates_to_forge', $sensitive['reason']);

        // Unknown kind fails safe: never executes, defers to the router.
        $unknown = $s->classifyRequest('totally_unmapped', true, true, 'direct');
        $this->assertFalse($unknown['known']);
        $this->assertFalse($unknown['in_scope']);
        $this->assertSame('delegate_to_other_flow', $unknown['route']);
        $this->assertSame('atlas_ai_router', $unknown['target_flow']);
    }

    /**
     * §1.3 — a workspace-bound question stays in Atlas Dev with no patch, but a
     * conceptual question (no workspace) is delegated to Atlas Research.
     */
    public function test_workspace_question_stays_but_conceptual_question_delegates(): void
    {
        $s = $this->service();

        $repoBound = $s->classifyRequest('workspace_question', true, true, 'atlas_ai_router');
        $this->assertSame('atlas_dev_fast_path', $repoBound['route']);
        $this->assertSame('atlas_dev', $repoBound['target_flow']);

        $conceptual = $s->classifyRequest('workspace_question', false, false, 'direct');
        $this->assertSame('delegate_to_other_flow', $conceptual['route']);
        $this->assertSame('atlas_research', $conceptual['target_flow']);
    }

    /**
     * §5.2 — surface-agnostic invariant: a core segment (Pipeline) that knows a
     * surface is a violation; the Surface segment is the only one allowed to; a
     * core segment that stays surface-agnostic does not violate.
     */
    public function test_surface_agnostic_invariant_only_surface_segment_may_know_surface(): void
    {
        $s = $this->service();

        $coreViolation = $s->surfaceAgnosticCheck('Pipeline', true);
        $this->assertTrue($coreViolation['is_core']);
        $this->assertFalse($coreViolation['surface_knowledge_allowed']);
        $this->assertTrue($coreViolation['violates']);
        $this->assertSame('core_segment_must_not_know_surface', $coreViolation['reason']);

        $surfaceOk = $s->surfaceAgnosticCheck('Surface', true);
        $this->assertTrue($surfaceOk['surface_knowledge_allowed']);
        $this->assertFalse($surfaceOk['violates']);

        $coreClean = $s->surfaceAgnosticCheck('Repair', false);
        $this->assertFalse($coreClean['violates']);
        $this->assertSame('core_segment_correctly_surface_agnostic', $coreClean['reason']);
    }

    /**
     * §5.2 — ui_hints is a derived projection: it may NEVER decide route, risk,
     * provider, scope, gate or completion; an unrelated decision is not banned.
     * The core contract input/output set is fixed.
     */
    public function test_ui_hints_cannot_decide_canonical_outcomes_and_core_contract_is_fixed(): void
    {
        $s = $this->service();

        foreach (['route', 'risk', 'provider', 'scope', 'gate', 'completion'] as $decision) {
            $this->assertFalse($s->uiHintsMayDecide($decision)['allowed'], "ui_hints must not decide {$decision}");
        }
        $this->assertTrue($s->uiHintsMayDecide('render_label')['allowed']);

        $contract = $s->coreContract();
        $this->assertSame('OperationEnvelope', $contract['input']);
        $this->assertEqualsCanonicalizing(['PlanOnlyResult', 'PatchResult'], $contract['outputs']);
    }

    /**
     * §8 — the canonical pipeline order: routing_decision is immediately followed
     * by provider_decision; routing_decision fans out to exactly three branches;
     * the provider lock is Sonnet with no fallback, fixed per run.
     */
    public function test_pipeline_order_routing_branches_and_provider_lock(): void
    {
        $s = $this->service();

        $adjacent = $s->pipelineOrder('routing_decision', 'provider_decision');
        $this->assertTrue($adjacent['ordered']);
        $this->assertSame('stages_are_adjacent_in_documented_order', $adjacent['reason']);

        // Not adjacent: intake comes long before scoped_execution.
        $notAdjacent = $s->pipelineOrder('intake_normalizado', 'scoped_execution');
        $this->assertFalse($notAdjacent['ordered']);

        $branches = $s->routingBranches();
        $this->assertCount(3, $branches);
        $this->assertEqualsCanonicalizing(
            ['read_only_answer', 'atlas_dev_fast_path', 'forge_promotion_preview'],
            $branches
        );

        $lock = $s->providerLock();
        $this->assertSame('sonnet', $lock['provider_lock']);
        $this->assertFalse($lock['fallback_allowed']);
        $this->assertSame('per_run', $lock['lock_scope']);
        $this->assertTrue($lock['may_change_between_runs']);
    }
}
