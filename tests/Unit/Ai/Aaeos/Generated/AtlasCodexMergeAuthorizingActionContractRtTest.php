<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeAuthorizingActionContractRtService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge Authorizing Action Contract: the read-only
 * boundary flags across all four surfaces, the default decision
 * (`request_changes`), the merge-only / all-validations-pass decision rule (with
 * `request_changes` / `abort` fallbacks and no implicit approval), the documented
 * input / validation / receipt-field enumerations, the runbook halt and the
 * separate-executor chain.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
 */
class AtlasCodexMergeAuthorizingActionContractRtTest extends TestCase
{
    private function service(): AtlasCodexMergeAuthorizingActionContractRtService
    {
        return new AtlasCodexMergeAuthorizingActionContractRtService();
    }

    /**
     * Doc "Read-Only Boundary" and every surface's "It must keep ...=false":
     * every gate flag on every surface is false, and the composite proves the
     * boundary held with zero violations.
     */
    public function test_all_surface_boundaries_stay_false(): void
    {
        $svc = $this->service();

        // Template: the nine documented gate flags, all false.
        $template = $svc->authorizingActionTemplate();
        $this->assertSame([
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ], $template['boundary']);

        // Receipt draft: receipt_signed plus the nine, all false.
        $this->assertFalse($svc->finalReceiptDraft()['boundary']['receipt_signed']);
        $this->assertSame(
            array_fill_keys(array_keys($svc->finalReceiptDraft()['boundary']), false),
            $svc->finalReceiptDraft()['boundary'],
        );

        // Signature request: requires a future signature but holds no signature.
        $sig = $svc->finalSignatureRequest();
        $this->assertTrue($sig['signature_required']);
        $this->assertFalse($sig['boundary']['signature_present']);
        $this->assertFalse($sig['boundary']['signature_valid']);
        $this->assertFalse($sig['boundary']['merge_allowed']);

        // Runbook: the five documented flags it must keep false.
        $this->assertSame([
            'signature_valid' => false,
            'receipt_signed' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ], $svc->postSignatureRunbook()['boundary']);

        // Composite proof: boundary held everywhere, no violations.
        $eval = $svc->evaluate();
        $this->assertTrue($eval['boundary_held']);
        $this->assertSame([], $eval['boundary_violations']);
    }

    /**
     * Doc "The default decision is always `request_changes`" — with no inputs,
     * and whenever any documented validation fails, the decision falls back to
     * the safe default and approval is never granted.
     */
    public function test_default_decision_is_request_changes_and_never_authorizes(): void
    {
        $svc = $this->service();

        $this->assertSame('request_changes', $svc->defaultDecision());

        // No inputs at all => default decision, no approval, not eligible.
        $preview = $svc->previewAuthorizationDecision([]);
        $this->assertSame('request_changes', $preview['decision']);
        $this->assertFalse($preview['merge_eligible']);
        $this->assertFalse($preview['approval_granted']);
        $this->assertFalse($preview['authorizes']);

        // Decision says 'merge' but a validation is missing => still default.
        $partial = $svc->previewAuthorizationDecision([
            'selected_decision' => 'merge',
            'validations' => [
                'selected_decision_is_exactly_merge' => true,
                'tests_pass' => true,
                // ... the other ten are absent => treated as false.
            ],
        ]);
        $this->assertSame('request_changes', $partial['decision']);
        $this->assertFalse($partial['merge_eligible']);
        $this->assertFalse($partial['all_validations_pass']);
        $this->assertNotEmpty($partial['failed_validations']);
        $this->assertFalse($partial['approval_granted']);
    }

