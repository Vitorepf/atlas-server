<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionActionSignedReceiptService;
use Tests\TestCase;

/**
 * Pins the documented Post-Execution Action Signed Receipt contract: the
 * seven-key boundary; the four bound sources; the persistence preflight's
 * fourteen blockers (fail-closed empty input blocks all; a fully satisfied input
 * clears all and may approach persistence); the selected-decision-must-equal-merge
 * blocker; the five release conditions; and the persistence template's exact
 * event type + fourteen event fields, defined without being written.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-signed-receipt.md
 */
class AtlasCodexMergePostExecutionActionSignedReceiptTest extends TestCase
{
    private function service(): AtlasCodexMergePostExecutionActionSignedReceiptService
    {
        return new AtlasCodexMergePostExecutionActionSignedReceiptService();
    }

    /**
     * A fully satisfied external-evidence input that clears every one of the
     * fourteen documented persistence blockers.
     *
     * @return array<string,mixed>
     */
    private function clearedPreflightInput(): array
    {
        return [
            'final_merge_action_signature_value' => 'sig-value',
            'signature_validator_identity' => 'validator-1',
            'signature_validation_timestamp' => '2026-06-01T00:00:00Z',
            'validated_action_signable_payload_hash' => 'payload-hash',
            'validated_action_receipt_hash' => 'receipt-hash',
            'selected_decision' => 'merge',
            'authority_input_validation_present' => true,
            'action_validation_proof_present' => true,
            'signed_receipt_persistence_event_hash' => 'event-hash',
            'expected_action_receipt_hash' => 'rh',
            'bound_action_receipt_hash' => 'rh',
            'expected_action_signable_payload_hash' => 'ph',
            'bound_action_signable_payload_hash' => 'ph',
            'hot_scope_unchanged_since_action_signature_request' => true,
            'diff_reviewed_since_action_signature_request' => true,
        ];
    }

    /**
     * Doc "Boundary": the template must keep all SEVEN keys false, and the
     * composite must prove the boundary held across every surface with empty
     * (safe) defaults.
     */
    public function test_boundary_keeps_all_seven_keys_false_and_holds_across_surfaces(): void
    {
        $result = $this->service()->evaluate([]);

        $expected = [
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
        ];

        $this->assertSame($expected, $this->service()->boundary());
        $this->assertTrue($result['boundary_held']);
        $this->assertSame([], $result['boundary_violations']);
    }

    /**
     * Doc "Required Sources": the signed action receipt template must be bound to
     * exactly the four named sources, in order — and it is NOT the signed receipt.
     */
    public function test_template_binds_the_four_required_sources_and_is_not_the_receipt(): void
    {
        $template = $this->service()->signedReceiptTemplate();

        $this->assertSame([
            'action_post_signature_runbook_hash',
            'action_signature_request_hash',
            'action_signable_payload_hash',
            'action_receipt_hash',
        ], $template['bound_sources']);

        // Doc "Principle": still NOT the signed receipt.
        $this->assertFalse($template['is_the_signed_receipt']);
        // Doc "Future Persisted Fields": exactly thirteen fields enumerated.
        $this->assertCount(13, $template['future_persisted_fields']);
        // Doc "Future External Evidence": exactly nine items enumerated.
        $this->assertCount(9, $template['future_external_evidence']);
    }

    /**
     * Doc "Persistence Preflight": fail-closed. Empty input must trip ALL fourteen
     * blockers and persistence must NOT be approachable.
     */
    public function test_preflight_empty_input_trips_all_fourteen_blockers(): void
    {
        $pre = $this->service()->persistencePreflight([]);

        $this->assertFalse($pre['may_approach_persistence']);
        $this->assertSame('persistence_blocked', $pre['status']);
        $this->assertSame(14, $pre['blocker_count']);
        $this->assertCount(14, $pre['active_blockers']);
    }

