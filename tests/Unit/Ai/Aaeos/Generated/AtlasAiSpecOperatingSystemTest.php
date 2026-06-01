<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiSpecOperatingSystemService;
use Tests\TestCase;

/**
 * Pins the top-level Spec Operating System contract: the seven Hard Laws, the
 * sixteen-stage canonical flow order, the design-directive reconciliation and
 * the one-shot eligibility gate.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
 */
class AtlasAiSpecOperatingSystemTest extends TestCase
{
    private function service(): AtlasAiSpecOperatingSystemService
    {
        return new AtlasAiSpecOperatingSystemService();
    }

    /** A fully-governed step with all Hard-Law preconditions met. */
    private function compliantStep(): array
    {
        return [
            'risky' => true,
            'has_operational_spec' => true,
            'spec_present' => true,
            'has_context' => true,
            'executing' => true,
            'has_decision_receipt' => true,
            'produced_result' => true,
            'has_evidence' => true,
            'learning_changes_critical_behavior' => true,
            'learning_reviewed' => true,
            'atlas_tree_overrides_canonical' => false,
            'contradicts_design_or_security' => false,
            'divergence_recorded' => false,
        ];
    }

    /** A clean, fully-governed step passes all seven Hard Laws. */
    public function test_compliant_step_passes_all_hard_laws(): void
    {
        $v = $this->service()->evaluateOperation($this->compliantStep());

        $this->assertSame(AtlasAiSpecOperatingSystemService::VERDICT_ALLOW, $v['verdict']);
        $this->assertSame([], $v['violations']);
        $this->assertTrue($this->service()->operationAllowed($this->compliantStep()));
        // All seven documented Hard Laws are the enforced set.
        $this->assertCount(7, $v['laws_enforced']);
    }

    /**
     * Each Hard Law fires on exactly its own breach.
     * - risky implementation with no operational spec
     * - execution with no Decision Receipt
     * - result with no evidence
     * - critical-behavior learning with no review
     * - a `.atlas` tree claiming authority over canon
     */
    public function test_each_hard_law_blocks_its_specific_breach(): void
    {
        $svc = $this->service();

        $noSpec = $this->compliantStep();
        $noSpec['has_operational_spec'] = false;
        $this->assertContains('risky_impl_requires_spec', $svc->evaluateOperation($noSpec)['violations']);

        $noReceipt = $this->compliantStep();
        $noReceipt['has_decision_receipt'] = false;
        $r = $svc->evaluateOperation($noReceipt);
        $this->assertSame(AtlasAiSpecOperatingSystemService::VERDICT_BLOCK, $r['verdict']);
        $this->assertContains('execution_requires_receipt', $r['violations']);

        $noEvidence = $this->compliantStep();
        $noEvidence['has_evidence'] = false;
        $this->assertContains('result_requires_evidence', $svc->evaluateOperation($noEvidence)['violations']);

        $unreviewedLearning = $this->compliantStep();
        $unreviewedLearning['learning_reviewed'] = false;
        $this->assertContains('critical_learning_requires_review', $svc->evaluateOperation($unreviewedLearning)['violations']);

        $treeOverrides = $this->compliantStep();
        $treeOverrides['atlas_tree_overrides_canonical'] = true;
        $this->assertContains('atlas_tree_cannot_outrank_canonical', $svc->evaluateOperation($treeOverrides)['violations']);
    }

    /**
     * Hard Law 7: blindly obeying wording that contradicts the design system is
     * a block; recording the divergence clears it.
     */
    public function test_blind_obedience_against_design_is_blocked_until_divergence_recorded(): void
    {
        $svc = $this->service();

        $blind = $this->compliantStep();
        $blind['contradicts_design_or_security'] = true;
        $blind['divergence_recorded'] = false;
        $this->assertContains(
            'no_blind_obedience_vs_design_or_security',
            $svc->evaluateOperation($blind)['violations'],
        );

        $recorded = $blind;
        $recorded['divergence_recorded'] = true;
        $this->assertSame([], $svc->evaluateOperation($recorded)['violations']);
    }

    /**
     * Canonical flow: the full canonical order is valid; a trace that runs the
     * execution harness before the Decision Receipt is rejected (Hard Law 3).
     */
    public function test_canonical_flow_order_is_enforced(): void
    {
        $svc = $this->service();

        $ok = $svc->validateFlowOrder(AtlasAiSpecOperatingSystemService::CANONICAL_FLOW);
        $this->assertTrue($ok['valid']);
        $this->assertSame([], $ok['unknown_stages']);
        $this->assertSame([], $ok['out_of_order_stages']);

        $bad = $svc->validateFlowOrder([
            'context_discovery',
            'spec_compiler',
            'execution_harness', // before the receipt
            'decision_receipt',
        ]);
        $this->assertFalse($bad['valid']);
        $this->assertTrue($bad['execution_before_receipt']);
        $this->assertSame(AtlasAiSpecOperatingSystemService::VERDICT_BLOCK, $bad['verdict']);
    }

    /**
     * Green Save Button: a green request against a `primary` design token is
     * reconciled to the design token with the divergence recorded — never
     * blindly obeyed; a matching request is honored as-is.
     */
    public function test_design_directive_prefers_design_token_and_records_divergence(): void
    {
        $svc = $this->service();

        $conflict = $svc->resolveDesignDirective([
            'requested_token' => 'green',
            'design_system_token' => 'primary',
            'action' => 'save',
        ]);
        $this->assertTrue($conflict['conflict']);
        $this->assertSame('primary', $conflict['applied_token']);
        $this->assertFalse($conflict['blindly_obeyed']);
        $this->assertTrue($conflict['divergence_recorded']);
        $this->assertNotNull($conflict['divergence_note']);

        $agree = $svc->resolveDesignDirective([
            'requested_token' => 'primary',
            'design_system_token' => 'primary',
            'action' => 'save',
        ]);
        $this->assertFalse($agree['conflict']);
        $this->assertSame('primary', $agree['applied_token']);
        $this->assertNull($agree['divergence_note']);
    }

    /**
     * One-shot eligibility: allowed only with high confidence AND available
     * gates; otherwise it routes to the staged pipeline (review, not block).
     */
    public function test_one_shot_requires_high_confidence_and_available_gates(): void
    {
        $svc = $this->service();

        $eligible = $svc->evaluateOneShotEligibility([
            'confidence' => 'high',
            'gates_available' => true,
        ]);
        $this->assertTrue($eligible['one_shot_allowed']);
        $this->assertSame('one_shot', $eligible['route']);
        $this->assertSame(AtlasAiSpecOperatingSystemService::VERDICT_ALLOW, $eligible['verdict']);

        $lowConf = $svc->evaluateOneShotEligibility([
            'confidence' => 'medium',
            'gates_available' => true,
        ]);
        $this->assertFalse($lowConf['one_shot_allowed']);
        $this->assertSame('staged_pipeline', $lowConf['route']);
        $this->assertSame(AtlasAiSpecOperatingSystemService::VERDICT_REVIEW, $lowConf['verdict']);
        $this->assertContains('confidence_not_high:medium', $lowConf['block_reasons']);

        $noGates = $svc->evaluateOneShotEligibility([
            'confidence' => 'high',
            'gates_available' => false,
        ]);
        $this->assertFalse($noGates['one_shot_allowed']);
        $this->assertContains('gates_unavailable', $noGates['block_reasons']);
    }
}
