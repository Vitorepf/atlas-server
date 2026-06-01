<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAutonomousImplementationLoopService;
use Tests\TestCase;

/**
 * Pins the documented Autonomous Implementation Loop contract.
 *
 * @see docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
 */
class AtlasAutonomousImplementationLoopTest extends TestCase
{
    private function service(): AtlasAutonomousImplementationLoopService
    {
        return new AtlasAutonomousImplementationLoopService();
    }

    /** A complete, valid 9-field Loop Receipt for use in proceed-path tests. */
    private function fullReceipt(): array
    {
        return [
            'operation_id' => 'AIL-1',
            'target_capability' => 'autonomous_implementation_loop',
            'maturity_delta' => 1,
            'allowed_files' => ['app/Services/Ai/Aaeos/Generated/'],
            'allowed_commands' => ['php artisan atlas:engineering:knowledge docs-health --json'],
            'required_gates' => ['docs-health'],
            'rollback' => 'git revert',
            'evidence' => ['doc.md'],
            'max_scope' => 'single read-only slice',
        ];
    }

    /**
     * Loop Stages order is exactly the 13 documented stages, and nextStage
     * returns the first incomplete one (after Observe+Diagnose => Research).
     */
    public function test_stage_order_and_next_stage(): void
    {
        $this->assertSame(
            [
                'observe', 'diagnose', 'research', 'document', 'specify', 'plan',
                'decide', 'execute', 'validate', 'evidence', 'drift_check',
                'learn', 'report',
            ],
            AtlasAutonomousImplementationLoopService::STAGES,
        );

        $this->assertSame('research', $this->service()->nextStage(['observe', 'diagnose']));
        // All complete => the final stage is Report.
        $this->assertSame('report', $this->service()->nextStage(AtlasAutonomousImplementationLoopService::STAGES));
    }

    /**
     * Stage gating: a gated loop, not an open-ended session. Execute (stage 8)
     * cannot run when earlier stages are missing, and the precise missing
     * prerequisites + required output kind are reported.
     */
    public function test_stage_gating_blocks_out_of_order_execution(): void
    {
        $blocked = $this->service()->evaluateStage('execute', ['observe', 'diagnose']);

        $this->assertFalse($blocked['allowed']);
        $this->assertSame(
            ['research', 'document', 'specify', 'plan', 'decide'],
            $blocked['missing_prerequisites'],
        );
        // Stage Contracts table: Execute emits a patch or no-op with reason.
        $this->assertSame('patch_or_no_op_with_reason', $blocked['required_output']);

        // With every prerequisite complete, the same stage is admitted.
        $allowed = $this->service()->evaluateStage('execute', [
            'observe', 'diagnose', 'research', 'document', 'specify', 'plan', 'decide',
        ]);
        $this->assertTrue($allowed['allowed']);
        $this->assertSame([], $allowed['missing_prerequisites']);
    }

    /**
     * Stop Conditions: ANY of the 8 documented triggers forces stop + human
     * review, overriding an otherwise-valid step. The fired condition is named.
     */
    public function test_stop_condition_forces_stop_and_review(): void
    {
        $request = [
            'requested_stage' => 'execute',
            'completed_stages' => [
                'observe', 'diagnose', 'research', 'document', 'specify', 'plan', 'decide',
            ],
            'slice' => ['description' => 'small reversible slice', 'maturity_delta' => 1],
            'receipt' => $this->fullReceipt(),
            'signals' => ['high_risk_without_human_gate' => true],
        ];

        $decision = $this->service()->evaluate($request);

        $this->assertSame(AtlasAutonomousImplementationLoopService::DECISION_STOP, $decision['decision']);
        $this->assertContains('stop_condition', $decision['blockers']);
        $this->assertTrue($decision['human_review_required']);
        $this->assertTrue($decision['stop_conditions']['stop']);
        $this->assertSame('high_risk_without_human_gate', $decision['stop_conditions']['fired'][0]['condition']);
        $this->assertSame('risk is high and no human gate exists', $decision['stop_conditions']['fired'][0]['reason']);

        // No signals => stop conditions clear.
        $clear = $this->service()->evaluateStopConditions([]);
        $this->assertFalse($clear['stop']);
        $this->assertSame('continue', $clear['next_action']);
    }

