<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseSignedReceiptService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge Writer Release Signed Receipt template rules:
 * the all-false boundary, the mandated upstream-runbook block status, and the
 * eight future execution preconditions (incl. the inverted no-authority ones).
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-signed-receipt.md
 */
class AtlasCodexMergeReleaseSignedReceiptTest extends TestCase
{
    private function service(): AtlasCodexMergeReleaseSignedReceiptService
    {
        return new AtlasCodexMergeReleaseSignedReceiptService();
    }

    /**
     * A fully-proven precondition input: every documented condition cleared,
     * with the decision exactly authorize_writer_release and the writer carrying
     * NO merge / dispatch authority.
     *
     * @return array<string,mixed>
     */
    private function provenPreconditionInput(): array
    {
        return [
            'signed_receipt_template_ready' => true,
            'external_validated_signature_evidence_present' => true,
            'selected_decision' => AtlasCodexMergeReleaseSignedReceiptService::REQUIRED_DECISION,
            'writer_contract_hash_matches_patch' => true,
            'hot_scope_still_clean' => true,
            'writer_capability_tests_pass' => true,
            'writer_has_merge_authority' => false,
            'writer_has_dispatch_authority' => false,
        ];
    }

    /**
     * Doc "Boundary": all nine keys must stay false on every surface, and the
     * boundary must hold through the full contract() chain.
     */
    public function test_boundary_keeps_all_nine_keys_false_and_holds(): void
    {
        $service = $this->service();

        $boundary = $service->boundary();
        $expected = [
            'execution_allowed', 'writer_file_creation_allowed', 'ledger_write_allowed',
            'dispatch_allowed', 'approval_granted', 'merge_allowed',
            'signature_valid', 'receipt_signed', 'receipt_persisted',
        ];
        $this->assertSame($expected, array_keys($boundary));
        foreach ($boundary as $key => $value) {
            $this->assertFalse($value, "boundary key {$key} must be false");
        }

        // Even with EVERYTHING proven, the boundary must still hold (the template
        // describes a future writer release; it never performs one).
        $result = $service->contract([
            'template' => ['writer_release_post_signature_runbook_ready' => true],
            'future_execution' => $this->provenPreconditionInput(),
        ]);
        $this->assertTrue($result['boundary_held']);
        $this->assertSame([], $result['boundary_violations']);
    }

    /**
     * Doc "Required Upstream Contract": if the runbook is not ready, the surface
     * MUST return blocked_before_writer_release_post_signature_runbook and be
     * not-ready. Safe default (empty input) is exactly this blocked state.
     */
    public function test_blocks_with_exact_status_when_runbook_not_ready(): void
    {
        $service = $this->service();

        $blocked = $service->template([]); // runbook not proven => fail-closed
        $this->assertSame(
            AtlasCodexMergeReleaseSignedReceiptService::STATUS_BLOCKED_UPSTREAM,
            $blocked['status'],
        );
        $this->assertSame('blocked_before_writer_release_post_signature_runbook', $blocked['status']);
        $this->assertTrue($blocked['blocked']);
        $this->assertFalse($blocked['ready']);
        $this->assertFalse($blocked['describes_required_signed_receipt_contents']);

        // Runbook ready => template surface becomes readable, status flips.
        $ready = $service->template(['writer_release_post_signature_runbook_ready' => true]);
        $this->assertFalse($ready['blocked']);
        $this->assertTrue($ready['ready']);
        $this->assertSame(
            AtlasCodexMergeReleaseSignedReceiptService::STATUS_TEMPLATE_READY,
            $ready['status'],
        );
        // ...but it still never claims a release/persistence happened.
        $this->assertFalse($ready['answers_release_or_persistence']);
    }

    /**
     * Doc "Human Meaning": the surface never answers whether release/persistence
     * happened, no matter the input — that boundary key is hard-coded false.
     */
    public function test_never_answers_release_or_persistence(): void
    {
        $service = $this->service();

        foreach ([true, false] as $runbook) {
            $t = $service->template(['writer_release_post_signature_runbook_ready' => $runbook]);
            $this->assertFalse($t['answers_release_or_persistence']);
        }
    }

    /**
     * Doc "Future Execution Preconditions": all eight cleared => unlockable; a
     * single failure (here: missing signature evidence) => not unlockable and it
     * is named in `unmet`.
     */
    public function test_future_preconditions_require_all_eight(): void
    {
        $service = $this->service();

        $allProven = $service->futureExecutionPreconditions($this->provenPreconditionInput());
        $this->assertTrue($allProven['execution_contract_unlockable']);
        $this->assertSame([], $allProven['unmet']);
        $this->assertCount(8, $allProven['met']);

        $missingSig = $this->provenPreconditionInput();
        $missingSig['external_validated_signature_evidence_present'] = false;
        $blocked = $service->futureExecutionPreconditions($missingSig);
        $this->assertFalse($blocked['execution_contract_unlockable']);
        $this->assertContains('external_validated_signature_evidence_present', $blocked['unmet']);
        // Unlockable-or-not, the surface unlocks/releases nothing now.
        $this->assertFalse($blocked['unlocks_execution']);
        $this->assertFalse($blocked['releases_writer']);
    }

    /**
     * Doc "selected decision equals authorize_writer_release": any other decision
     * fails that precondition.
     */
    public function test_decision_must_equal_authorize_writer_release(): void
    {
        $service = $this->service();

        $wrong = $this->provenPreconditionInput();
        $wrong['selected_decision'] = 'authorize_merge'; // not the required value
        $result = $service->futureExecutionPreconditions($wrong);

        $this->assertFalse($result['preconditions']['selected_decision_is_authorize_writer_release']);
        $this->assertFalse($result['execution_contract_unlockable']);
        $this->assertContains('selected_decision_is_authorize_writer_release', $result['unmet']);
    }

    /**
     * Doc "writer has no merge authority" / "writer has no dispatch authority":
     * these are INVERTED — granting the writer either authority fails the
     * precondition. Also fail-closed: absent authority signals default to "writer
     * HAS authority", so the precondition is unmet until explicitly proven false.
     */
    public function test_writer_must_have_no_merge_or_dispatch_authority(): void
    {
        $service = $this->service();

        // Writer granted merge authority => precondition fails.
        $withMerge = $this->provenPreconditionInput();
        $withMerge['writer_has_merge_authority'] = true;
        $r1 = $service->futureExecutionPreconditions($withMerge);
        $this->assertFalse($r1['preconditions']['writer_has_no_merge_authority']);
        $this->assertFalse($r1['execution_contract_unlockable']);

        // Writer granted dispatch authority => precondition fails.
        $withDispatch = $this->provenPreconditionInput();
        $withDispatch['writer_has_dispatch_authority'] = true;
        $r2 = $service->futureExecutionPreconditions($withDispatch);
        $this->assertFalse($r2['preconditions']['writer_has_no_dispatch_authority']);
        $this->assertFalse($r2['execution_contract_unlockable']);

        // Fail-closed: omit the authority signals entirely => still unmet.
        $omitted = $this->provenPreconditionInput();
        unset($omitted['writer_has_merge_authority'], $omitted['writer_has_dispatch_authority']);
        $r3 = $service->futureExecutionPreconditions($omitted);
        $this->assertFalse($r3['preconditions']['writer_has_no_merge_authority']);
        $this->assertFalse($r3['preconditions']['writer_has_no_dispatch_authority']);
    }
}
