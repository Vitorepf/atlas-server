<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseSignatureRequestService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release Signature Request boundary, upstream gate
 * and required-evidence rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-signature-request.md
 */
class AtlasCodexMergeReleaseSignatureRequestTest extends TestCase
{
    private function service(): AtlasCodexMergeReleaseSignatureRequestService
    {
        return new AtlasCodexMergeReleaseSignatureRequestService();
    }

    /**
     * Doc "Required Upstream Contract": if the receipt draft is not ready, the
     * surface must return EXACTLY `blocked_before_writer_release_receipt_draft`
     * and publish no signable spec. Empty (safe-default) input is fail-closed.
     */
    public function test_blocked_before_receipt_draft_when_upstream_not_ready(): void
    {
        $r = $this->service()->signatureRequest([]);

        $this->assertSame('blocked_before_writer_release_receipt_draft', $r['status']);
        $this->assertFalse($r['upstream_receipt_draft_ready']);
        $this->assertFalse($r['signable_request_ready']);
        // Blocked => no evidence spec is listed at all.
        $this->assertSame([], $r['required_signature_evidence']);
    }

    /**
     * Fail-closed: a non-strict-true upstream signal (e.g. the string "true" or
     * 1) must NOT unblock the request — only an exact boolean true does.
     */
    public function test_upstream_gate_is_fail_closed_against_non_boolean_true(): void
    {
        foreach (['true', 1, 'yes', [], null] as $loose) {
            $r = $this->service()->signatureRequest(['writer_release_receipt_draft_ready' => $loose]);
            $this->assertSame(
                'blocked_before_writer_release_receipt_draft',
                $r['status'],
                'loose value must stay blocked',
            );
        }
    }

    /**
     * Doc "Required Signature Evidence": once (and only once) the upstream draft
     * is ready, the surface lists the SEVEN evidence fields a later flow must
     * require — in the exact documented order — while still accepting nothing.
     */
    public function test_lists_seven_required_evidence_fields_when_draft_ready(): void
    {
        $r = $this->service()->signatureRequest(['writer_release_receipt_draft_ready' => true]);

        $this->assertSame('writer_release_signature_requested', $r['status']);
        $this->assertTrue($r['signable_request_ready']);
        $this->assertSame([
            'external_writer_release_signature_value',
            'signer_identity',
            'signature_timestamp',
            'signature_algorithm',
            'signature_scope',
            'writer_release_receipt_hash_signed',
            'writer_release_signable_payload_hash_signed',
        ], $r['required_signature_evidence']);
        $this->assertCount(7, $r['required_signature_evidence']);
    }

    /**
     * Doc "Boundary": every result keeps all nine keys false, even when the
     * request is fully ready to be signed by a later flow. Listing the spec is
     * never acceptance.
     */
    public function test_boundary_stays_all_false_even_when_request_ready(): void
    {
        $r = $this->service()->signatureRequest(['writer_release_receipt_draft_ready' => true]);

        foreach (AtlasCodexMergeReleaseSignatureRequestService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $r['boundary']);
            $this->assertFalse($r['boundary'][$key], "boundary $key must be false");
        }
        // The four restated guarantees also stay false.
        $this->assertFalse($r['signature_valid']);
        $this->assertFalse($r['receipt_signed']);
        $this->assertFalse($r['receipt_persisted']);
        $this->assertFalse($r['accepts_or_validates_signature']);
    }

    /**
     * Doc "Human Meaning": the surface does NOT answer "Was the signature
     * accepted or validated?" with a yes — "The answer remains no." This holds
     * structurally regardless of input.
     */
    public function test_signature_acceptance_question_always_answers_no(): void
    {
        $a = $this->service()->signatureAccepted();

        $this->assertSame('was_the_signature_accepted_or_validated', $a['question']);
        $this->assertFalse($a['signature_accepted']);
        $this->assertFalse($a['signature_valid']);
        $this->assertSame(
            'signature_acceptance_and_validation_belong_to_a_later_governed_surface',
            $a['reason'],
        );
    }

    /**
     * Composite entrypoint holds the boundary across both the (ready) request
     * and the (always-no) acceptance answer, and the boundary check is real:
     * a tampered key is actually caught.
     */
    public function test_evaluate_holds_boundary_and_assertion_catches_a_flip(): void
    {
        $svc = $this->service();

        $r = $svc->evaluate(['writer_release_receipt_draft_ready' => true]);
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);

        // Guard: a flipped boundary key must be detected (proves non-vacuous).
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['signature_valid' => true] + $svc->boundary(),
        ];
        $violations = $svc->assertBoundaryHeld([$tampered]);
        $this->assertContains('tampered.signature_valid', $violations);
    }
}
