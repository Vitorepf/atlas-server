<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionContractService;
use Tests\TestCase;

/**
 * Pins the specific documented rules of
 * docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-contract.md.
 *
 * Pure, deterministic, no DB.
 */
final class AtlasCodexMergePostExecutionContractTest extends TestCase
{
    private function service(): AtlasCodexMergePostExecutionContractService
    {
        return new AtlasCodexMergePostExecutionContractService();
    }

    /**
     * Doc "Boundary": the six keys must stay false. The preflight always emits
     * exactly those six false keys (no extras, none flipped).
     */
    public function test_preflight_holds_the_six_false_boundary_keys(): void
    {
        $preflight = $this->service()->postExecutionPreflight();

        $this->assertSame([
            'execution_allowed' => false,
            'execution_receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ], $preflight['boundary']);
    }

    /**
     * Doc "Required Inputs" (7), "Required Checks" (12), "Blocking Conditions"
     * (10), "Future Merge Action" (6 obligations + consume persisted receipt
     * only). The preflight surfaces exactly those counts.
     */
    public function test_preflight_declares_documented_input_check_block_and_action_lists(): void
    {
        $preflight = $this->service()->postExecutionPreflight();

        $this->assertCount(7, $preflight['required_inputs']);
        $this->assertCount(12, $preflight['required_checks']);
        $this->assertCount(10, $preflight['blocking_conditions']);
        $this->assertCount(6, $preflight['future_merge_action_obligations']);

        // The merge candidate hash is a required input; gates passed is a check;
        // a diff mismatch is a blocking condition.
        $this->assertContains('merge_candidate_hash', $preflight['required_inputs']);
        $this->assertContains('post_execution_gates_passed', $preflight['required_checks']);
        $this->assertContains('post_execution_diff_mismatch', $preflight['blocking_conditions']);

        // Doc "Future Merge Action": consume the persisted execution receipt only.
        $this->assertSame('persisted_execution_receipt_only', $preflight['future_merge_action_consumes']);
        $this->assertContains('emit_a_final_merge_receipt', $preflight['future_merge_action_obligations']);
    }

    /**
     * Doc "Action Template": "It must default to do_not_merge" and "may become
     * ready only after post-execution preflight is ready." With no readiness
     * signal (safe default) the template is fail-closed: not ready, and it lists
     * NO required inputs, NO validations and NO action steps — yet still defaults
     * to do_not_merge.
     */
    public function test_action_template_fails_closed_to_do_not_merge_without_preflight(): void
    {
        $template = $this->service()->actionTemplate();

        $this->assertFalse($template['template_ready']);
        $this->assertSame('blocked_before_post_execution_preflight', $template['status']);
        $this->assertSame('do_not_merge', $template['default_decision']);

        // Nothing is templated until the preflight is proven ready.
        $this->assertSame([], $template['required_inputs']);
        $this->assertSame([], $template['validations']);
        $this->assertSame([], $template['action_steps']);
    }

    /**
     * A non-boolean / truthy-but-not-true readiness signal must NOT open the gate
     * (fail-closed). Only an exact boolean true clears it, which then surfaces the
     * documented 6 required inputs, 7 validations and 6 action steps ending in the
     * unsigned-receipt terminal step.
     */
    public function test_action_template_only_opens_on_exact_true_and_lists_documented_steps(): void
    {
        $service = $this->service();

        // Truthy-but-not-true => still closed.
        $loose = $service->actionTemplate(['post_execution_preflight_ready' => 1]);
        $this->assertFalse($loose['template_ready']);
        $this->assertSame([], $loose['action_steps']);

        // Exact true => open, with documented lists.
        $ready = $service->actionTemplate(['post_execution_preflight_ready' => true]);
        $this->assertTrue($ready['template_ready']);
        $this->assertSame('action_template_ready', $ready['status']);
        $this->assertCount(6, $ready['required_inputs']);
        $this->assertCount(7, $ready['validations']);
        $this->assertCount(6, $ready['action_steps']);
        $this->assertSame('emit_unsigned_final_merge_action_receipt', $ready['action_steps'][5]);

        // Even when ready, the template still forbids approval/merge/dispatch/persist.
        $this->assertSame([
            'approval' => false,
            'merge' => false,
            'dispatch' => false,
            'final_receipt_persistence' => false,
        ], $ready['forbids']);
        $this->assertSame('do_not_merge', $ready['default_decision']);
    }

    /**
     * Doc "Boundary": across the whole evaluation — even with the preflight proven
     * ready — the boundary must hold (no key, nested forbid, or restated guarantee
     * ever flips true).
     */
    public function test_evaluate_keeps_boundary_held_even_when_template_is_ready(): void
    {
        $result = $this->service()->evaluate(['post_execution_preflight_ready' => true]);

        $this->assertTrue($result['action_template']['template_ready']);
        $this->assertSame([], $result['boundary_violations']);
        $this->assertTrue($result['boundary_held']);
    }
}
