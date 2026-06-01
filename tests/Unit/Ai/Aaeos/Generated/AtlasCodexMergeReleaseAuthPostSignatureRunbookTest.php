<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseAuthPostSignatureRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release Authorization Post-Signature Runbook
 * boundary, ordered-step sequence, forbidden actions, future-validator
 * obligations and the always-no release answer.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-post-signature-runbook.md
 */
class AtlasCodexMergeReleaseAuthPostSignatureRunbookTest extends TestCase
{
    private function service(): AtlasCodexMergeReleaseAuthPostSignatureRunbookService
    {
        return new AtlasCodexMergeReleaseAuthPostSignatureRunbookService();
    }

    /**
     * Doc "Ordered Steps": the runbook sequences the documented checks in order
     * and the FINAL step is the mandated stop. Every prior step is a read-only
     * check; only the last is terminal. No step is ever an execution.
     */
    public function test_ordered_steps_end_in_mandated_stop_and_none_execute(): void
    {
        $steps = $this->service()->orderedSteps();

        // Exactly the eight documented steps, in order.
        $this->assertCount(8, $steps);
        $this->assertSame([
            'collect_external_writer_release_signature_evidence',
            'verify_request_hash_against_signable_payload',
            'verify_receipt_hash_against_signed_payload',
            'verify_signable_payload_hash_against_signature_request',
            'verify_required_writer_release_authorization_evidence_exists',
            'verify_hot_scope_is_clean',
            'prepare_a_signed_receipt_template_candidate',
            'stop_before_signature_acceptance_writer_creation_or_ledger_write',
        ], array_column($steps, 'step'));

        // Only the last step is terminal; orders are 1..8 ascending.
        foreach ($steps as $i => $step) {
            $this->assertSame($i + 1, $step['order']);
            $this->assertSame($i === 7, $step['is_terminal']);
            $this->assertSame($i === 7 ? 'halt' : 'read_only_check', $step['kind']);
        }
    }

    /**
     * Doc "Boundary": every result keeps all nine keys false, plus the four
     * restated guarantees. The runbook lists, sequences and halts — it never
     * flips a gate.
     */
    public function test_boundary_stays_all_false(): void
    {
        $r = $this->service()->runbook();

        foreach (AtlasCodexMergeReleaseAuthPostSignatureRunbookService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $r['boundary']);
            $this->assertFalse($r['boundary'][$key], "boundary $key must be false");
        }
        $this->assertCount(9, $r['boundary']);
        $this->assertFalse($r['signature_valid']);
        $this->assertFalse($r['receipt_signed']);
        $this->assertFalse($r['receipt_persisted']);
        $this->assertFalse($r['accepts_or_validates_signature']);
        $this->assertTrue($r['halts_before_acceptance']);
    }

    /**
     * Doc "It must not": the runbook publishes the exact eight actions it
     * refuses to take — and accept/validate signatures, merge and dispatch are
     * among them.
     */
    public function test_forbidden_actions_are_the_documented_eight(): void
    {
        $r = $this->service()->runbook();

        $this->assertSame([
            'create_writer_files',
            'write_ledger_events',
            'persist_receipts',
            'accept_or_validate_signatures',
            'record_decisions',
            'approve_code',
            'merge',
            'dispatch_work',
        ], $r['forbidden_actions']);
    }

    /**
     * Doc "Future Validator Checks": the runbook names the eight obligations a
     * LATER validator must prove, and marks every one proven=false here (this
     * runbook proves none of them; no validator is bound).
     */
    public function test_future_validator_checks_listed_but_none_proven(): void
    {
        $r = $this->service()->runbook();

        $this->assertCount(8, $r['future_validator_checks']);
        $this->assertSame([
            'external_signature_value_is_present',
            'validator_identity_is_present',
            'validation_timestamp_is_present',
            'validated_receipt_hash_matches_source',
            'validated_signable_payload_hash_matches_source',
            'selected_decision_is_explicit',
            'hot_scope_is_still_clean',
            'writer_patch_still_matches_contract_hash',
        ], array_column($r['future_validator_checks'], 'check'));

        foreach ($r['future_validator_checks'] as $check) {
            $this->assertFalse($check['proven'], 'runbook proves no validator check');
        }
        $this->assertFalse($r['validator_present']);
    }

    /**
     * Doc "Human Meaning": the surface does NOT answer "Has the signature been
     * accepted or has the writer been released?" with a yes — that remains
     * blocked. Structural: always false with the documented deferral reason.
     */
    public function test_release_question_always_answers_no(): void
    {
        $rel = $this->service()->releaseStatus();

        $this->assertSame('has_the_signature_been_accepted_or_has_the_writer_been_released', $rel['question']);
        $this->assertFalse($rel['signature_accepted']);
        $this->assertFalse($rel['writer_released']);
        $this->assertSame(
            'signature_acceptance_and_writer_release_remain_blocked_until_a_separate_signed_receipt_template_and_release_path_exist',
            $rel['reason'],
        );
    }

    /**
     * Composite entrypoint holds the boundary AND proves the halt is the last
     * step. The boundary check is real: a tampered key is actually caught.
     */
    public function test_evaluate_holds_boundary_terminal_last_and_catches_a_flip(): void
    {
        $svc = $this->service();

        $e = $svc->evaluate();
        $this->assertTrue($e['boundary_held']);
        $this->assertSame([], $e['boundary_violations']);
        $this->assertTrue($e['terminal_step_is_last']);

        // Guard: a flipped boundary key must be detected (proves non-vacuous).
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['merge_allowed' => true] + $svc->boundary(),
        ];
        $violations = $svc->assertBoundaryHeld([$tampered]);
        $this->assertContains('tampered.merge_allowed', $violations);
    }
}