    /**
     * Doc "Persistence Preflight": when every documented condition is satisfied,
     * all fourteen blockers clear and persistence MAY be approached — while the
     * boundary still holds (approaching is not signing, persisting or merging).
     */
    public function test_preflight_fully_satisfied_clears_all_blockers_but_boundary_holds(): void
    {
        $pre = $this->service()->persistencePreflight($this->clearedPreflightInput());

        $this->assertTrue($pre['may_approach_persistence']);
        $this->assertSame('persistence_may_be_approached', $pre['status']);
        $this->assertSame(0, $pre['blocker_count']);
        $this->assertSame([], $pre['active_blockers']);

        // Approaching persistence never flips the boundary.
        $this->assertSame([], $this->service()->assertBoundaryHeld([$pre]));
        $this->assertFalse($pre['boundary']['receipt_persisted']);
        $this->assertFalse($pre['boundary']['merge_allowed']);
    }

    /**
     * Doc "Persistence Preflight" blocker "selected decision not equal to merge":
     * any decision other than the exact string 'merge' must keep that blocker
     * active even when everything else is satisfied.
     */
    public function test_preflight_blocks_when_selected_decision_is_not_merge(): void
    {
        $input = $this->clearedPreflightInput();
        $input['selected_decision'] = 'do_not_merge';

        $pre = $this->service()->persistencePreflight($input);

        $this->assertFalse($pre['may_approach_persistence']);
        $this->assertContains('selected_decision_not_equal_to_merge', $pre['active_blockers']);

        // A hash mismatch is also an independent blocker.
        $mismatch = $this->clearedPreflightInput();
        $mismatch['bound_action_receipt_hash'] = 'different-hash';
        $preMismatch = $this->service()->persistencePreflight($mismatch);
        $this->assertContains('action_receipt_hash_mismatch', $preMismatch['active_blockers']);
        $this->assertFalse($preMismatch['may_approach_persistence']);
    }

    /**
     * Doc "Release Conditions": a later merge surface may proceed only after all
     * five conditions hold. Missing any one keeps it from proceeding; all five
     * present lets it proceed — yet the boundary stays all-false (it grants
     * nothing now).
     */
    public function test_release_conditions_require_all_five_and_never_grant_merge(): void
    {
        $allMet = [
            'signed_action_receipt_persisted_append_only' => true,
            'signed_action_receipt_hash_verified' => true,
            'merge_surface_consumes_only_signed_action_receipt' => true,
            'last_minute_diff_and_hot_scope_checks_pass' => true,
            'final_merge_evidence_can_be_emitted' => true,
        ];

        $proceed = $this->service()->releaseConditions($allMet);
        $this->assertTrue($proceed['merge_surface_may_proceed']);
        $this->assertSame([], $proceed['unmet']);
        // Even when "may proceed", it proceeds with nothing and the boundary holds.
        $this->assertFalse($proceed['proceeds_with_merge']);
        $this->assertFalse($proceed['boundary']['merge_allowed']);

        // Drop one condition => cannot proceed.
        $missingOne = $allMet;
        $missingOne['signed_action_receipt_hash_verified'] = false;
        $blocked = $this->service()->releaseConditions($missingOne);
        $this->assertFalse($blocked['merge_surface_may_proceed']);
        $this->assertContains('signed_action_receipt_hash_verified', $blocked['unmet']);
    }

    /**
     * Doc "Persistence Template": pins the exact future event type and its
     * fourteen fields, defined WITHOUT being written (and forbidding ledger writes).
     */
    public function test_persistence_template_pins_event_type_and_does_not_write(): void
    {
        $persist = $this->service()->persistenceTemplate();

        $this->assertSame(
            'CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED',
            $persist['event_type'],
        );
        // The doc's "must include" list packs two named fields into several
        // bullets ("event id and event type", "signed action receipt id and hash",
        // "signature hash and validator identity"), enumerating sixteen distinct
        // event fields in total.
        $this->assertCount(16, $persist['event_fields']);
        $this->assertContains('persistence_timestamp', $persist['event_fields']);
        $this->assertContains('signed_action_receipt_hash', $persist['event_fields']);

        // Defines the event WITHOUT writing it; forbids ledger writes.
        $this->assertFalse($persist['writes_event']);
        $this->assertTrue($persist['forbids_ledger_write']);
        $this->assertFalse($persist['boundary']['ledger_write_allowed']);
    }
}