    /**
     * Small Slice Rule: the documented Bad example ("Implement all
     * self-programming runtime.") is rejected; the Good example (a small,
     * read-only, maturity-improving slice) is accepted. A zero/negative
     * maturity delta is also rejected.
     */
    public function test_small_slice_rule_rejects_implement_all(): void
    {
        $bad = $this->service()->evaluateSlice([
            'description' => 'Implement all self-programming runtime.',
            'maturity_delta' => 1,
        ]);
        $this->assertFalse($bad['within_small_slice']);
        $this->assertTrue($bad['oversized_scope']);
        $this->assertContains('oversized_scope:implement all', $bad['reasons']);

        $good = $this->service()->evaluateSlice([
            'description' => 'Implement read-only self-construction gap report with tests and docs.',
            'maturity_delta' => 1,
        ]);
        $this->assertTrue($good['within_small_slice']);
        $this->assertTrue($good['improves_maturity']);

        // A slice that does not improve maturity is not the smallest IMPROVING block.
        $noGain = $this->service()->evaluateSlice([
            'description' => 'tiny tweak',
            'maturity_delta' => 0,
        ]);
        $this->assertFalse($noGain['within_small_slice']);
        $this->assertContains('does_not_improve_maturity', $noGain['reasons']);
    }

    /**
     * Loop Receipt Requirements: all 9 fields are mandatory. A missing/empty
     * field makes the receipt invalid and is reported by name; a complete
     * receipt is valid.
     */
    public function test_loop_receipt_requires_all_nine_fields(): void
    {
        $this->assertCount(9, AtlasAutonomousImplementationLoopService::RECEIPT_FIELDS);

        $full = $this->service()->validateReceipt($this->fullReceipt());
        $this->assertTrue($full['valid']);
        $this->assertSame([], $full['missing_fields']);
        $this->assertSame(9, $full['present_count']);

        $partial = $this->fullReceipt();
        unset($partial['rollback']);
        $partial['evidence'] = []; // present but empty => still missing
        $bad = $this->service()->validateReceipt($partial);
        $this->assertFalse($bad['valid']);
        $this->assertContains('rollback', $bad['missing_fields']);
        $this->assertContains('evidence', $bad['missing_fields']);

        // Empty receipt => all 9 missing.
        $empty = $this->service()->validateReceipt([]);
        $this->assertCount(9, $empty['missing_fields']);
    }

    /**
     * Learning Rule: the loop may emit a proposal, but "may not apply critical
     * learning automatically." Adding a new gate is critical => no auto-apply;
     * a non-critical proposal may auto-apply; an unknown kind is rejected.
     */
    public function test_learning_rule_blocks_auto_apply_of_critical_learning(): void
    {
        $critical = $this->service()->evaluateLearningProposal(['kind' => 'add_a_new_gate']);
        $this->assertTrue($critical['recognized']);
        $this->assertTrue($critical['is_critical']);
        $this->assertTrue($critical['may_emit_proposal']);
        $this->assertFalse($critical['auto_apply_allowed']);
        $this->assertSame('emit_proposal_and_require_human_gate', $critical['required_action']);

        $nonCritical = $this->service()->evaluateLearningProposal(['kind' => 'improve_source_scoring']);
        $this->assertTrue($nonCritical['auto_apply_allowed']);
        $this->assertSame('emit_proposal', $nonCritical['required_action']);

        $unknown = $this->service()->evaluateLearningProposal(['kind' => 'rewrite_kernel']);
        $this->assertFalse($unknown['recognized']);
        $this->assertFalse($unknown['auto_apply_allowed']);
        $this->assertSame('reject_unknown_learning_kind', $unknown['required_action']);
    }

    /**
     * Full proceed path: an in-order stage with a complete receipt, a small
     * maturity-improving slice and no stop signal proceeds autonomously.
     */
    public function test_full_step_proceeds_when_all_gates_clear(): void
    {
        $request = [
            'requested_stage' => 'execute',
            'completed_stages' => [
                'observe', 'diagnose', 'research', 'document', 'specify', 'plan', 'decide',
            ],
            'slice' => [
                'description' => 'Implement read-only self-construction gap report with tests and docs.',
                'maturity_delta' => 1,
            ],
            'receipt' => $this->fullReceipt(),
            'signals' => [],
        ];

        $decision = $this->service()->evaluate($request);
        $this->assertSame(AtlasAutonomousImplementationLoopService::DECISION_PROCEED, $decision['decision']);
        $this->assertSame([], $decision['blockers']);
        $this->assertFalse($decision['human_review_required']);
        $this->assertTrue($this->service()->mayProceed($request));
    }
}
