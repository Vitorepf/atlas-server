<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePostExecutionActionPersistenceReceiptsService;
use Tests\TestCase;

/**
 * Pins the documented Post-Execution Action Persistence Receipts contract: the
 * eight-key boundary; the six required source hashes; the future event type +
 * five event fields the draft lists but never writes; the readiness rule (the
 * draft is ready ONLY when the persistence template is ready, and ready stays
 * read-only); and the persistence preflight's ten blockers (fail-closed empty
 * input blocks all; a fully satisfied input clears all and may approach the future
 * persistence — yet the boundary still holds and persistence is not made legal).
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-receipts.md
 */
class AtlasCodexMergePostExecutionActionPersistenceReceiptsTest extends TestCase
{
    private function service(): AtlasCodexMergePostExecutionActionPersistenceReceiptsService
    {
        return new AtlasCodexMergePostExecutionActionPersistenceReceiptsService();
    }

    /**
     * A fully satisfied evidence input that clears every one of the ten documented
     * persistence preflight blockers.
     *
     * @return array<string,mixed>
     */
    private function clearedPreflightInput(): array
    {
        return [
            'persistence_actor_identity' => 'actor-1',
            'persistence_timestamp' => '2026-06-01T00:00:00Z',
            'signed_action_receipt_hash' => 'receipt-hash',
            'append_only_event_hash' => 'event-hash',
            'ledger_sequence_number' => 42,
            'source_hash_match_report' => 'match-report',
            'hot_scope_recheck_report' => 'hot-scope-report',
            'unreviewed_diff_absence_report' => 'no-unreviewed-diff',
            'human_persistence_confirmation' => true,
            'append_only_ledger_write_surface' => true,
        ];
    }

    /**
     * Doc "Boundary": the draft must keep all EIGHT keys false, and the composite
     * must prove the boundary held across every surface with empty (safe) defaults.
     */
    public function test_boundary_keeps_all_eight_keys_false_and_holds_across_surfaces(): void
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
            'receipt_signed' => false,
        ];

        $this->assertSame($expected, $this->service()->boundary());
        $this->assertTrue($result['boundary_held']);
        $this->assertSame([], $result['boundary_violations']);
    }

    /**
     * Doc "Required Source Hashes" + "Future Event": the draft binds exactly the six
     * named source hashes (in order), pins the exact future event type and its five
     * fields, lists eight required-evidence items and eight forbidden authorities —
     * and writes nothing / is not proof of persistence / does not answer "was it
     * persisted?".
     */
    public function test_draft_binds_six_source_hashes_pins_event_and_proves_nothing(): void
    {
        $draft = $this->service()->persistenceReceiptDraft();

        $this->assertSame([
            'source_signed_action_receipt_persistence_template_hash',
            'source_signed_action_receipt_preflight_hash',
            'source_signed_action_receipt_template_hash',
            'source_action_signature_request_hash',
            'source_action_signable_payload_hash',
            'source_action_receipt_hash',
        ], $draft['required_source_hashes']);

        $this->assertSame(
            'CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED',
            $draft['future_event_type'],
        );
        $this->assertCount(5, $draft['future_event_fields']);
        $this->assertContains('ledger_sequence_number', $draft['future_event_fields']);

        // Doc "Required Evidence": exactly eight items. Doc "Forbidden Authority":
        // exactly eight authorities kept forbidden.
        $this->assertCount(8, $draft['required_evidence']);
        $this->assertCount(8, $draft['forbidden_authorities']);

        // Doc frontmatter + "Future Event" + "Human Meaning".
        $this->assertFalse($draft['writes_event']);
        $this->assertFalse($draft['is_proof_of_persistence']);
        $this->assertFalse($draft['answers_was_receipt_persisted']);
    }

    /**
     * Doc "Readiness Rule": the draft is ready ONLY when the signed action receipt
     * persistence template is ready, with the exact documented blocked/ready status
     * strings — and ready still means read-only (not that persistence happened).
     */
    public function test_readiness_flips_only_when_template_ready_and_stays_read_only(): void
    {
        $blocked = $this->service()->readiness([]);
        $this->assertFalse($blocked['draft_ready']);
        $this->assertSame(
            'merge_post_execution_action_signed_receipt_persistence_receipt_draft_blocked',
            $blocked['status'],
        );

        $ready = $this->service()->readiness([
            'signed_action_receipt_persistence_template_ready' => true,
        ]);
        $this->assertTrue($ready['draft_ready']);
        $this->assertSame(
            'merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready',
            $ready['status'],
        );

        // "The ready state still means read-only" and never that persistence happened.
        $this->assertTrue($ready['read_only']);
        $this->assertFalse($ready['means_persistence_happened']);
        $this->assertFalse($ready['boundary']['receipt_persisted']);
    }

    /**
     * Doc "Persistence Preflight": fail-closed. Empty input must trip ALL ten
     * blockers and the future persistence must NOT be approachable.
     */
    public function test_preflight_empty_input_trips_all_ten_blockers(): void
    {
        $pre = $this->service()->persistencePreflight([]);

        $this->assertFalse($pre['may_approach_persistence']);
        $this->assertSame('persistence_preflight_blocked', $pre['status']);
        $this->assertSame(10, $pre['blocker_count']);
        $this->assertCount(10, $pre['active_blockers']);
        $this->assertContains('missing_append_only_ledger_write_surface', $pre['active_blockers']);
    }

    /**
     * Doc "Persistence Preflight": when every documented condition is satisfied, all
     * ten blockers clear and the future persistence MAY be approached — while the
     * boundary still holds and the preflight does NOT make persistence legal by
     * itself.
     */
    public function test_preflight_fully_satisfied_clears_all_but_does_not_make_persistence_legal(): void
    {
        $pre = $this->service()->persistencePreflight($this->clearedPreflightInput());

        $this->assertTrue($pre['may_approach_persistence']);
        $this->assertSame('persistence_preflight_ready', $pre['status']);
        $this->assertSame(0, $pre['blocker_count']);
        $this->assertSame([], $pre['active_blockers']);

        // Approaching never makes persistence legal and never flips the boundary.
        $this->assertFalse($pre['makes_persistence_legal']);
        $this->assertSame([], $this->service()->assertBoundaryHeld([$pre]));
        $this->assertFalse($pre['boundary']['receipt_persisted']);
        $this->assertFalse($pre['boundary']['ledger_write_allowed']);
    }

    /**
     * Doc "Persistence Preflight": each blocker is independent. Dropping a single
     * piece of evidence re-activates exactly its blocker and blocks the approach,
     * even when everything else is satisfied. A human-confirmation flag that is not
     * exactly boolean true is fail-closed.
     */
    public function test_preflight_each_missing_evidence_independently_blocks(): void
    {
        $missingActor = $this->clearedPreflightInput();
        unset($missingActor['persistence_actor_identity']);
        $pre = $this->service()->persistencePreflight($missingActor);
        $this->assertFalse($pre['may_approach_persistence']);
        $this->assertContains('missing_persistence_actor_identity', $pre['active_blockers']);

        // Fail-closed: a truthy-but-not-true confirmation does not clear the blocker.
        $weakConfirm = $this->clearedPreflightInput();
        $weakConfirm['human_persistence_confirmation'] = 'yes';
        $preWeak = $this->service()->persistencePreflight($weakConfirm);
        $this->assertContains('missing_human_persistence_confirmation', $preWeak['active_blockers']);
        $this->assertFalse($preWeak['may_approach_persistence']);
    }
}
