<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthReceiptService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release AUTHORIZATION Receipt (unsigned DRAFT)
 * contract: the four allowed future decisions, the forced default decision
 * (request_external_writer_release_evidence) that the draft can NEVER override
 * to authorize_writer_release, the six described-but-uncollected future
 * signature inputs, the deterministic non-signature receipt hash, the all-false
 * boundary, and the structurally "no" authorized/released answer.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-receipt.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthReceiptTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterReleaseAuthReceiptService
    {
        return new AtlasCodexMergePEAPWriterReleaseAuthReceiptService();
    }

    /**
     * Doc "Decisions": the allowed future decisions are EXACTLY these four, in
     * the documented order — and only these are recognized as allowed.
     */
    public function test_allowed_decisions_are_exactly_the_four_documented(): void
    {
        $svc = $this->service();

        $this->assertSame([
            'authorize_writer_release',
            'request_external_writer_release_evidence',
            'request_changes',
            'abort',
        ], $svc->allowedDecisions());

        $this->assertTrue($svc->isAllowedDecision('abort'));
        $this->assertTrue($svc->isAllowedDecision('authorize_writer_release'));
        // Not in the closed set.
        $this->assertFalse($svc->isAllowedDecision('authorize'));
        $this->assertFalse($svc->isAllowedDecision('merge'));
    }

    /**
     * Doc "Decisions": the default selected decision is
     * request_external_writer_release_evidence, and that default "prevents a
     * missing-evidence receipt from being mistaken for writer release
     * authorization." So even when a caller explicitly asks to
     * authorize_writer_release, the draft must REFUSE and stay on the default,
     * flagging the override.
     */
    public function test_draft_never_selects_authorize_and_forces_the_default(): void
    {
        $svc = $this->service();

        // No request => default.
        $d0 = $svc->selectDecision(null);
        $this->assertSame('request_external_writer_release_evidence', $d0['selected_decision']);
        $this->assertTrue($d0['is_default']);
        $this->assertFalse($d0['requested_overridden']);

        // A caller tries to authorize => OVERRIDDEN back to the safe default.
        $d1 = $svc->selectDecision('authorize_writer_release');
        $this->assertSame('request_external_writer_release_evidence', $d1['selected_decision']);
        $this->assertTrue($d1['requested_was_allowed']); // it IS a valid token...
        $this->assertTrue($d1['requested_overridden']);  // ...but the draft refuses to select it
        $this->assertNotNull($d1['override_reason']);

        // The same forcing shows up in the full draft, and at the composite level.
        $draft = $svc->receiptDraft(['requested_decision' => 'authorize_writer_release']);
        $this->assertSame('request_external_writer_release_evidence', $draft['selected_decision']);
        $this->assertTrue($svc->evaluate(['requested_decision' => 'authorize_writer_release'])['selected_decision_is_default']);
    }

    /**
     * Doc "Future Signature Inputs": a LATER signature request may require
     * exactly these SIX inputs, in order — and this draft "only describes those
     * inputs. It does not collect or sign them" (collected=false for each).
     */
    public function test_lists_exactly_six_uncollected_future_signature_inputs(): void
    {
        $inputs = $this->service()->futureSignatureInputs();

        $this->assertCount(6, $inputs);
        $this->assertSame([
            'receipt_hash',
            'selected_decision',
            'writer_release_authorization_preflight_hash',
            'writer_implementation_patch_hash',
            'human_writer_release_confirmation_hash',
            'principal_integrator_identity',
        ], array_column($inputs, 'input'));

        foreach ($inputs as $input) {
            $this->assertFalse($input['collected']);
        }
    }

    /**
     * Doc body: this is an UNSIGNED draft. The receipt hash must be
     * deterministic (same advisory input => same hash), sensitive (a different
     * advisory decision changes it), 64-char sha256, and is_signature=false.
     */
    public function test_receipt_hash_is_deterministic_and_is_not_a_signature(): void
    {
        $svc = $this->service();

        $a = $svc->receiptDraft(['requested_decision' => 'request_changes']);
        $b = $svc->receiptDraft(['requested_decision' => 'request_changes']);
        $c = $svc->receiptDraft(['requested_decision' => 'abort']);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $this->assertSame(64, strlen($a['receipt_hash']));
        $this->assertNotSame($a['receipt_hash'], $c['receipt_hash']);
        $this->assertFalse($a['is_signature']);
        $this->assertSame('sha256', $a['receipt_hash_algo']);
    }

    /**
     * Doc "Boundary": every result keeps all nine keys false, even when callers
     * pass an authorize request. Drafting a receipt is never signing, approving,
     * persisting or merging.
     */
    public function test_boundary_stays_all_false_even_when_asked_to_authorize(): void
    {
        $draft = $this->service()->receiptDraft(['requested_decision' => 'authorize_writer_release']);

        foreach (AtlasCodexMergePEAPWriterReleaseAuthReceiptService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $draft['boundary']);
            $this->assertFalse($draft['boundary'][$key], "boundary $key must be false");
        }
        $this->assertFalse($draft['signature_valid']);
        $this->assertFalse($draft['receipt_signed']);
        $this->assertFalse($draft['receipt_persisted']);
        $this->assertFalse($draft['accepts_or_validates_signature']);
    }

    /**
     * Doc "Human Meaning": the surface does NOT answer "Has the writer been
     * authorized or released?" with a yes — it "remains blocked until the
     * receipt is signed, validated and consumed by a separate release path."
     * Structural regardless of input.
     */
    public function test_authorization_status_always_answers_no(): void
    {
        $s = $this->service()->authorizationStatus();

        $this->assertSame('has_the_writer_been_authorized_or_released', $s['question']);
        $this->assertFalse($s['authorized']);
        $this->assertFalse($s['released']);
        $this->assertSame(
            'writer_release_remains_blocked_until_the_receipt_is_signed_validated_and_consumed_by_a_separate_release_path',
            $s['reason'],
        );
    }

    /**
     * Composite entrypoint holds the boundary and proves determinism + the
     * forced default, and the boundary check is real: a tampered key is actually
     * caught (non-vacuous assertion).
     */
    public function test_evaluate_holds_boundary_is_deterministic_and_catches_a_flip(): void
    {
        $svc = $this->service();

        $r = $svc->evaluate(['requested_decision' => 'abort']);
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertTrue($r['receipt_hash_deterministic']);
        $this->assertTrue($r['selected_decision_is_default']);

        // Guard: a flipped boundary key must be detected.
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['approval_granted' => true] + $svc->boundary(),
        ];
        $violations = $svc->assertBoundaryHeld([$tampered]);
        $this->assertContains('tampered.approval_granted', $violations);
    }
}
