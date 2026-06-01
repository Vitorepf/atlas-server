<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part02Service;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev efficient programming flow rules (Parte 2, §9–§18).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md
 */
class AtlasDevEfficientProgrammingFlowV1Part02Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowV1Part02Service
    {
        return new AtlasDevEfficientProgrammingFlowV1Part02Service();
    }

    /**
     * §9 — `verifying` legally branches to {passed,needs_review,failed}; a final
     * state like `completed` has no successor, so any transition out of it is illegal.
     */
    public function test_state_machine_transitions_follow_documented_graph(): void
    {
        $s = $this->service();

        $legal = $s->evaluateTransition('verifying', 'failed');
        $this->assertTrue($legal['legal']);
        $this->assertSame('transition_in_documented_graph', $legal['reason']);
        $this->assertEqualsCanonicalizing(['passed', 'needs_review', 'failed'], $legal['allowed_next']);

        // Not a drawn edge: verifying -> completed (must go through `passed` first).
        $notDrawn = $s->evaluateTransition('verifying', 'completed');
        $this->assertFalse($notDrawn['legal']);
        $this->assertSame('transition_not_in_documented_graph', $notDrawn['reason']);

        // Terminal state has no successor.
        $fromFinal = $s->evaluateTransition('completed', 'executing');
        $this->assertFalse($fromFinal['legal']);
        $this->assertSame('source_is_terminal_final_state', $fromFinal['reason']);
        $this->assertTrue($s->isFinalState('failed_closed'));
        $this->assertTrue($s->isGateState('waived'));
        $this->assertFalse($s->isGateState('completed'));
    }

    /**
     * §10 — task_kind classification: `risky` is plan-only Forge preview with NO
     * write; `patch` is fast_path WITH write; an unknown kind degrades to safe read_only.
     */
    public function test_task_classification_maps_kind_to_initial_mode(): void
    {
        $s = $this->service();

        $risky = $s->classifyInitialMode('risky');
        $this->assertSame('plan_only_forge_preview', $risky['initial_mode']);
        $this->assertFalse($risky['write_allowed']);

        $patch = $s->classifyInitialMode('patch');
        $this->assertSame('fast_path', $patch['initial_mode']);
        $this->assertTrue($patch['write_allowed']);

        $unknown = $s->classifyInitialMode('whatever_unmapped');
        $this->assertSame('unknown', $unknown['task_kind']);
        $this->assertSame('read_only', $unknown['initial_mode']);
        $this->assertFalse($unknown['write_allowed']);
    }

    /**
     * §11 — R-level envelope: R0 allows 0 repairs, R3 allows up to 2, R5 is
     * Forge-only; a repair attempt past the cap escalates instead of looping.
     */
    public function test_risk_level_repair_caps_and_forge_only(): void
    {
        $s = $this->service();

        $this->assertSame(0, $s->riskEnvelope('R0')['max_repairs']);
        $this->assertSame(2, $s->riskEnvelope('R3')['max_repairs']);

        $r5 = $s->riskEnvelope('R5');
        $this->assertTrue($r5['forge_only']);
        $this->assertFalse($r5['native_to_dev']);

        // R2 cap is 1 repair: attempt #1 allowed, attempt #2 must escalate/close.
        $first = $s->repairDecision('R2', 1);
        $this->assertTrue($first['allowed']);
        $this->assertSame('repair_allowed', $first['decision']);

        $second = $s->repairDecision('R2', 2);
        $this->assertFalse($second['allowed']);
        $this->assertSame('repair_cap_reached_escalate_or_close', $second['decision']);
    }

    /**
     * §14/§17 — the seven non-negotiable write gates are present for a write, and
     * adaptive can never remove one for a task that writes.
     */
    public function test_non_negotiable_write_gates_cannot_be_removed_by_adaptive(): void
    {
        $s = $this->service();

        $req = $s->writeGateRequirements(true);
        $this->assertCount(7, $req['gates']);
        $this->assertContains('scope_guard_light', $req['gates']);
        $this->assertContains('verification_gate', $req['gates']);
        $this->assertFalse($req['adaptive_may_remove_non_negotiable']);

        // A write task cannot drop a protected gate...
        $this->assertFalse($s->canAdaptiveRemoveGate('verification_gate', true));
        // ...but a read-only task is not bound by the write-gate invariant.
        $this->assertTrue($s->canAdaptiveRemoveGate('verification_gate', false));

        // No gates required when there is no write.
        $this->assertSame([], $s->writeGateRequirements(false)['gates']);
    }

    /**
     * §15.2 — context budget is in chars; read_only max is 6000. Overflow that
     * drops a REQUIRED source (code_intelligence) must mark truncated + escalate;
     * an in-budget fit does not.
     */
    public function test_context_char_budget_and_overflow_escalation(): void
    {
        $s = $this->service();

        $budget = $s->contextBudget('read_only');
        $this->assertSame('chars', $budget['unit']);
        $this->assertSame(4000, $budget['min_chars']);
        $this->assertSame(6000, $budget['max_chars']);
        $this->assertTrue($budget['in_fast_path']);

        // full_forge is out of the fast path.
        $this->assertFalse($s->contextBudget('full_forge')['in_fast_path']);

        // 9000 chars > 6000 budget AND code_intelligence (required) was dropped => escalate.
        $over = $s->applyBudgetOverflow('read_only', 9000, ['core', 'code_intelligence'], ['code_intelligence']);
        $this->assertFalse($over['within_budget']);
        $this->assertTrue($over['truncated']);
        $this->assertTrue($over['escalate']);
        $this->assertSame('required_source_did_not_fit_escalate', $over['reason']);
        $this->assertEqualsCanonicalizing(['core', 'code_intelligence'], $over['preserved']);

        // Within budget, nothing dropped => no truncation, no escalation.
        $fits = $s->applyBudgetOverflow('read_only', 5000, ['core', 'code_intelligence'], []);
        $this->assertTrue($fits['within_budget']);
        $this->assertFalse($fits['truncated']);
        $this->assertFalse($fits['escalate']);

        // Truncated lower-priority refs only (no required source dropped) => no escalation.
        $softTrim = $s->applyBudgetOverflow('read_only', 8000, ['core', 'code_intelligence'], ['extra_refs']);
        $this->assertTrue($softTrim['truncated']);
        $this->assertFalse($softTrim['escalate']);
        $this->assertSame('truncated_lower_priority_refs', $softTrim['reason']);
    }

    /**
     * §18 — scope guard severity ordering: forbidden file blocks completion;
     * over the 5-6 file ceiling OR 3+ layers escalates; an unforeseen-but-defensible
     * file is needs_review; a clean diff within contract passes.
     */
    public function test_scope_guard_verdict_severity_ordering(): void
    {
        $s = $this->service();

        $forbidden = $s->scopeGuardVerdict(['config/auth.php'], [], 2, 1);
        $this->assertSame('blocked', $forbidden['verdict']);
        $this->assertTrue($forbidden['blocks_completion']);

        // 7 files > default expected_max 6 => escalate_forge.
        $tooMany = $s->scopeGuardVerdict([], [], 7, 1);
        $this->assertSame('escalate_forge', $tooMany['verdict']);
        $this->assertTrue($tooMany['escalate']);
        $this->assertSame('over_expected_max_files', $tooMany['reason']);

        // 3 layers also escalates even within the file ceiling.
        $deepLayers = $s->scopeGuardVerdict([], [], 3, 3);
        $this->assertSame('escalate_forge', $deepLayers['verdict']);
        $this->assertSame('three_plus_layers_touched', $deepLayers['reason']);

        // Unforeseen-but-defensible file => needs_review (not block, not escalate).
        $unforeseen = $s->scopeGuardVerdict([], ['app/Support/Helper.php'], 2, 1);
        $this->assertSame('needs_review', $unforeseen['verdict']);
        $this->assertFalse($unforeseen['blocks_completion']);

        // Clean diff within the contract, pre-existing user change flagged not absorbed.
        $clean = $s->scopeGuardVerdict([], [], 2, 1, 6, ['README.md']);
        $this->assertSame('within_scope', $clean['verdict']);
        $this->assertSame(['README.md'], $clean['preexisting_flagged']);
    }
}
