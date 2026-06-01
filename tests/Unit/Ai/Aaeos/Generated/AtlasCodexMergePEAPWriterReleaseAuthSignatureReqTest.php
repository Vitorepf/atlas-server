<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release AUTHORIZATION Signature Request contract:
 * the ten-component deterministic signable payload, the five required external
 * signature evidence fields, the all-false boundary, and the structurally
 * "no" signed/authorized answer.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-signature-request.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthSignatureReqTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService
    {
        return new AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService();
    }

    /**
     * Doc "Signable Payload": the request may expose a deterministic payload
     * containing EXACTLY the ten documented components, in the documented order.
     * No more, no fewer.
     */
    public function test_signable_payload_exposes_exactly_the_ten_documented_components(): void
    {
        $p = $this->service()->signablePayload([]);

        $this->assertSame([
            'receipt_hash',
            'receipt_id',
            'selected_decision',
            'writer_release_authorization_preflight_hash',
            'writer_release_authorization_template_hash',
            'writer_implementation_preflight_hash',
            'writer_contract_template_hash',
            'required_external_evidence',
            'required_authorization_checks',
            'blocking_conditions',
        ], array_keys($p['components']));
        $this->assertCount(10, $p['components']);
        $this->assertSame('authorization_signature_request_signable_payload_constructed', $p['status']);
    }

    /**
     * Doc: "The payload hash is an input to a future external signature process.
     * It is not a signature." The hash must be deterministic (same input =>
     * same hash), any component change must change it, and is_signature=false.
     */
    public function test_payload_hash_is_deterministic_and_is_not_a_signature(): void
    {
        $svc = $this->service();

        $a = $svc->signablePayload(['receipt_id' => 'r-1', 'selected_decision' => 'merge_ready']);
        $b = $svc->signablePayload(['receipt_id' => 'r-1', 'selected_decision' => 'merge_ready']);
        $c = $svc->signablePayload(['receipt_id' => 'r-2', 'selected_decision' => 'merge_ready']);

        // Deterministic: identical input => identical 64-char sha256 hex hash.
        $this->assertSame($a['payload_hash'], $b['payload_hash']);
        $this->assertSame(64, strlen($a['payload_hash']));
        // Sensitive: any component change flips the hash.
        $this->assertNotSame($a['payload_hash'], $c['payload_hash']);
        // The hash is explicitly NOT a signature.
        $this->assertFalse($a['is_signature']);
        $this->assertSame('sha256', $a['payload_hash_algo']);
    }

    /**
     * Doc "Required External Signature Evidence": a later post-signature runbook
     * must require exactly these FIVE fields, in order — and this surface marks
     * each as not present and not validated (it collects none of them).
     */
    public function test_lists_exactly_five_required_external_signature_evidence_fields(): void
    {
        $evidence = $this->service()->requiredExternalSignatureEvidence();

        $this->assertCount(5, $evidence);
        $this->assertSame([
            'external_writer_release_signature_value',
            'signature_validator_identity',
            'signature_validation_timestamp',
            'validated_receipt_hash',
            'validated_signable_payload_hash',
        ], array_column($evidence, 'field'));

        foreach ($evidence as $field) {
            $this->assertFalse($field['signature_present']);
            $this->assertFalse($field['validated']);
        }
    }

    /**
     * Doc "Boundary": every result keeps all nine keys false, even when callers
     * pass rich component values. Constructing a signable payload is never
     * signing, approving, persisting or merging.
     */
    public function test_boundary_stays_all_false_even_with_rich_input(): void
    {
        $p = $this->service()->signablePayload([
            'receipt_hash' => 'deadbeef',
            'selected_decision' => 'approved',
            'required_authorization_checks' => ['hot_scope_clean', 'contract_hash_matches'],
            'blocking_conditions' => ['no_external_signature'],
        ]);

        foreach (AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $p['boundary']);
            $this->assertFalse($p['boundary'][$key], "boundary $key must be false");
        }
        $this->assertFalse($p['signature_valid']);
        $this->assertFalse($p['receipt_signed']);
        $this->assertFalse($p['receipt_persisted']);
        $this->assertFalse($p['accepts_or_validates_signature']);
    }

    /**
     * Doc "Human Meaning": the surface does NOT answer "Has the writer release
     * been signed or authorized?" with a yes — it "remains blocked until
     * external signature evidence is validated by a separate post-signature
     * path." Structural regardless of input.
     */
    public function test_authorization_status_always_answers_no(): void
    {
        $s = $this->service()->authorizationStatus();

        $this->assertSame('has_the_writer_release_been_signed_or_authorized', $s['question']);
        $this->assertFalse($s['signed']);
        $this->assertFalse($s['authorized']);
        $this->assertSame(
            'writer_release_remains_blocked_until_external_signature_evidence_is_validated_by_a_separate_post_signature_path',
            $s['reason'],
        );
    }

    /**
     * Composite entrypoint holds the boundary and proves determinism across the
     * payload and authorization status, and the boundary check is real: a
     * tampered key is actually caught (non-vacuous assertion).
     */
    public function test_evaluate_holds_boundary_is_deterministic_and_catches_a_flip(): void
    {
        $svc = $this->service();

        $r = $svc->evaluate(['receipt_id' => 'r-99']);
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertTrue($r['payload_hash_deterministic']);

        // Guard: a flipped boundary key must be detected.
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['merge_allowed' => true] + $svc->boundary(),
        ];
        $violations = $svc->assertBoundaryHeld([$tampered]);
        $this->assertContains('tampered.merge_allowed', $violations);
    }
}