    /**
     * Doc "Required Future Validations: selected decision is exactly `merge`" +
     * "Any missing or mismatched item must produce `request_changes` or `abort`,
     * never implicit approval." Merge is eligible ONLY when the decision is
     * exactly `merge` AND all twelve validations pass — yet even then this
     * read-only surface still authorizes nothing.
     */
    public function test_merge_eligible_only_when_decision_merge_and_all_validations_pass(): void
    {
        $svc = $this->service();

        $allPass = [];
        foreach (AtlasCodexMergeAuthorizingActionContractRtService::REQUIRED_FUTURE_VALIDATIONS as $check) {
            $allPass[$check] = true;
        }

        // Exactly 'merge' + all twelve true => eligible, but non-authorizing.
        $merge = $svc->previewAuthorizationDecision([
            'selected_decision' => 'merge',
            'validations' => $allPass,
        ]);
        $this->assertTrue($merge['all_validations_pass']);
        $this->assertSame('merge', $merge['decision']);
        $this->assertTrue($merge['merge_eligible']);
        // Hard invariant: eligible is NOT authorized.
        $this->assertFalse($merge['approval_granted']);
        $this->assertFalse($merge['authorizes']);

        // All validations pass but the decision is request_changes => no merge.
        $hold = $svc->previewAuthorizationDecision([
            'selected_decision' => 'request_changes',
            'validations' => $allPass,
        ]);
        $this->assertSame('request_changes', $hold['decision']);
        $this->assertFalse($hold['merge_eligible']);

        // A present but malformed/unknown decision must abort, never pass.
        $bad = $svc->previewAuthorizationDecision([
            'selected_decision' => 'approve_and_ship',
            'validations' => $allPass,
        ]);
        $this->assertSame('abort', $bad['decision']);
        $this->assertFalse($bad['merge_eligible']);
        $this->assertFalse($bad['approval_granted']);
    }

    /**
     * Doc enumerations: the 17 required future inputs, the 12 required future
     * validations (none proven here) and the 13 future receipt fields are exact.
     */
    public function test_documented_enumerations_are_exact(): void
    {
        $svc = $this->service();

        $this->assertCount(17, $svc->requiredFutureInputs());
        $this->assertContains('external_authorization_signature_value', $svc->requiredFutureInputs());
        $this->assertContains('human_final_merge_confirmation', $svc->requiredFutureInputs());

        $validations = $svc->requiredFutureValidations();
        $this->assertCount(12, $validations);
        // This read-only surface proves NONE of the future validations.
        foreach ($validations as $v) {
            $this->assertFalse($v['proven']);
        }
        $this->assertSame('selected_decision_is_exactly_merge', $validations[0]['check']);

        $this->assertCount(13, $svc->futureReceiptFields());
        $this->assertSame('authorizing_action_id', $svc->futureReceiptFields()[0]);
        $this->assertContains('authorization_timestamp', $svc->futureReceiptFields());
    }

    /**
     * Doc "Execution Boundary" / "The correct chain is" and "Final Post-Signature
     * Runbook ... must sequence, but not execute": the execution chain ends in a
     * SEPARATE controlled merge action (never this surface), and the runbook's
     * final step is the mandated non-execute stop.
     */
    public function test_executor_is_separate_and_runbook_halts_before_signing(): void
    {
        $svc = $this->service();

        $chain = $svc->executionChain();
        $this->assertCount(5, $chain);
        // Stage 1 is this surface (the authorizing action) and does NOT execute.
        $this->assertSame('authorizing_action', $chain[0]['stage']);
        $this->assertTrue($chain[0]['is_this_surface']);
        $this->assertFalse($chain[0]['is_executor']);
        // The final controlled merge action is the executor and is NOT this surface.
        $this->assertSame('controlled_merge_action', $chain[4]['stage']);
        $this->assertTrue($chain[4]['is_executor']);
        $this->assertFalse($chain[4]['is_this_surface']);

        // Runbook: seven steps, only the last terminal, ending in the stop token.
        $steps = $svc->runbookOrderedSteps();
        $this->assertCount(7, $steps);
        $this->assertSame(
            'stop_before_signing_the_receipt_a_separate_surface_signs',
            $steps[6]['step'],
        );
        $this->assertTrue($steps[6]['is_terminal']);
        $this->assertSame('halt', $steps[6]['kind']);
        foreach (array_slice($steps, 0, 6) as $s) {
            $this->assertFalse($s['is_terminal']);
            $this->assertSame('read_only_check', $s['kind']);
        }
        $this->assertFalse($svc->postSignatureRunbook()['signs_receipt']);

        // Composite structural guarantees hold.
        $eval = $svc->evaluate();
        $this->assertTrue($eval['runbook_terminal_is_last']);
        $this->assertTrue($eval['executor_is_separate']);
    }
}
